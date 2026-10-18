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
 * {fd}@path を持つ field（オプションで field_rev）の行を、所属（owner）単位のキーセットページングで読む。
 *
 * field テーブルには主キーが無いので、所属の種類（eid / cid / …）ごとに、所属 ID の昇順で
 * 「@path を持つ所属 ID」を取り、その所属の行をまとめて読む。並べ替えの対象は所属 ID の素のカラムだけで、
 * 全行に対する計算（CONCAT など）や、チャンクごとの全件の並べ替えをしない。
 * 1 つの所属の行は途中で切らないので、1 チャンクの候補数は上限をわずかに超えることがある。
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

    /** 所属 ID を取るときの 1 回の件数 */
    private const OWNER_BATCH = 20;

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
        $blogIds = $this->scope->blogIds($blogId, $options->includeChildBlogs);
        $tables = $options->includeRevisions ? ['field', 'field_rev'] : ['field'];
        $owners = [];
        foreach (self::OWNERS as $name => $col) {
            if (in_array($name, $options->owners, true)) {
                $owners[] = $col;
            }
        }
        if ($blogIds === [] || $options->entryStatus === [] || $owners === []) {
            return ['candidates' => [], 'nextCursor' => null];
        }

        $state = $this->parseCursor($cursor);

        // 位置（テーブル × 所属の種類）を順に並べ、カーソルの位置から 1 回に所属 OWNER_BATCH 件だけ読む。
        // 候補が 1 件も見つからなくても、そこで区切ってカーソルを返す（呼び出し側が時間を確かめながら続きを呼ぶ）。
        // 画像とファイルを振り分けるスキャナの一方は、他方の行を全部見送るので、1 回で歩き切ると時間切れになる。
        $positions = [];
        foreach ($tables as $table) {
            foreach ($owners as $col) {
                $positions[] = [$table, $col];
            }
        }
        $start = 0;
        $last = '';
        $stateIndex = array_search([$state['t'], $state['o']], $positions, true);
        if ($stateIndex !== false) {
            $start = $stateIndex;
            $last = $state['id'];
        }
        [$table, $col] = $positions[$start];
        if ($last === '' && $col !== 'unit_id') {
            $last = '0';
        }

        $candidates = [];
        $ids = $this->ownerIds($table, $col, $options, $blogIds, $last);
        foreach ($ids as $id) {
            $last = $id;
            foreach ($this->rowsOf($table, $col, $id, $blogIds) as $row) {
                $candidate = $this->toCandidate($table, $col, $row, $options);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
            if (count($candidates) >= $limit) {
                return ['candidates' => $candidates, 'nextCursor' => (string) json_encode(['t' => $table, 'o' => $col, 'id' => $last])];
            }
        }
        if (count($ids) >= self::OWNER_BATCH) {
            return ['candidates' => $candidates, 'nextCursor' => (string) json_encode(['t' => $table, 'o' => $col, 'id' => $last])];
        }
        // この位置は読み切った。次の位置があれば、その先頭から
        $next = $positions[$start + 1] ?? null;
        if ($next === null) {
            return ['candidates' => $candidates, 'nextCursor' => null];
        }
        return ['candidates' => $candidates, 'nextCursor' => (string) json_encode(['t' => $next[0], 'o' => $next[1], 'id' => ''])];
    }

    /**
     * @param list<int> $blogIds
     * @return list<string>
     */
    private function ownerIds(string $table, string $col, JobOptions $options, array $blogIds, string $last): array
    {
        $column = 'field_' . $col;
        $params = [];
        $where = $column . ' > ? AND field_key LIKE \'%@path\' AND field_value IS NOT NULL AND field_value <> \'\'';
        $params[] = $last;
        $where .= ' AND field_blog_id IN (' . $this->db->placeholders($blogIds) . ')';
        array_push($params, ...$blogIds);

        // エントリーの状態（エントリー所属、ユニット所属）
        $statuses = $options->entryStatus;
        $in = $this->db->placeholders($statuses);
        if ($col === 'eid') {
            $where .= ' AND EXISTS (SELECT 1 FROM ' . $this->db->t('entry') . ' e WHERE e.entry_id = field_eid AND e.entry_status IN (' . $in . '))';
            array_push($params, ...$statuses);
        } elseif ($col === 'unit_id') {
            $unitTable = $table === 'field_rev' ? 'column_rev' : 'column';
            $where .= ' AND EXISTS (SELECT 1 FROM ' . $this->db->t($unitTable) . ' c INNER JOIN ' . $this->db->t('entry') . ' e2 ON e2.entry_id = c.column_entry_id'
                . ' WHERE c.column_id = field_unit_id AND e2.entry_status IN (' . $in . '))';
            array_push($params, ...$statuses);
        }

        $rows = $this->db->all(
            'SELECT ' . $column . ' AS oid FROM ' . $this->db->t($table) . ' WHERE ' . $where
            . ' GROUP BY ' . $column . ' ORDER BY ' . $column . ' LIMIT ' . self::OWNER_BATCH,
            $params
        );
        return array_map(static fn (array $r): string => Cast::str($r['oid']), $rows);
    }

    /**
     * @param list<int> $blogIds
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $table, string $col, string $ownerId, array $blogIds): array
    {
        $params = [$ownerId];
        $order = $table === 'field_rev' ? 'field_key, field_sort, field_rev_id' : 'field_key, field_sort';
        $rows = $this->db->all(
            'SELECT * FROM ' . $this->db->t($table) . ' WHERE field_' . $col . ' = ? AND field_key LIKE \'%@path\''
            . ' AND field_value IS NOT NULL AND field_value <> \'\' AND field_blog_id IN (' . $this->db->placeholders($blogIds) . ')'
            . ' ORDER BY ' . $order,
            [...$params, ...$blogIds]
        );
        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toCandidate(string $table, string $scanCol, array $row, JobOptions $options): ?Candidate
    {
        $key = Cast::str($row['field_key']);
        // LIKE は大文字小文字を区別しないので、ここで厳密に確かめる
        if (!str_ends_with($key, '@path')) {
            return null;
        }
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
        // 複数の所属が入っている行は、先頭の所属を走査しているときだけ扱う（二重に拾わない）
        if ($ownerColumn === null || $ownerColumn !== $scanCol || $ownerId === null || $ownerId === 0) {
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
     * @return array{t: string, o: string, id: string}
     */
    private function parseCursor(?string $cursor): array
    {
        $data = $cursor === null ? null : json_decode($cursor, true);
        if (!is_array($data) || !isset($data['t'], $data['o'], $data['id']) || !in_array($data['t'], ['field', 'field_rev'], true)) {
            return ['t' => '', 'o' => '', 'id' => ''];
        }
        return ['t' => Cast::str($data['t']), 'o' => Cast::str($data['o']), 'id' => Cast::str($data['id'])];
    }
}
