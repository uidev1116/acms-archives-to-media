<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use PHPUnit\Framework\Attributes\Test;

final class DbTest extends A2mTestCase
{
    #[Test]
    public function 読み取りクエリの失敗は空の結果ではなく例外になる(): void
    {
        $db = new Db();
        $bad = 'SELECT * FROM ' . $db->t('no_such_table_a2m');

        foreach (
            [
                fn () => $db->all($bad),
                fn () => $db->row($bad),
                fn () => $db->one($bad),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('例外が必要');
            } catch (\Throwable $e) {
                $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
            }
        }
    }

    #[Test]
    public function 書き込みクエリの失敗も例外(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Db())->exec('UPDATE ' . DB_PREFIX . 'no_such_table_a2m SET x = 1');
    }

    #[Test]
    public function プレースホルダー付きの読み取りと件数(): void
    {
        $db = new Db();
        $rows = $db->all('SELECT ? + 1 AS n', [1]);
        $this->assertCount(1, $rows);
        $this->assertSame('2', Cast::str($rows[0]['n'] ?? ''));
        $this->assertNull($db->row('SELECT 1 AS a FROM ' . $db->t('blog') . ' WHERE blog_id = ?', [-1]));
        $this->assertNull($db->one('SELECT 1 FROM ' . $db->t('blog') . ' WHERE blog_id = ?', [-1]));
        $this->assertSame('x', $db->one('SELECT ?', ['x']));
        $this->assertSame('a\\_b\\%c\\\\', $db->escapeLike('a_b%c\\'));
        $this->assertSame('?,?,?', $db->placeholders([1, 2, 3]));
    }

    #[Test]
    public function トランザクションはコミットとロールバックができる(): void
    {
        $db = new Db();
        $id = $this->createTestBlog();
        $db->transaction(fn () => $db->exec('UPDATE ' . $db->t('blog') . ' SET blog_name = ? WHERE blog_id = ?', ['changed', $id]));
        $afterCommit = $db->one('SELECT blog_name FROM ' . $db->t('blog') . ' WHERE blog_id = ?', [$id]);
        $this->assertSame('changed', $afterCommit);

        try {
            $db->transaction(function () use ($db, $id): void {
                $db->exec('UPDATE ' . $db->t('blog') . ' SET blog_name = ? WHERE blog_id = ?', ['rolled', $id]);
                throw new \RuntimeException('x');
            });
        } catch (\RuntimeException) {
            // 期待どおり
        }
        $this->assertSame($afterCommit, $db->one('SELECT blog_name FROM ' . $db->t('blog') . ' WHERE blog_id = ?', [$id]));
    }
}
