<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConflictException;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConversionSkipException;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use PHPUnit\Framework\Attributes\Test;

final class UnitConvertersTest extends A2mTestCase
{
    private const D = ':acms_unit_delimiter:';

    /**
     * @param list<string> $paths
     */
    private function imageCandidate(string $id, array $paths, ?int $rev = null): Candidate
    {
        return new Candidate(TargetType::UnitImage, new ColumnLocator($id, $rev), 'entry', $this->entryId, $this->blogId, $paths);
    }

    #[Test]
    public function 画像ユニットは設計書のマッピング表どおりに変換される(): void
    {
        $this->archives->put('001/a.jpg');
        $this->insertColumn('u1', 'image', [
            'column_field_1' => 'キャプション',
            'column_field_2' => '001/a.jpg',
            'column_field_3' => 'https://example.com/',
            'column_field_4' => '代替テキスト',
            'column_field_5' => '50',
            'column_field_6' => 'EXIF',
            'column_size' => '640',
            'column_align' => 'center',
            'column_sort' => '7',
        ]);
        $c = $this->unitImageConverter();
        $cand = $this->imageCandidate('u1', ['001/a.jpg']);
        $opts = JobOptions::fromArray(['unitTypeMap' => ['image' => 'media_2']]);

        $after = $c->apply($cand, [42], $opts);
        $row = $this->columnRow('u1');

        $this->assertSame('media_2', $row['column_type']);
        $this->assertSame('42', $row['column_field_1']);
        $this->assertSame('キャプション', $row['column_field_2']);
        $this->assertSame('代替テキスト', $row['column_field_3']);
        $this->assertSame('no', $row['column_field_4']);
        $this->assertSame('no', $row['column_field_5']);
        $this->assertSame('50', $row['column_field_6']);
        $this->assertSame('https://example.com/', $row['column_field_7']);
        $this->assertSame('', $row['column_field_8']);
        // レイアウトは変えない
        $this->assertSame(['640', 'center', '7', 'u1'], [$row['column_size'], $row['column_align'], Cast::str($row['column_sort']), $row['column_id']]);
        $this->assertSame($row['column_field_1'], $this->child($after, 'row')['column_field_1']);
    }

    #[Test]
    public function 拡大表示はautoならリンクが空でlarge版があるときだけyes(): void
    {
        $this->archives->put('001/a.jpg');
        $this->archives->put('001/large-a.jpg');
        $this->archives->put('002/b.jpg');
        $this->insertColumn('withLarge', 'image', ['column_field_2' => '001/a.jpg']);
        $this->insertColumn('withLink', 'image', ['column_field_2' => '001/a.jpg', 'column_field_3' => '/x']);
        $this->insertColumn('noLarge', 'image', ['column_field_2' => '002/b.jpg']);
        $c = $this->unitImageConverter();
        $opts = JobOptions::defaults();

        $c->apply($this->imageCandidate('withLarge', ['001/a.jpg']), [1], $opts);
        $c->apply($this->imageCandidate('withLink', ['001/a.jpg']), [1], $opts);
        $c->apply($this->imageCandidate('noLarge', ['002/b.jpg']), [2], $opts);

        $this->assertSame('yes', $this->columnRow('withLarge')['column_field_4']);
        $this->assertSame('no', $this->columnRow('withLink')['column_field_4']);
        $this->assertSame('no', $this->columnRow('noLarge')['column_field_4']);
    }

    #[Test]
    public function 拡大表示は固定値にもできる(): void
    {
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg']);
        $this->insertColumn('u2', 'image', ['column_field_2' => '001/a.jpg']);
        $c = $this->unitImageConverter();
        $c->apply($this->imageCandidate('u1', ['001/a.jpg']), [1], JobOptions::fromArray(['media' => ['enlarged' => 'yes']]));
        $c->apply($this->imageCandidate('u2', ['001/a.jpg']), [1], JobOptions::fromArray(['media' => ['enlarged' => 'no']]));
        $this->assertSame('yes', $this->columnRow('u1')['column_field_4']);
        $this->assertSame('no', $this->columnRow('u2')['column_field_4']);
    }

