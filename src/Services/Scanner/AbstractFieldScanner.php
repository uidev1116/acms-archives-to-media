<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Scanner;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Support\BlogScope;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore;

/**
 * {fd}@path を持つ field（オプションで field_rev）の行を、合成キーのキーセットページングで読む。
 *
 * field テーブルには主キーが無いので、キー・所属・field_sort（・リビジョン）を連結した sk を並び順に使う。
 */
abstract class AbstractFieldScanner implements ScannerInterface
{
    private const OWNERS = [
        'entry' => 'eid',
        'category' => 'cid',
        'user' => 'uid',
        'blog' => 'bid',
        'module' => 'mid',
        'unit' => 'unit_id',
    ];

    public function __construct(
        protected readonly Db $db,
        protected readonly BlogScope $scope,
        protected readonly FieldStore $store,
        protected readonly FieldNameResolver $names,
    ) {
    }

    /**
     * 同じ所属・同じ sort の {fd} 系の行から、この走査器の対象か（画像 or ファイル）を判定する。
     *
     * @param list<array<string, mixed>> $rows
     */
    abstract protected function accepts(array $rows, string $fd): bool;

    public function scan(JobOptions $options, int $blogId, ?string $cursor, int $limit): array
    {
        $state = $this->parseCursor($cursor);
        $blogIds = $this->scope->blogIds($blogId, $options->includeChildBlogs);
        $tables = $options->includeRevisions ? ['field', 'field_rev'] : ['field'];
        $startIndex = (int) array_search($state['t'], $tables, true);
        $candidates = [];

        foreach ($tables as $tableIndex => $table) {
            if ($tableIndex < $startIndex) {
                continue;
            }
            $last = $state['t'] === $table ? $state['sk'] : '';
            $batch = max($limit, 50);
            // 対象キーの一覧は最初のチャンクだけ調べ、続きのチャンクはカーソルに入れて使い回す（毎回の DISTINCT を避ける）
            $keys = $state['t'] === $table && $state['keys'] !== null ? $state['keys'] : $this->targetKeys($table, $options, $blogIds);
            while ($keys !== [] && count($candidates) < $limit) {
                $rows = $this->fetch($table, $options, $blogIds, $keys, $last, $batch);
                if ($rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    $last = Cast::str($row['sk']);
                    $candidate = $this->toCandidate($table, $row, $options);
                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                        if (count($candidates) >= $limit) {
                            return ['candidates' => $candidates, 'nextCursor' => (string) json_encode(['t' => $table, 'sk' => $last, 'keys' => $keys])];
                        }
                    }
                }
                if (count($rows) < $batch) {
                    break;
                }
            }
        }
        return ['candidates' => $candidates, 'nextCursor' => null];
    }

    /**
     * 対象ブログに存在する {fd}@path のキーのうち、変換対象のものだけ（先に絞って全行走査を避ける）。
     *
     * @param list<int> $blogIds
     * @return list<string>
     */
    private function targetKeys(string $table, JobOptions $options, array $blogIds): array
    {
        if ($blogIds === []) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT DISTINCT field_key FROM ' . $this->db->t($table)
            . ' WHERE field_key LIKE \'%@path\' AND field_blog_id IN (' . $this->db->placeholders($blogIds) . ')',
            $blogIds
        );
        $keys = [];
        foreach ($rows as $row) {
            $key = Cast::str($row['field_key']);
            $fd = substr($key, 0, -5);
            if ($fd !== '' && JobOptions::isFieldName($fd) && $this->names->isTarget($fd, $options)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * @param list<int> $blogIds
     * @param list<string> $keys
     * @return list<array<string, mixed>>
     */
    private function fetch(string $table, JobOptions $options, array $blogIds, array $keys, string $last, int $limit): array
    {
        if ($blogIds === [] || $options->entryStatus === []) {
            return [];
        }
        $ownerConds = [];
        foreach (self::OWNERS as $name => $col) {
            if (in_array($name, $options->owners, true)) {
                $ownerConds[] = 'field_' . $col . ' IS NOT NULL';
            }
        }
        if ($ownerConds === []) {
            return [];
        }
        $isRev = $table === 'field_rev';
        $sk = 'CONCAT(field_key, CHAR(1), CONCAT_WS(\'/\', IFNULL(field_eid, \'\'), IFNULL(field_cid, \'\'), IFNULL(field_uid, \'\'), IFNULL(field_bid, \'\'), IFNULL(field_mid, \'\'), IFNULL(field_unit_id, \'\')), CHAR(1), LPAD(field_sort, 10, \'0\')'
            . ($isRev ? ', CHAR(1), LPAD(COALESCE(field_rev_id, 0), 10, \'0\')' : '') . ')';

        $params = [];
        $where = 'field_key IN (' . $this->db->placeholders($keys) . ') AND field_value IS NOT NULL AND field_value <> \'\'';
        array_push($params, ...$keys);
        $where .= ' AND field_blog_id IN (' . $this->db->placeholders($blogIds) . ')';
        array_push($params, ...$blogIds);
        $where .= ' AND (' . implode(' OR ', $ownerConds) . ')';

        // エントリーの状態（エントリー所属、ユニット所属）
        $statuses = $options->entryStatus;
        $in = $this->db->placeholders($statuses);
        $where .= ' AND (field_eid IS NULL OR EXISTS (SELECT 1 FROM ' . $this->db->t('entry') . ' e WHERE e.entry_id = field_eid AND e.entry_status IN (' . $in . ')))';
        array_push($params, ...$statuses);
        $unitTable = $isRev ? 'column_rev' : 'column';
        $where .= ' AND (field_unit_id IS NULL OR EXISTS (SELECT 1 FROM ' . $this->db->t($unitTable) . ' c INNER JOIN ' . $this->db->t('entry') . ' e2 ON e2.entry_id = c.column_entry_id'
            . ' WHERE c.column_id = field_unit_id AND e2.entry_status IN (' . $in . ')))';
        array_push($params, ...$statuses);

        $params[] = $last;
        return $this->db->all(
            'SELECT *, ' . $sk . ' AS sk FROM ' . $this->db->t($table) . ' WHERE ' . $where
            . ' HAVING BINARY sk > BINARY ? ORDER BY BINARY sk LIMIT ' . $limit,
            $params
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toCandidate(string $table, array $row, JobOptions $options): ?Candidate
    {
        $key = Cast::str($row['field_key']);
        $fd = substr($key, 0, -5);
        if ($fd === '' || !JobOptions::isFieldName($fd) || !$this->names->isTarget($fd, $options)) {
            return null;
        }
        $ownerColumn = null;
        $ownerId = null;
        foreach (FieldLocator::OWNER_COLUMNS as $col) {
            if (isset($row['field_' . $col]) && $row['field_' . $col] !== '') {
                $ownerColumn = $col;
                $ownerId = $col === 'unit_id' ? Cast::str($row['field_unit_id']) : Cast::int($row['field_' . $col]);
                break;
            }
        }
        if ($ownerColumn === null || $ownerId === null || $ownerId === 0) {
            return null;
        }
        $locator = new FieldLocator(
            $fd,
            Cast::int($row['field_sort']),
            $ownerColumn,
            $ownerId,
            $table === 'field_rev' ? Cast::int($row['field_rev_id']) : null,
            Cast::int($row['field_blog_id']),
        );
        $rows = $this->store->family($locator, $fd);
        if (!$this->accepts($rows, $fd)) {
            return null;
        }

        // 既にメディア化済み（書き込み先の @media に値がある）なら候補にしない
        $dest = $this->names->resolve($fd, $options);
        $destRows = $dest === $fd ? $rows : $this->store->family($locator, $dest);
        $media = $this->store->valueOf($destRows, $dest . '@media');
        if ($media !== null && trim($media) !== '') {
            return null;
        }
        $conflict = $dest !== $fd && $destRows !== [];

        return new Candidate(
            $this->target(),
            $locator,
            $locator->ownerType(),
            $ownerId,
            Cast::int($row['field_blog_id']),
            [Cast::str($row['field_value'])],
            [],
            ['conflict' => $conflict],
        );
    }

    /**
     * @return array{t: string, sk: string, keys: list<string>|null}
     */
    private function parseCursor(?string $cursor): array
    {
        $data = $cursor === null ? null : json_decode($cursor, true);
        if (!is_array($data) || !isset($data['t'], $data['sk']) || !in_array($data['t'], ['field', 'field_rev'], true)) {
            return ['t' => 'field', 'sk' => '', 'keys' => null];
        }
        $keys = null;
        if (isset($data['keys']) && is_array($data['keys'])) {
            $keys = array_values(array_map(Cast::str(...), $data['keys']));
        }
        return ['t' => Cast::str($data['t']), 'sk' => Cast::str($data['sk']), 'keys' => $keys];
    }
}
