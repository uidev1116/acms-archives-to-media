<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Media;

use Acms\Plugins\ArchivesToMedia\Services\Media\MediaReferenceChecker;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use Acms\Plugins\ArchivesToMedia\Tests\Support\RecordingDb;
use Acms\TestingFramework\Seeder\MediaSeeder;
use PHPUnit\Framework\Attributes\Test;

final class MediaReferenceCheckerTest extends A2mTestCase
{
    #[Test]
    public function まとめて渡した中から_参照されているものだけを返す(): void
    {
        $checker = new MediaReferenceChecker($this->db);
        [$a, $b, $c, $d, $e, $f, $unused] = array_map(fn () => MediaSeeder::seed($this->blogId), range(1, 7));
        $delim = ':acms_unit_delimiter:';
        $this->insertColumn('m1', 'media', ['column_field_1' => (string) $a]);
        $this->insertColumn('m2', 'media_2', ['column_field_1' => $b . $delim . '999']);
        $this->insertColumn('m3', 'media', ['column_field_1' => '998' . $delim . $c], null, null, 2);
        $this->insertColumn('m4', 'block-editor', ['column_field_1' => '<img data-mid="' . $d . '" src="x.jpg">']);
        $this->insertField('x_media@media', (string) $e);
        $this->insertField('y_media@media', (string) $f, 1, [], 4);
        // 部分一致は参照ではない
        $this->insertColumn('m5', 'media', ['column_field_1' => $unused . '0']);

        $got = $checker->referencedAmong([$a, $b, $c, $d, $e, $f, $unused]);
        sort($got);
        $expected = [$a, $b, $c, $d, $e, $f];
        sort($expected);
        $this->assertSame($expected, $got);
        $this->assertSame([], $checker->referencedAmong([]));
    }

    #[Test]
    public function 多数のメディアを調べても_クエリの数はメディアの数に比例しない(): void
    {
        $db = new RecordingDb();
        $ids = array_map(fn () => MediaSeeder::seed($this->blogId), range(1, 60));
        $this->insertColumn('m1', 'media', ['column_field_1' => (string) $ids[10]]);

        $got = (new MediaReferenceChecker($db))->referencedAmong($ids);

        $this->assertSame([$ids[10]], $got);
        $this->assertLessThanOrEqual(10, count($db->selects), '60 件で ' . count($db->selects) . ' クエリ');
    }
}
