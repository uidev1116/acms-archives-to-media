<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Media;

use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaMeta;
use Acms\Plugins\ArchivesToMedia\Services\Media\MediaReferenceChecker;
use Acms\Plugins\ArchivesToMedia\Services\Media\SourceException;
use Acms\Plugins\ArchivesToMedia\Services\Repository\PathMapRepository;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use Acms\TestingFramework\Seeder\MediaSeeder;
use PHPUnit\Framework\Attributes\Test;

final class ArchiveMediaResolverTest extends A2mTestCase
{
    private function reasonOf(callable $fn): ?ReasonCode
    {
        try {
            $fn();
        } catch (SourceException $e) {
            return $e->reason;
        }
        return null;
    }

    #[Test]
    public function 新規ファイルはメディア登録され_対応表に記録される(): void
    {
        $this->archives->put('001/a.jpg', 'AAA');
        $res = $this->resolver()->resolve('001/a.jpg', $this->blogId, 5, new MediaMeta());

        $this->assertTrue($res->isNew);
        $this->assertSame(['a.jpg'], $this->media->registered);
        $this->assertSame($res->mediaId, (new PathMapRepository($this->db))->find($this->blogId, '001/a.jpg'));
        $this->assertSame([$res->mediaId], (new PathMapRepository($this->db))->mediaIdsCreatedByJob(5));
    }

    #[Test]
    public function 同じパスの2回目は対応表から再利用し_再登録しない(): void
    {
        $this->archives->put('001/a.jpg', 'AAA');
        $first = $this->resolver()->resolve('001/a.jpg', $this->blogId, 5, new MediaMeta());
        $second = $this->resolver()->resolve('001/a.jpg', $this->blogId, 6, new MediaMeta());

        $this->assertSame($first->mediaId, $second->mediaId);
        $this->assertFalse($second->isNew);
        $this->assertCount(1, $this->media->registered);
    }

    #[Test]
    public function 内容が同じ別パスは既存メディアを再利用する(): void
    {
        $this->archives->put('001/a.jpg', 'SAME');
        $this->archives->put('002/b.jpg', 'SAME');
        $first = $this->resolver()->resolve('001/a.jpg', $this->blogId, 5, new MediaMeta());
        $second = $this->resolver()->resolve('002/b.jpg', $this->blogId, 5, new MediaMeta());

        $this->assertSame($first->mediaId, $second->mediaId);
        $this->assertFalse($second->isNew);
        $this->assertSame($second->mediaId, (new PathMapRepository($this->db))->find($this->blogId, '002/b.jpg'));
        // 再利用は「このジョブが作った」とは記録しない
        $this->assertSame([$first->mediaId], (new PathMapRepository($this->db))->mediaIdsCreatedByJob(5));
    }

    #[Test]
    public function large版があればそれを取り込み_ファイル名はlargeを含まない(): void
    {
        $this->archives->put('001/a.jpg', 'small');
        $this->archives->put('001/large-a.jpg', 'LARGE');
        $this->resolver()->resolve('001/a.jpg', $this->blogId, 1, new MediaMeta());

        $this->assertSame(['a.jpg'], $this->media->registered);
        $hash = $this->db->one('SELECT media_hash FROM ' . $this->db->t('media') . ' ORDER BY media_id DESC');
        $this->assertSame(hash('sha256', 'LARGE'), $hash);
    }

    #[Test]
    public function ファイルはlarge接頭辞を使わず_名前のヒントを使う(): void
    {
        $this->archives->put('001/doc.pdf', 'PDF');
        $this->archives->put('001/large-doc.pdf', 'OTHER');
        $this->resolver()->resolve('001/doc.pdf', $this->blogId, 1, new MediaMeta(fileName: '請求書', preferLarge: false));

        $this->assertSame(['請求書.pdf'], $this->media->registered);
        $this->assertSame(hash('sha256', 'PDF'), $this->db->one('SELECT media_hash FROM ' . $this->db->t('media') . ' ORDER BY media_id DESC'));
    }