    #[Test]
    public function 多言語ユニットは同じ区切りで書き戻す(): void
    {
        $d = self::D;
        $this->archives->put('001/ja.jpg');
        $this->archives->put('001/large-ja.jpg');
        $this->insertColumn('ml', 'image', [
            'column_field_1' => '日本語' . $d . 'English',
            'column_field_2' => '001/ja.jpg' . $d . '001/en.jpg',
            'column_field_3' => $d . '/link',
            'column_field_4' => 'あ' . $d . 'a',
        ]);

        $this->unitImageConverter()->apply($this->imageCandidate('ml', ['001/ja.jpg', '001/en.jpg']), [10, 11], JobOptions::defaults());
        $row = $this->columnRow('ml');

        $this->assertSame("10{$d}11", $row['column_field_1']);
        $this->assertSame("日本語{$d}English", $row['column_field_2']);
        $this->assertSame("あ{$d}a", $row['column_field_3']);
        $this->assertSame("yes{$d}no", $row['column_field_4']);
        $this->assertSame("no{$d}no", $row['column_field_5']);
        $this->assertSame($d . '/link', $row['column_field_7']);
    }

    #[Test]
    public function 空のパスの言語は空のまま残る(): void
    {
        $d = self::D;
        $this->insertColumn('ml', 'image', ['column_field_2' => '001/ja.jpg' . $d]);
        $this->unitImageConverter()->apply($this->imageCandidate('ml', ['001/ja.jpg', '']), [10, 0], JobOptions::defaults());
        $row = $this->columnRow('ml');
        $this->assertSame("10{$d}", $row['column_field_1']);
        $this->assertSame("no{$d}", $row['column_field_5']);
    }

    #[Test]
    public function ロールバックで元の行と完全に一致する(): void
    {
        $this->insertColumn('u1', 'image', ['column_field_1' => 'c', 'column_field_2' => '001/a.jpg', 'column_field_4' => 'alt', 'column_field_6' => 'exif', 'column_field_7' => 'junk']);
        $conv = $this->unitImageConverter();
        $opts = JobOptions::defaults();
        $loc = new ColumnLocator('u1');
        $before = $conv->snapshot($loc, $opts);
        $this->assertNotNull($before);

        $after = $conv->apply($this->imageCandidate('u1', ['001/a.jpg']), [3], $opts);
        $this->assertNotEquals($before, $conv->snapshot($loc, $opts));

        $conv->restore($loc, $before, $after, $opts);
        $this->assertEquals($before, $conv->snapshot($loc, $opts));
        $this->assertSame('image', $this->columnRow('u1')['column_type']);
    }

    #[Test]
    public function 変換後に編集されていたら戻せない(): void
    {
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg']);
        $conv = $this->unitImageConverter();
        $opts = JobOptions::defaults();
        $loc = new ColumnLocator('u1');
        $before = $conv->snapshot($loc, $opts);
        $after = $conv->apply($this->imageCandidate('u1', ['001/a.jpg']), [3], $opts);
        $this->db->exec('UPDATE ' . $this->db->t('column') . ' SET column_field_2 = ? WHERE column_id = ?', ['edited', 'u1']);

        $this->expectException(ConflictException::class);
        $conv->restore($loc, (array) $before, $after, $opts);
    }

    #[Test]
    public function 行が消えていたら戻せず_スナップショットはnull(): void
    {
        $conv = $this->unitImageConverter();
        $loc = new ColumnLocator('ghost');
        $this->assertNull($conv->snapshot($loc, JobOptions::defaults()));
        $this->expectException(ConflictException::class);
        $conv->restore($loc, ['row' => []], ['row' => []], JobOptions::defaults());
    }

