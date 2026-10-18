<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Job;

use Acms\Plugins\ArchivesToMedia\Services\Domain\ItemStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * エントリー以外（カテゴリー・ユーザー・ブログ・モジュール）に付いたカスタムフィールドも、
 * スキャン → 変換 → ロールバックまで通ることを確かめる。
 */
final class FieldOwnersTest extends A2mTestCase
{
    private const OWNER_ID = 9001;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function owners(): array
    {
        $out = [];
        foreach (['category' => 'cid', 'user' => 'uid', 'blog' => 'bid', 'module' => 'mid'] as $type => $column) {
            foreach (['suffix', 'same'] as $mode) {
                $out[$type . '/' . $mode] = [$type, $column, $mode];
            }
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dump(string $column): array
    {
        return $this->db->all(
            'SELECT * FROM ' . $this->db->t('field') . ' WHERE field_' . $column . ' = ? ORDER BY field_key, field_sort, field_value',
            [self::OWNER_ID]
        );
    }

    #[Test]
    #[DataProvider('owners')]
    public function 所属の種類ごとに_スキャンから変換_ロールバックまで通る(string $type, string $column, string $mode): void
    {
        $this->archives->put('005/p.jpg', 'P');
        $this->archives->put('005/d.pdf', 'PDF');
        $over = ['field_eid' => null, 'field_' . $column => self::OWNER_ID];
        $this->insertImageField('photo', '005/p.jpg', 1, 'alt', $over);
        $this->insertFileField('doc', '005/d.pdf', 'd.pdf', '資料.pdf', 1, $over);
        $this->insertField('@card', 'photo', 1, $over);
        $this->insertField('@card', 'doc', 2, $over);
        // 同じ所属の別の種類（エントリー）のフィールドには触れない
        $this->insertImageField('photo', '005/p.jpg', 1, 'entry');
        $original = $this->dump($column);
        $entryBefore = $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort', [$this->entryId]);

        $jobId = $this->scannedJob(['targets' => ['field_image', 'field_file'], 'owners' => [$type], 'field' => ['mode' => $mode]]);
        $job = $this->jobs()->find($jobId);
        $this->assertNotNull($job);
        $this->assertSame([ItemStatus::Pending->value => 2], $this->items()->countByStatus($jobId), 'この所属の 2 件だけが候補');

        for ($i = 0; $i < 10 && !$this->runner()->runChunk($job)->done; $i++) {
        }

        $this->assertSame([ItemStatus::Converted->value => 2], $this->items()->countByStatus($jobId));
        $dest = $mode === 'same' ? ['photo', 'doc'] : ['photo_media', 'doc_media'];
        foreach ($dest as $fd) {
            $row = $this->db->row(
                'SELECT * FROM ' . $this->db->t('field') . ' WHERE field_key = ? AND field_' . $column . ' = ?',
                [$fd . '@media', self::OWNER_ID]
            );
            $this->assertNotNull($row, $fd . '@media が所属に書かれる');
            $this->assertNull($row['field_eid'], '所属以外の列は NULL のまま');
            $this->assertSame('media', Cast::str($row['field_type']));
        }
        if ($mode === 'suffix') {
            $group = array_map(
                static fn (array $r): string => Cast::str($r['field_value']),
                $this->db->all('SELECT field_value FROM ' . $this->db->t('field') . ' WHERE field_key = \'@card\' AND field_' . $column . ' = ? ORDER BY field_sort', [self::OWNER_ID])
            );
            $this->assertSame(['photo', 'doc', 'photo_media', 'doc_media'], $group, 'フィールドグループへ追記される');
        }
        $this->assertEquals($entryBefore, $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort', [$this->entryId]), 'エントリーのフィールドは変わらない');

        $job = $this->jobs()->find($jobId);
        $this->assertNotNull($job);
        $res = $this->rollbacker()->rollbackChunk($job, false);

        $this->assertSame(JobStatus::RolledBack, $res->status);
        $this->assertEquals($original, $this->dump($column), 'ロールバックで全行が元に戻る');
    }
}