    #[Test]
    public function 子ブログのデータなら登録後にメディアの所属ブログを補正する(): void
    {
        $child = $this->createTestBlog();
        $this->archives->put('001/a.jpg', 'AAA');
        $res = $this->resolver()->resolve('001/a.jpg', $child, 1, new MediaMeta());

        $this->assertSame((string) $child, (string) $this->db->one('SELECT media_blog_id FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [$res->mediaId]));
    }

    #[Test]
    public function 新規メディアにだけaltとキャプションとタグを書く(): void
    {
        $this->archives->put('001/a.jpg', 'AAA');
        $meta = new MediaMeta('代替', 'キャプション', true, ['archives-import']);
        $res = $this->resolver()->resolve('001/a.jpg', $this->blogId, 1, $meta);
        $row = $this->db->row('SELECT media_field_1, media_field_3 FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [$res->mediaId]);
        $this->assertSame(['media_field_1' => 'キャプション', 'media_field_3' => '代替'], $row);
        $this->assertSame([[(string) $res->mediaId, 'archives-import']], $this->media->tagCalls);

        // 再利用では書き換えない
        $again = $this->resolver()->resolve('001/a.jpg', $this->blogId, 2, new MediaMeta('別', '別', true, ['x']));
        $this->assertFalse($again->isNew);
        $this->assertSame('代替', $this->db->one('SELECT media_field_3 FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [$again->mediaId]));
        $this->assertCount(1, $this->media->tagCalls);
    }

    #[Test]
    public function fillAltがオフならalt_キャプションは書かない(): void
    {
        $this->archives->put('001/a.jpg', 'AAA');
        $res = $this->resolver()->resolve('001/a.jpg', $this->blogId, 1, new MediaMeta('代替', 'cap', false));
        $this->assertSame('', $this->db->one('SELECT media_field_3 FROM ' . $this->db->t('media') . ' WHERE media_id = ?', [$res->mediaId]));
    }

    #[Test]
    public function 取り込めない入力は理由コード付きで失敗する(): void
    {
        $this->archives->put('001/a.jpg', 'AAA');
        $this->archives->put('big.jpg', str_repeat('x', 2000));
        $outside = rtrim(sys_get_temp_dir(), '/') . '/a2m_outside_' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($outside, 'secret');
        symlink($outside, $this->archives->dir . 'link.jpg');

        $r = $this->resolver(1000);
        $meta = new MediaMeta();
        try {
            $this->assertSame(ReasonCode::SourceNotFound, $this->reasonOf(fn () => $r->resolve('missing.jpg', $this->blogId, 1, $meta)));
            $this->assertSame(ReasonCode::SourceForbidden, $this->reasonOf(fn () => $r->resolve('../x.jpg', $this->blogId, 1, $meta)));
            $this->assertSame(ReasonCode::SourceForbidden, $this->reasonOf(fn () => $r->resolve('link.jpg', $this->blogId, 1, $meta)));
            $this->assertSame(ReasonCode::SourceTooLarge, $this->reasonOf(fn () => $r->resolve('big.jpg', $this->blogId, 1, $meta)));
        } finally {
            unlink($outside);
            unlink($this->archives->dir . 'link.jpg');
        }
        $this->assertSame([], $this->media->registered);
    }

    #[Test]
    public function コアの登録が失敗したらMEDIA_REGISTER_FAILEDかINVALID_MIME(): void
    {
        $this->archives->put('001/a.jpg', 'AAA');
        $this->media->failRegister = true;

        $this->assertSame(ReasonCode::SourceInvalidMime, $this->reasonOf(fn () => $this->resolver()->resolve('001/a.jpg', $this->blogId, 1, new MediaMeta())));
    }

    #[Test]
    public function プレビューは新規_再利用を返し_登録はしない(): void
    {
        $this->archives->put('001/a.jpg', 'AAA');
        $this->archives->put('002/b.jpg', 'AAA');
        $r = $this->resolver();

        $this->assertSame('new', $r->preview('001/a.jpg', $this->blogId));
        $this->assertSame([], $this->media->registered);

        $r->resolve('001/a.jpg', $this->blogId, 1, new MediaMeta());
        $this->assertSame('reuse', $r->preview('001/a.jpg', $this->blogId));
        $this->assertSame('reuse', $r->preview('002/b.jpg', $this->blogId));
        $this->assertSame(ReasonCode::SourceNotFound, $this->reasonOf(fn () => $r->preview('nope.jpg', $this->blogId)));
    }

    #[Test]
    public function メディア参照チェッカーはユニットとフィールドの参照を見つける(): void
    {
        $checker = new MediaReferenceChecker($this->db);
        $a = MediaSeeder::seed($this->blogId);
        $b = MediaSeeder::seed($this->blogId);
        $c = MediaSeeder::seed($this->blogId);
        $d = MediaSeeder::seed($this->blogId);
        $e = MediaSeeder::seed($this->blogId);
        $unused = MediaSeeder::seed($this->blogId);
        $delim = ':acms_unit_delimiter:';

        $this->insertColumn('m1', 'media', ['column_field_1' => (string) $a]);
        $this->insertColumn('m2', 'media_2', ['column_field_1' => $b . $delim . '999']);
        $this->insertColumn('m3', 'media', ['column_field_1' => '998' . $delim . $c]);
        $this->insertColumn('m4', 'media', ['column_field_1' => '77' . $delim . $d . $delim . '88']);
        $this->insertField('x_media@media', (string) $e);

        foreach ([$a, $b, $c, $d, $e] as $id) {
            $this->assertTrue($checker->isReferenced($id), (string) $id);
        }
        $this->assertFalse($checker->isReferenced($unused));
        // 部分一致では参照とみなさない（128 は 12 や 8 ではない）
        $this->insertColumn('m5', 'media', ['column_field_1' => '128']);
        $this->assertFalse($checker->isReferenced(12));
        $this->assertFalse($checker->isReferenced(8));
    }
}
