<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Scanner;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator;
use Acms\Plugins\ArchivesToMedia\Services\Support\BlogScope;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Services\Support\UnitData;

/**
 * column（オプションで column_rev）を column_id 順のキーセットページングで読む。
 * OFFSET は使わない。
 */
abstract class AbstractUnitScanner implements ScannerInterface
{
    public function __construct(protected readonly Db $db, protected readonly BlogScope $scope)
    {
    }

    /** 変換元ユニット種類の正規表現（例: image / image_2） */
    abstract protected function typeRegex(): string;

    /** SQL の LIKE 用の接頭辞（例: image） */
    abstract protected function typePrefix(): string;

    public function scan(JobOptions $options, int $blogId, ?string $cursor, int $limit): array
    {
        $state = $this->parseCursor($cursor);
        $blogIds = $this->scope->blogIds($blogId, $options->includeChildBlogs);
        $candidates = [];
        $tables = $options->includeRevisions ? ['column', 'column_rev'] : ['column'];

        foreach ($tables as $tableIndex => $table) {
            if ($tableIndex < array_search($state['t'], $tables, true)) {
                continue;
            }
            $lastId = $state['t'] === $table ? $state['id'] : '';
            $lastRev = $state['t'] === $table ? $state['rev'] : 0;

            while (count($candidates) < $limit) {
                $rows = $this->fetch($table, $options, $blogIds, $lastId, $lastRev, max(1, $limit - count($candidates)));
                if ($rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    $lastId = (string) $row['column_id'];
                    $lastRev = (int) ($row['column_rev_id'] ?? 0);
                    $candidate = $this->toCandidate($table, $row);
                    if ($candidate !== null) {
                        $candidates[] = $candidate;
                    }
                }
            }
            if (count($candidates) >= $limit) {
                return ['candidates' => $candidates, 'nextCursor' => $this->encode($table, $lastId, $lastRev)];
            }
        }
        return ['candidates' => $candidates, 'nextCursor' => null];
    }

    /**
     * @param list<int> $blogIds
     * @return list<array<string, mixed>>
     */
    private function fetch(string $table, JobOptions $options, array $blogIds, string $lastId, int $lastRev, int $limit): array
    {
        $statuses = $options->entryStatus;
        if ($statuses === [] || $blogIds === []) {
            return [];
        }
        $params = [];
        $where = 'c.column_blog_id IN (' . $this->db->placeholders($blogIds) . ')';
        array_push($params, ...$blogIds);
        $where .= ' AND e.entry_status IN (' . $this->db->placeholders($statuses) . ')';
        array_push($params, ...$statuses);
        $where .= ' AND (c.column_type = ? OR c.column_type LIKE ?) AND c.column_field_2 <> \'\'';
        array_push($params, $this->typePrefix(), $this->db->escapeLike($this->typePrefix()) . '\\_%');

        if ($table === 'column_rev') {
            $where .= ' AND (BINARY c.column_id > BINARY ? OR (BINARY c.column_id = BINARY ? AND c.column_rev_id > ?))';
            array_push($params, $lastId, $lastId, $lastRev);
            $order = 'BINARY c.column_id, c.column_rev_id';
        } else {
            $where .= ' AND BINARY c.column_id > BINARY ?';
            $params[] = $lastId;
            $order = 'BINARY c.column_id';
        }

        return $this->db->all(
            'SELECT c.* FROM ' . $this->db->t($table) . ' c
             INNER JOIN ' . $this->db->t('entry') . ' e ON e.entry_id = c.column_entry_id
             WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $limit,
            $params
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toCandidate(string $table, array $row): ?Candidate
    {
        $type = (string) $row['column_type'];
        if (preg_match($this->typeRegex(), $type) !== 1) {
            return null;
        }
        $paths = UnitData::explode((string) $row['column_field_2']);
        if (count(array_filter($paths, static fn (string $p): bool => $p !== '')) === 0) {
            return null;
        }
        $rev = $table === 'column_rev' ? (int) $row['column_rev_id'] : null;
        return new Candidate(
            $this->target(),
            new ColumnLocator((string) $row['column_id'], $rev),
            'entry',
            (int) $row['column_entry_id'],
            (int) $row['column_blog_id'],
            $paths,
            [],
            ['unitType' => $type],
        );
    }

    /**
     * @return array{t: string, id: string, rev: int}
     */
    private function parseCursor(?string $cursor): array
    {
        $data = $cursor === null ? null : json_decode($cursor, true);
        if (!is_array($data) || !isset($data['t'], $data['id']) || !in_array($data['t'], ['column', 'column_rev'], true)) {
            return ['t' => 'column', 'id' => '', 'rev' => 0];
        }
        return ['t' => (string) $data['t'], 'id' => (string) $data['id'], 'rev' => (int) ($data['rev'] ?? 0)];
    }

    private function encode(string $table, string $id, int $rev): string
    {
        return (string) json_encode(['t' => $table, 'id' => $id, 'rev' => $rev]);
    }
}