    #[Test]
    public function スナップショットが不正なら戻せない(): void
    {
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg']);
        $this->expectException(ConflictException::class);
        $this->unitImageConverter()->restore(new ColumnLocator('u1'), [], [], JobOptions::defaults());
    }

    #[Test]
    public function 存在しないユニットの変換はスキップ扱い(): void
    {
        $this->expectException(ConversionSkipException::class);
        $this->unitImageConverter()->apply($this->imageCandidate('ghost', ['a.jpg']), [1], JobOptions::defaults());
    }

    #[Test]
    public function 画像ユニットのメタデータ_altとキャプションを言語ごとに取り出す(): void
    {
        $d = self::D;
        $this->insertColumn('u1', 'image', ['column_field_1' => 'c1' . $d . 'c2', 'column_field_2' => 'a' . $d . 'b', 'column_field_4' => 'alt1']);
        $metas = $this->unitImageConverter()->metas($this->imageCandidate('u1', ['a', 'b']), JobOptions::fromArray(['media' => ['tags' => ['t']]]));
        $this->assertCount(2, $metas);
        $this->assertSame(['alt1', 'c1', true, ['t']], [$metas[0]->alt, $metas[0]->caption, $metas[0]->fillAlt, $metas[0]->tags]);
        $this->assertSame(['', 'c2'], [$metas[1]->alt, $metas[1]->caption]);
    }

    #[Test]
    public function ファイルユニットはキャプションを移し_アイコン表示をyesにする(): void
    {
        $this->insertColumn('f1', 'file', ['column_field_1' => '資料', 'column_field_2' => '001/doc.pdf', 'column_field_3' => 'x', 'column_size' => '']);
        $conv = $this->unitFileConverter();
        $cand = new Candidate(TargetType::UnitFile, new ColumnLocator('f1'), 'entry', $this->entryId, $this->blogId, ['001/doc.pdf']);
        $loc = new ColumnLocator('f1');
        $before = $conv->snapshot($loc, JobOptions::defaults());

        $metas = $conv->metas($cand, JobOptions::defaults());
        $this->assertSame('資料', $metas[0]->caption);
        $this->assertFalse($metas[0]->preferLarge);

        $after = $conv->apply($cand, [9], JobOptions::defaults());
        $row = $this->columnRow('f1');
        $this->assertSame(['media', '9', '資料', '', 'no', 'yes'], [
            $row['column_type'], $row['column_field_1'], $row['column_field_2'], $row['column_field_3'], $row['column_field_4'], $row['column_field_5'],
        ]);

        $conv->restore($loc, (array) $before, $after, JobOptions::defaults());
        $this->assertEquals($before, $conv->snapshot($loc, JobOptions::defaults()));
    }

    #[Test]
    public function リビジョンのユニットはcolumn_revの行だけを変換する(): void
    {
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg']);
        $this->insertColumn('u1', 'image', ['column_field_2' => '001/a.jpg'], null, null, 4);
        $conv = $this->unitImageConverter();
        $conv->apply($this->imageCandidate('u1', ['001/a.jpg'], 4), [3], JobOptions::defaults());

        $this->assertSame('media', $this->columnRow('u1', 4)['column_type']);
        $this->assertSame('image', $this->columnRow('u1')['column_type']);
    }

    #[Test]
    public function 別の種類のロケーターは受け付けない(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->unitImageConverter()->snapshot(new \Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator('k', 1, 'eid', 1, null, 1), JobOptions::defaults());
    }

    #[Test]
    public function supportsは担当する対象だけ(): void
    {
        $this->assertTrue($this->unitImageConverter()->supports(TargetType::UnitImage));
        $this->assertFalse($this->unitImageConverter()->supports(TargetType::UnitFile));
        $this->assertTrue($this->unitFileConverter()->supports(TargetType::UnitFile));
        $this->assertTrue($this->fieldConverter()->supports(TargetType::FieldFile));
        $this->assertFalse($this->fieldConverter()->supports(TargetType::UnitFile));
    }
}
