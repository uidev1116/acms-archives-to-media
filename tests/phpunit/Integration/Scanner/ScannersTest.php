<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Scanner;

use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\AbstractFieldScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\FieldFileScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\FieldImageScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\ScannerInterface;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitFileScanner;
use Acms\Plugins\ArchivesToMedia\Services\Scanner\UnitImageScanner;
use Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ScannersTest extends A2mTestCase
{
    private function unitImage(): UnitImageScanner
    {
        return new UnitImageScanner($this->db, $this->blogScope());
    }

    private function fieldImage(): FieldImageScanner
    {
        return new FieldImageScanner($this->db, $this->blogScope(), new FieldStore($this->db), new FieldNameResolver());
    }

    private function fieldFile(): FieldFileScanner
    {
        return new FieldFileScanner($this->db, $this->blogScope(), new FieldStore($this->db), new FieldNameResolver());
    }

    /**
     * 全ページを読み切って候補を集める
     *
     * @return list<Candidate>
     */
    private function drain(ScannerInterface $s, ?JobOptions $o = null, int $limit = 2, ?int $blogId = null): array
    {
        $o ??= JobOptions::defaults();
        $all = [];
        $cursor = null;
        for ($i = 0; $i < 50; $i++) {
            $res = $s->scan($o, $blogId ?? $this->blogId, $cursor, $limit);
            array_push($all, ...$res['candidates']);
            $cursor = $res['nextCursor'];
            if ($cursor === null) {
                return $all;
            }
        }
        $this->fail('終わらない');
    }

    /**
     * @param list<Candidate> $c
     * @return list<string>
     */
    private function columnIds(array $c): array
    {
        return array_map(static fn (Candidate $x) => $x->locator instanceof ColumnLocator ? $x->locator->columnId : '?', $c);
    }

    #[Test]
    public function 画像ユニットだけを列挙し_空パスや別種は除く(): void
    {
        $this->insertColumn('a1', 'image', ['column_field_2' => '001/a.jpg']);
        $this->insertColumn('a2', 'image_2', ['column_field_2' => '001/b.jpg']);
        $this->insertColumn('a3', 'image', ['column_field_2' => '']);
        $this->insertColumn('a4', 'media', ['column_field_1' => '5']);
        $this->insertColumn('a5', 'eximage', ['column_field_2' => 'http://x']);
        $this->insertColumn('a6', 'file', ['column_field_2' => '001/c.pdf']);
        $this->insertColumn('a7', 'imagex', ['column_field_2' => 'z']);
        $this->insertColumn('a8', 'image', ['column_field_2' => ':acms_unit_delimiter:']);

        $got = $this->drain($this->unitImage());

        $this->assertSame(['a1', 'a2'], $this->columnIds($got));
        $this->assertSame('entry', $got[0]->ownerType);
        $this->assertSame($this->entryId, $got[0]->ownerId);
        $this->assertSame('image_2', $got[1]->context['unitType']);
        $this->assertSame(['001/a.jpg'], $got[0]->sourcePaths);
    }

    #[Test]
    public function ページングは重複も欠落も出さない(): void
    {
        $ids = [];
        for ($i = 1; $i <= 7; $i++) {
            $id = sprintf('u%02d', $i);
            $ids[] = $id;
            $this->insertColumn($id, 'image', ['column_field_2' => "001/{$i}.jpg"]);
        }
        $this->assertSame($ids, $this->columnIds($this->drain($this->unitImage(), null, 3)));
        $this->assertSame($ids, $this->columnIds($this->drain($this->unitImage(), null, 100)));
        $this->assertSame($ids, $this->columnIds($this->drain($this->unitImage(), null, 7)));
    }

    #[Test]
    public function 多言語ユニットは言語ごとのパスを持つ(): void
    {
        $this->insertColumn('ml', 'image', ['column_field_2' => '001/ja.jpg:acms_unit_delimiter:001/en.jpg']);
        $got = $this->drain($this->unitImage());
        $this->assertSame(['001/ja.jpg', '001/en.jpg'], $got[0]->sourcePaths);
    }

    #[Test]
    public function エントリーの状態とブログで絞り込む(): void
    {
        $closed = $this->createTestEntry($this->blogId, null, ['entry_status' => 'close']);
        $trash = $this->createTestEntry($this->blogId, null, ['entry_status' => 'trash']);
        $otherBlog = $this->createTestBlog();
        $otherEntry = $this->createTestEntry($otherBlog, null);
        $this->insertColumn('open1', 'image', ['column_field_2' => 'a.jpg']);
        $this->insertColumn('close1', 'image', ['column_field_2' => 'a.jpg'], $closed);
        $this->insertColumn('trash1', 'image', ['column_field_2' => 'a.jpg'], $trash);
        $this->insertColumn('other1', 'image', ['column_field_2' => 'a.jpg'], $otherEntry, $otherBlog);

        $this->assertSame(['close1', 'open1'], $this->sorted($this->columnIds($this->drain($this->unitImage()))));
        $only = JobOptions::fromArray(['entryStatus' => ['open']]);
        $this->assertSame(['open1'], $this->columnIds($this->drain($this->unitImage(), $only)));
        $withTrash = JobOptions::fromArray(['entryStatus' => ['open', 'close', 'draft', 'trash']]);
        $this->assertCount(3, $this->drain($this->unitImage(), $withTrash));
        $this->assertSame([], $this->drain($this->unitImage(), JobOptions::fromArray(['entryStatus' => []])));
        $this->assertSame(['other1'], $this->columnIds($this->drain($this->unitImage(), null, 2, $otherBlog)));
    }

    /**
     * @param list<string> $a
     * @return list<string>
     */
    private function sorted(array $a): array
    {
        sort($a);
        return $a;
    }

    #[Test]
    public function 子ブログは設定したときだけ含める(): void
    {
        $parent = $this->createTestBlog(['blog_left' => 1, 'blog_right' => 4]);
        $child = $this->createTestBlog(['blog_parent' => $parent, 'blog_left' => 2, 'blog_right' => 3]);
        $parentEntry = $this->createTestEntry($parent, null);
        $childEntry = $this->createTestEntry($child, null);
        $this->insertColumn('p1', 'image', ['column_field_2' => 'a.jpg'], $parentEntry, $parent);
        $this->insertColumn('c1', 'image', ['column_field_2' => 'b.jpg'], $childEntry, $child);

        $this->assertSame(['p1'], $this->columnIds($this->drain($this->unitImage(), null, 5, $parent)));
        $with = JobOptions::fromArray(['includeChildBlogs' => true]);
        $this->assertSame(['c1', 'p1'], $this->sorted($this->columnIds($this->drain($this->unitImage(), $with, 5, $parent))));
        $this->assertSame([$this->blogId], $this->blogScope()->blogIds($this->blogId, true));
        $this->assertSame([987654], $this->blogScope()->blogIds(987654, true));
    }

    #[Test]
    public function ファイルユニットを列挙する(): void
    {
        $this->insertColumn('f1', 'file', ['column_field_2' => '001/a.pdf']);
        $this->insertColumn('f2', 'file_3', ['column_field_2' => '001/b.pdf']);
        $this->insertColumn('i1', 'image', ['column_field_2' => '001/c.jpg']);
        $got = $this->drain(new UnitFileScanner($this->db, $this->blogScope()));
        $this->assertSame(['f1', 'f2'], $this->columnIds($got));
        $this->assertSame(TargetType::UnitFile, $got[0]->target);
    }

    #[Test]
    public function リビジョンはオプションがオンのときだけ走査する(): void
    {
        $this->insertColumn('u1', 'image', ['column_field_2' => 'a.jpg']);
        $this->insertColumn('u1', 'image', ['column_field_2' => 'a.jpg'], null, null, 2);
        $this->insertColumn('u1', 'image', ['column_field_2' => 'a.jpg'], null, null, 3);
        $this->insertColumn('u9', 'image', ['column_field_2' => 'z.jpg'], null, null, 1);

        $this->assertCount(1, $this->drain($this->unitImage()));
        $on = JobOptions::fromArray(['includeRevisions' => true]);
        foreach ([1, 2, 10] as $limit) {
            $got = $this->drain($this->unitImage(), $on, $limit);
            $revs = array_map(static fn (Candidate $c) => $c->locator instanceof ColumnLocator ? ($c->locator->revId ?? 0) : -1, $got);
            $this->assertSame([0, 2, 3, 1], $revs, 'limit=' . $limit);
        }
    }

    #[Test]
    public function 壊れたカーソルは先頭から読み直す(): void
    {
        $this->insertColumn('u1', 'image', ['column_field_2' => 'a.jpg']);
        $res = $this->unitImage()->scan(JobOptions::defaults(), $this->blogId, 'garbage', 5);
        $this->assertCount(1, $res['candidates']);
        $res = $this->fieldImage()->scan(JobOptions::defaults(), $this->blogId, '{"t":"x"}', 5);
        $this->assertSame([], $res['candidates']);
    }

    #[Test]
    public function 画像フィールドとファイルフィールドを見分ける(): void
    {
        $this->insertImageField('hero', '001/a.jpg');
        $this->insertFileField('doc', '001/d.pdf', 'd.pdf');
        $this->insertField('noimage@path', '');

        $img = $this->drain($this->fieldImage());
        $file = $this->drain($this->fieldFile());

        $this->assertCount(1, $img);
        $this->assertCount(1, $file);
        $this->assertSame(TargetType::FieldImage, $img[0]->target);
        $loc = $img[0]->locator;
        $this->assertInstanceOf(FieldLocator::class, $loc);
        $this->assertSame(['hero', 1, 'eid', $this->entryId], [$loc->key, $loc->sort, $loc->ownerColumn, $loc->ownerId]);
        $this->assertSame(['001/a.jpg'], $img[0]->sourcePaths);
        $this->assertSame('doc', $file[0]->locator instanceof FieldLocator ? $file[0]->locator->key : '');
        $this->assertFalse($img[0]->context['conflict']);
    }

    #[Test]
    public function 同じフィールドのsortごとに別の候補になる(): void
    {
        $this->insertImageField('gallery', '001/1.jpg', 1);
        $this->insertImageField('gallery', '001/2.jpg', 2);
        $this->insertImageField('gallery', '001/3.jpg', 3);
        foreach ([1, 2, 5] as $limit) {
            $got = $this->drain($this->fieldImage(), null, $limit);
            $this->assertSame(['001/1.jpg', '001/2.jpg', '001/3.jpg'], array_map(static fn (Candidate $c) => $c->sourcePaths[0], $got), 'limit=' . $limit);
        }
    }

    #[Test]
    public function 変換済みは候補にならず_書き込み先が既にあれば衝突として返す(): void
    {
        $this->insertImageField('done', '001/a.jpg');
        $this->insertField('done_media@media', '5', 1, ['field_type' => 'media']);
        $this->insertImageField('conflict', '001/b.jpg');
        $this->insertField('conflict_media', 'x');
        $this->insertImageField('fresh', '001/c.jpg');

        $got = $this->drain($this->fieldImage());
        $keys = array_map(static fn (Candidate $c) => $c->locator instanceof FieldLocator ? $c->locator->key : '', $got);

        $this->assertSame(['conflict', 'fresh'], $keys);
        $this->assertTrue($got[0]->context['conflict']);
        $this->assertFalse($got[1]->context['conflict']);
    }

    #[Test]
    public function 同名モードでは_media行が既にあるものを除く(): void
    {
        $this->insertImageField('done', '001/a.jpg');
        $this->insertField('done@media', '5', 1, ['field_type' => 'media']);
        $this->insertImageField('todo', '001/b.jpg');
        $same = JobOptions::fromArray(['field' => ['mode' => 'same']]);
        $keys = array_map(static fn (Candidate $c) => $c->locator instanceof FieldLocator ? $c->locator->key : '', $this->drain($this->fieldImage(), $same));
        $this->assertSame(['todo'], $keys);
    }

    #[Test]
    public function includeKeysとexcludeKeysで絞る(): void
    {
        $this->insertImageField('a', '1.jpg');
        $this->insertImageField('b', '2.jpg');
        $this->insertImageField('c', '3.jpg');
        $keys = fn (JobOptions $o) => array_map(static fn (Candidate $c) => $c->locator instanceof FieldLocator ? $c->locator->key : '', $this->drain($this->fieldImage(), $o));

        $this->assertSame(['a', 'c'], $keys(JobOptions::fromArray(['field' => ['excludeKeys' => ['b']]])));
        $this->assertSame(['b'], $keys(JobOptions::fromArray(['field' => ['includeKeys' => ['b']]])));
    }

    #[Test]
    public function 所属の種類で絞る_カテゴリー_ユーザー_ブログ_モジュール_ユニット(): void
    {
        $cid = $this->createTestCategory($this->blogId);
        $uid = $this->createTestUser(['user_blog_id' => $this->blogId]);
        $this->insertColumn('cu1', 'custom');
        $owners = [
            'category' => ['field_eid' => null, 'field_cid' => $cid],
            'user' => ['field_eid' => null, 'field_uid' => $uid],
            'blog' => ['field_eid' => null, 'field_bid' => $this->blogId],
            'module' => ['field_eid' => null, 'field_mid' => 4242],
            'unit' => ['field_eid' => null, 'field_unit_id' => 'cu1'],
        ];
        $this->insertImageField('entryfd', '0.jpg');
        foreach ($owners as $name => $over) {
            $this->insertImageField($name . 'fd', $name . '.jpg', 1, '', $over);
        }

        $all = $this->drain($this->fieldImage(), null, 3);
        $types = array_map(static fn (Candidate $c) => $c->ownerType, $all);
        sort($types);
        $this->assertSame(['blog', 'category', 'entry', 'module', 'unit', 'user'], $types);

        $onlyEntry = $this->drain($this->fieldImage(), JobOptions::fromArray(['owners' => ['entry']]));
        $this->assertCount(1, $onlyEntry);
        $this->assertSame([], $this->drain($this->fieldImage(), JobOptions::fromArray(['owners' => []])));
        $unit = $this->drain($this->fieldImage(), JobOptions::fromArray(['owners' => ['unit']]));
        $this->assertSame('cu1', $unit[0]->ownerId);
    }

    #[Test]
    public function エントリーが対象外の状態なら所属フィールドも除く(): void
    {
        $trash = $this->createTestEntry($this->blogId, null, ['entry_status' => 'trash']);
        $this->insertImageField('hero', '1.jpg', 1, '', ['field_eid' => $trash]);
        $this->insertColumn('cu1', 'custom', [], $trash);
        $this->insertImageField('uhero', '2.jpg', 1, '', ['field_eid' => null, 'field_unit_id' => 'cu1']);
        $this->assertSame([], $this->drain($this->fieldImage()));
    }

    #[Test]
    public function リビジョンのフィールドも走査できる(): void
    {
        $this->insertImageField('hero', '1.jpg', 1);
        $this->insertImageField('hero', '1.jpg', 1, '', [], 5);
        $this->insertImageField('hero', '2.jpg', 2, '', [], 5);
        $on = JobOptions::fromArray(['includeRevisions' => true]);
        foreach ([1, 3, 10] as $limit) {
            $got = $this->drain($this->fieldImage(), $on, $limit);
            $revs = array_map(static fn (Candidate $c) => $c->locator instanceof FieldLocator ? ($c->locator->revId ?? 0) : -1, $got);
            $this->assertSame([0, 5, 5], $revs, 'limit=' . $limit);
        }
        $this->assertCount(1, $this->drain($this->fieldImage()));
    }

    #[Test]
    public function 大文字小文字だけ違うキーも取りこぼさない(): void
    {
        $this->insertImageField('Photo', '1.jpg');
        $this->insertImageField('photo', '2.jpg');
        $got = $this->drain($this->fieldImage(), null, 1);
        $this->assertCount(2, $got);
    }

    #[Test]
    public function スキャナーの対象種類(): void
    {
        $this->assertSame(TargetType::UnitImage, $this->unitImage()->target());
        $this->assertSame(TargetType::FieldImage, $this->fieldImage()->target());
        $this->assertSame(TargetType::FieldFile, $this->fieldFile()->target());
        $this->assertInstanceOf(AbstractFieldScanner::class, $this->fieldFile());
    }
}
