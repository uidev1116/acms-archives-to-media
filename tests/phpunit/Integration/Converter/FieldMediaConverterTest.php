<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConflictException;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConversionSkipException;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\Plugins\ArchivesToMedia\Tests\Support\Opts;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FieldMediaConverterTest extends A2mTestCase
{
    private function locator(string $fd = 'hero', int $sort = 1, ?int $rev = null): FieldLocator
    {
        return new FieldLocator($fd, $sort, 'eid', $this->entryId, $rev, $this->blogId);
    }

    private function candidate(string $fd = 'hero', TargetType $t = TargetType::FieldImage, int $sort = 1, ?int $rev = null): Candidate
    {
        return new Candidate($t, $this->locator($fd, $sort, $rev), 'entry', $this->entryId, $this->blogId, ['001/a.jpg']);
    }

    /**
     * @return array<string, string>
     */
    private function valuesByKey(string $like = '%'): array
    {
        $out = [];
        foreach ($this->fieldRows($like) as $r) {
            $out[Cast::str($r['field_key']) . '#' . Cast::str($r['field_sort'])] = Cast::str($r['field_value']);
        }
        return $out;
    }

    #[Test]
    public function 別名モードは旧キーに触れず_新しい2行を書く(): void
    {
        $this->insertImageField('hero', '001/a.jpg', 1, 'alt');
        $before = $this->valuesByKey();

        $after = $this->fieldConverter()->apply($this->candidate(), [42], Opts::suffix());
        $now = $this->valuesByKey();

        $this->assertSame('42', $now['hero_media#1']);
        $this->assertSame('42', $now['hero_media@media#1']);
        foreach ($before as $k => $v) {
            $this->assertSame($v, $now[$k], $k . ' は変わらない');
        }
        $types = $this->db->all('SELECT field_key, field_type, field_search, field_blog_id, field_eid FROM ' . $this->db->t('field') . ' WHERE field_key LIKE \'hero\\_media%\' ORDER BY field_key');
        $this->assertSame([null, 'media'], array_map(static fn ($r) => $r['field_type'], $types));
        $this->assertSame('on', $types[0]['field_search']);
        $this->assertSame('off', $types[1]['field_search']);
        $this->assertSame((string) $this->blogId, Cast::str($types[0]['field_blog_id']));
        $this->assertSame((string) $this->entryId, Cast::str($types[1]['field_eid']));
        $rows = $after['rows'];
        $this->assertIsArray($rows);
        $this->assertCount(2, array_filter($rows, static fn ($r): bool => is_array($r) && str_starts_with(Cast::str($r['field_key'] ?? ''), 'hero_media')));
    }

    #[Test]
    public function 同名モードは旧キーを消して_メディア行に置き換える(): void
    {
        $this->insertImageField('hero', '001/a.jpg');
        $this->insertField('other@path', 'keep');
        $opts = JobOptions::fromArray(['field' => ['mode' => 'same']]);

        $this->fieldConverter()->apply($this->candidate(), [42], $opts);
        $now = $this->valuesByKey();

        $this->assertSame('42', $now['hero#1']);
        $this->assertSame('42', $now['hero@media#1']);
        $this->assertArrayNotHasKey('hero@path#1', $now);
        $this->assertArrayNotHasKey('hero@x#1', $now);
        $this->assertSame('keep', $now['other@path#1']);
    }

    #[Test]
    public function 同名モードで旧キーを残す設定なら_pathなどは残る(): void
    {
        $this->insertImageField('hero', '001/a.jpg');
        $opts = JobOptions::fromArray(['field' => ['mode' => 'same', 'removeLegacyKeys' => false]]);
        $this->fieldConverter()->apply($this->candidate(), [42], $opts);
        $now = $this->valuesByKey();
        $this->assertSame('001/a.jpg', $now['hero@path#1']);
        $this->assertSame('42', $now['hero@media#1']);
    }

    #[Test]
    public function カスタム名とfield_sortごとの変換(): void
    {
        $this->insertImageField('hero', '001/a.jpg', 1);
        $this->insertImageField('hero', '001/b.jpg', 2);
        $opts = JobOptions::fromArray(['field' => ['mode' => 'custom', 'custom' => ['hero' => 'visual']]]);

        $this->fieldConverter()->apply($this->candidate('hero', TargetType::FieldImage, 2), [7], $opts);
        $now = $this->valuesByKey('visual%');

        $this->assertSame(['visual#2' => '7', 'visual@media#2' => '7'], $now);
    }

    #[Test]
    public function フィールドグループに書き込み先が追記される(): void
    {
        $this->insertImageField('hero', '001/a.jpg');
        $this->insertField('@news', 'title', 1, ['field_search' => 'off']);
        $this->insertField('@news', 'hero', 2, ['field_search' => 'off']);
        $this->insertField('@news', 'body', 3, ['field_search' => 'off']);
        $this->insertField('@other', 'title', 1);

        $after = $this->fieldConverter()->apply($this->candidate(), [42], Opts::suffix());

        $groups = $this->db->all('SELECT field_value, field_sort FROM ' . $this->db->t('field') . ' WHERE field_eid = ? AND field_key = \'@news\' ORDER BY field_sort', [$this->entryId]);
        $this->assertSame(['title', 'hero', 'body', 'hero_media'], array_map(static fn ($r) => $r['field_value'], $groups));
        $this->assertSame('4', Cast::str($groups[3]['field_sort']));
        $this->assertCount(1, $this->db->all('SELECT 1 FROM ' . $this->db->t('field') . ' WHERE field_key = \'@other\' AND field_eid = ?', [$this->entryId]));
        $this->assertIsArray($after['groupAdded']);
        $this->assertCount(1, $after['groupAdded']);
    }

    #[Test]
    public function グループの項目が副キーで宣言されていても書き込み先が追記される(): void
    {
        // カスタムフィールドメーカーの画像グループは、gallery ではなく gallery@path などで宣言する
        $this->insertImageField('hero', '001/a.jpg');
        $this->insertField('@news', 'title', 1, ['field_search' => 'off']);
        $this->insertField('@news', 'hero@path', 2, ['field_search' => 'off']);
        $this->insertField('@news', 'hero@alt', 3, ['field_search' => 'off']);

        $after = $this->fieldConverter()->apply($this->candidate(), [42], Opts::suffix());

        $groups = $this->db->all('SELECT field_value FROM ' . $this->db->t('field') . ' WHERE field_eid = ? AND field_key = \'@news\' ORDER BY field_sort', [$this->entryId]);
        $this->assertSame(['title', 'hero@path', 'hero@alt', 'hero_media'], array_map(static fn ($r) => $r['field_value'], $groups));
        $this->assertIsArray($after['groupAdded']);
        $this->assertCount(1, $after['groupAdded']);
    }

    #[Test]
    public function 同名モードでもグループへ追記され_ロールバックで戻る(): void
    {
        $this->insertImageField('hero', '001/a.jpg');
        $this->insertField('@news', 'title', 1, ['field_search' => 'off']);
        $this->insertField('@news', 'hero@path', 2, ['field_search' => 'off']);
        $this->insertField('@news', 'hero@alt', 3, ['field_search' => 'off']);
        $sql = 'SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort, field_value';
        $original = $this->db->all($sql, [$this->entryId]);
        $opts = JobOptions::fromArray(['field' => ['mode' => 'same']]);
        $conv = $this->fieldConverter();
        $before = $conv->snapshot($this->locator(), $opts);
        $after = $conv->apply($this->candidate(), [42], $opts);

        $groups = $this->db->all('SELECT field_value FROM ' . $this->db->t('field') . ' WHERE field_eid = ? AND field_key = \'@news\' ORDER BY field_sort', [$this->entryId]);
        $this->assertSame(['title', 'hero@path', 'hero@alt', 'hero'], array_map(static fn ($r) => $r['field_value'], $groups));

        $conv->restore($this->locator(), (array) $before, $after, $opts);

        $this->assertEquals($original, $this->db->all($sql, [$this->entryId]));
    }

    #[Test]
    public function グループ追記をオフにできる(): void
    {
        $this->insertImageField('hero', '001/a.jpg');
        $this->insertField('@news', 'hero', 1);
        $this->fieldConverter()->apply($this->candidate(), [42], JobOptions::fromArray(['field' => ['appendToGroup' => false]]));
        $this->assertCount(1, $this->db->all('SELECT 1 FROM ' . $this->db->t('field') . ' WHERE field_key = \'@news\' AND field_eid = ?', [$this->entryId]));
    }

    #[Test]
    public function ロールバックで全行が完全に元へ戻る_グループ追記も(): void
    {
        $this->insertImageField('hero', '001/a.jpg', 1, 'alt');
        $this->insertField('@news', 'hero', 1);
        $snapshotAll = $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort, field_value', [$this->entryId]);

        $conv = $this->fieldConverter();
        $opts = Opts::suffix();
        $before = $conv->snapshot($this->locator(), $opts);
        $after = $conv->apply($this->candidate(), [42], $opts);
        $conv->restore($this->locator(), (array) $before, $after, $opts);

        $restored = $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort, field_value', [$this->entryId]);
        $this->assertEquals($snapshotAll, $restored);
    }

    #[Test]
    public function 同名モードのロールバックも旧キーを復元する(): void
    {
        $this->insertImageField('hero', '001/a.jpg', 1, 'alt');
        $original = $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort', [$this->entryId]);
        $opts = JobOptions::fromArray(['field' => ['mode' => 'same']]);
        $conv = $this->fieldConverter();
        $before = $conv->snapshot($this->locator(), $opts);
        $after = $conv->apply($this->candidate(), [42], $opts);

        $conv->restore($this->locator(), (array) $before, $after, $opts);

        $this->assertEquals($original, $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort', [$this->entryId]));
    }

    #[Test]
    public function 変換後に編集されていたら戻せない(): void
    {
        $this->insertImageField('hero', '001/a.jpg');
        $conv = $this->fieldConverter();
        $opts = Opts::suffix();
        $before = $conv->snapshot($this->locator(), $opts);
        $after = $conv->apply($this->candidate(), [42], $opts);
        $this->db->exec('UPDATE ' . $this->db->t('field') . ' SET field_value = ? WHERE field_key = ? AND field_eid = ?', ['99', 'hero_media@media', $this->entryId]);

        $this->expectException(ConflictException::class);
        $conv->restore($this->locator(), (array) $before, $after, $opts);
    }

    #[Test]
    public function スナップショットが不正なら戻せない(): void
    {
        $this->expectException(ConflictException::class);
        $this->fieldConverter()->restore($this->locator(), [], [], Opts::suffix());
    }

    #[Test]
    public function 書き込み先が既にあればDEST_CONFLICT(): void
    {
        $this->insertImageField('hero', '001/a.jpg');
        $this->insertField('hero_media', '5');
        try {
            $this->fieldConverter()->apply($this->candidate(), [42], Opts::suffix());
            $this->fail('例外が必要');
        } catch (ConversionSkipException $e) {
            $this->assertSame(ReasonCode::DestConflict, $e->reason);
        }
    }

    #[Test]
    public function 同名モードで既にメディア化済みならDEST_CONFLICT(): void
    {
        $this->insertImageField('hero', '001/a.jpg');
        $this->insertField('hero@media', '5', 1, ['field_type' => 'media']);
        $this->expectException(ConversionSkipException::class);
        $this->fieldConverter()->apply($this->candidate(), [42], JobOptions::fromArray(['field' => ['mode' => 'same']]));
    }

    #[Test]
    public function 元のパスが無い_media_idが無い場合はスキップ(): void
    {
        $this->insertField('hero@path', '');
        foreach ([[42, ReasonCode::StaleSnapshot], [0, ReasonCode::MediaRegisterFailed]] as [$mid, $reason]) {
            try {
                $this->fieldConverter()->apply($this->candidate(), [$mid], Opts::suffix());
                $this->fail('例外が必要');
            } catch (ConversionSkipException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }

    #[Test]
    public function 画像のメタデータはalt_ファイルはダウンロード名を優先する(): void
    {
        $this->insertImageField('hero', '001/a.jpg', 1, '代替');
        $this->insertFileField('doc', '001/d.pdf', 'base.pdf', 'download.pdf');
        $this->insertFileField('doc2', '001/e.pdf', 'base2.pdf', '');
        $opts = JobOptions::fromArray(['media' => ['tags' => ['t']]]);
        $conv = $this->fieldConverter();

        $img = $conv->metas($this->candidate(), $opts)[0];
        $this->assertSame(['代替', true, true], [$img->alt, $img->fillAlt, $img->preferLarge]);

        $file = $conv->metas($this->candidate('doc', TargetType::FieldFile), $opts)[0];
        $this->assertSame(['download.pdf', false], [$file->fileName, $file->preferLarge]);
        $this->assertSame('base2.pdf', $conv->metas($this->candidate('doc2', TargetType::FieldFile), $opts)[0]->fileName);
    }

    #[Test]
    public function リビジョンのフィールドはfield_revの行だけを変換する(): void
    {
        $this->insertImageField('hero', '001/a.jpg', 1);
        $this->insertImageField('hero', '001/a.jpg', 1, '', [], 3);

        $this->fieldConverter()->apply($this->candidate('hero', TargetType::FieldImage, 1, 3), [8], Opts::suffix());

        $this->assertSame([], $this->fieldRows('hero\\_media%'));
        $rev = $this->db->all('SELECT field_key, field_rev_id FROM ' . $this->db->t('field_rev') . ' WHERE field_eid = ? AND field_key LIKE \'hero\\_media%\'', [$this->entryId]);
        $this->assertCount(2, $rev);
        $this->assertSame('3', Cast::str($rev[0]['field_rev_id']));
    }

    #[Test]
    public function ユニット所属のフィールドも変換できる(): void
    {
        $this->insertColumn('cu1', 'custom');
        $over = ['field_eid' => null, 'field_unit_id' => 'cu1'];
        $this->insertImageField('hero', '001/a.jpg', 1, '', $over);
        $loc = new FieldLocator('hero', 1, 'unit_id', 'cu1', null, $this->blogId);
        $cand = new Candidate(TargetType::FieldImage, $loc, 'unit', 'cu1', $this->blogId, ['001/a.jpg']);

        $this->fieldConverter()->apply($cand, [11], Opts::suffix());

        $rows = $this->db->all('SELECT field_key, field_unit_id, field_eid FROM ' . $this->db->t('field') . ' WHERE field_unit_id = ? AND field_key LIKE \'hero\\_media%\'', ['cu1']);
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['field_eid']);
    }

    #[Test]
    public function フィールドが無ければスナップショットはnull_別種のロケーターは拒否(): void
    {
        $this->assertNull($this->fieldConverter()->snapshot($this->locator('ghost'), Opts::suffix()));
        $this->expectException(\InvalidArgumentException::class);
        $this->fieldConverter()->snapshot(new ColumnLocator('x'), Opts::suffix());
    }

    #[Test]
    public function 大文字小文字だけ違うフィールドは別物として扱い_巻き添えにしない(): void
    {
        $this->insertImageField('Photo', '001/upper.jpg', 1, 'upper');
        $this->insertImageField('photo', '001/lower.jpg', 1, 'lower');
        $original = $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort', [$this->entryId]);
        $opts = JobOptions::fromArray(['field' => ['mode' => 'same']]);
        $conv = $this->fieldConverter();
        $loc = $this->locator('photo');
        $before = $conv->snapshot($loc, $opts);

        $after = $conv->apply($this->candidate('photo'), [9], $opts);

        $now = $this->valuesByKey();
        $this->assertSame('001/upper.jpg', $now['Photo@path#1'], '大文字の Photo は変わらない');
        $this->assertSame('upper', $now['Photo@alt#1']);
        $this->assertArrayNotHasKey('photo@path#1', $now);
        $this->assertSame('9', $now['photo#1']);

        $conv->restore($loc, (array) $before, $after, $opts);
        $this->assertEquals($original, $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_eid = ? ORDER BY field_key, field_sort', [$this->entryId]));
    }

    #[Test]
    public function 行ロックはフィールドの行に対して実行できる(): void
    {
        $conv = $this->fieldConverter();
        $this->db->begin();
        $conv->lock($this->locator(), Opts::suffix());
        $conv->lock($this->locator('ghost'), Opts::suffix());
        $this->db->rollBack();
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function カスタムユニット内のフィールドグループにも書き込み先が追記され_ロールバックで完全に戻る(): void
    {
        $this->insertColumn('cu1', 'custom');
        $over = ['field_eid' => null, 'field_unit_id' => 'cu1'];
        $this->insertImageField('hero', '001/a.jpg', 1, 'alt', $over);
        $this->insertField('@gal', 'caption', 1, $over + ['field_search' => 'off']);
        $this->insertField('@gal', 'hero', 2, $over + ['field_search' => 'off']);
        // 別のユニットのグループには触れない
        $this->insertColumn('cu2', 'custom');
        $this->insertField('@gal', 'hero', 1, ['field_eid' => null, 'field_unit_id' => 'cu2', 'field_search' => 'off']);
        $snapshot = fn (): array => $this->db->all('SELECT * FROM ' . $this->db->t('field') . ' WHERE field_unit_id IN (\'cu1\', \'cu2\') ORDER BY field_unit_id, field_key, field_sort, field_value');
        $original = $snapshot();

        $loc = new FieldLocator('hero', 1, 'unit_id', 'cu1', null, $this->blogId);
        $cand = new Candidate(TargetType::FieldImage, $loc, 'unit', 'cu1', $this->blogId, ['001/a.jpg']);
        $conv = $this->fieldConverter();
        $opts = Opts::suffix();
        $before = $conv->snapshot($loc, $opts);
        $after = $conv->apply($cand, [42], $opts);

        $groups = $this->db->all('SELECT field_unit_id, field_value FROM ' . $this->db->t('field') . ' WHERE field_key = \'@gal\' ORDER BY field_unit_id, field_sort');
        $this->assertSame(
            [['cu1', 'caption'], ['cu1', 'hero'], ['cu1', 'hero_media'], ['cu2', 'hero']],
            array_map(static fn (array $r): array => [Cast::str($r['field_unit_id']), Cast::str($r['field_value'])], $groups),
            'cu1 のグループにだけ追記される'
        );

        $conv->restore($loc, (array) $before, $after, $opts);
        $this->assertEquals($original, $snapshot());
    }
}
