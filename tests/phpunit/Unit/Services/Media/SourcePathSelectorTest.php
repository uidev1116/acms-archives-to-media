<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services\Media;

use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Media\SourceException;
use Acms\Plugins\ArchivesToMedia\Services\Media\SourcePathSelector;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class SourcePathSelectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/a2m_sel_' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir . '001/202401', 0777, true);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $found = glob($this->dir . '001/202401/*');
        return $found === false ? [] : $found;
    }

    protected function tearDown(): void
    {
        foreach ($this->files() as $f) {
            unlink($f);
        }
        rmdir($this->dir . '001/202401');
        rmdir($this->dir . '001');
        rmdir($this->dir);
        parent::tearDown();
    }

    #[Test]
    public function パスの安全確認とlargeの有無は_コアのFilesystemで行う(): void
    {
        $fs = new \Acms\Plugins\ArchivesToMedia\Tests\Support\RecordingFilesystem();
        $s = new SourcePathSelector($this->dir, $fs);
        $s->normalize('001/202401/a.jpg');
        $s->hasLarge('001/202401/a.jpg');
        $this->assertContains('isSafeRelativePath', $fs->calls);
        $this->assertContains('isFile', $fs->calls);
    }

    #[Test]
    public function 画像はlarge接頭辞を除いた通常サイズのパスにそろえる(): void
    {
        $s = new SourcePathSelector($this->dir);
        $this->assertSame('001/202401/a.jpg', $s->normalize('001/202401/large-a.jpg'));
        $this->assertSame('001/202401/a.jpg', $s->normalize('001/202401/a.jpg'));
        $this->assertSame('a.jpg', $s->normalize('large-a.jpg'));
    }

    #[Test]
    public function ファイルはlarge接頭辞をそのまま残す(): void
    {
        $s = new SourcePathSelector($this->dir);
        $this->assertSame('001/large-report.pdf', $s->normalize('001/large-report.pdf', false));
    }

    #[Test]
    public function large接頭辞だけの名前は除かない(): void
    {
        $s = new SourcePathSelector($this->dir);
        $this->assertSame('001/large-', $s->normalize('001/large-'));
    }

    #[Test]
    #[DataProvider('forbidden')]
    public function archivesの外や不正な値は拒否(string $path): void
    {
        $s = new SourcePathSelector($this->dir);
        try {
            $s->normalize($path);
            $this->fail('例外が必要');
        } catch (SourceException $e) {
            $this->assertSame(ReasonCode::SourceForbidden, $e->reason);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function forbidden(): array
    {
        return [
            '空' => [''],
            '空白のみ' => ['  '],
            '親ディレクトリ' => ['../etc/passwd'],
            '途中の親ディレクトリ' => ['001/../../x.jpg'],
            'カレント' => ['./a.jpg'],
            '絶対パス' => ['/etc/passwd'],
            'URL' => ['http://example.com/a.jpg'],
            'file スキーム' => ['file:/etc/passwd'],
            'バックスラッシュ' => ['001\\a.jpg'],
            'NUL' => ["001/a\0.jpg"],
        ];
    }

    #[Test]
    public function large版が存在すれば優先し_無ければ通常サイズ(): void
    {
        $s = new SourcePathSelector($this->dir);
        touch($this->dir . '001/202401/a.jpg');

        $this->assertFalse($s->hasLarge('001/202401/a.jpg'));
        $this->assertSame('001/202401/a.jpg', $s->selectImport('001/202401/a.jpg', true));

        touch($this->dir . '001/202401/large-a.jpg');
        $this->assertTrue($s->hasLarge('001/202401/a.jpg'));
        $this->assertSame('001/202401/large-a.jpg', $s->selectImport('001/202401/a.jpg', true));
        $this->assertSame('001/202401/a.jpg', $s->selectImport('001/202401/a.jpg', false));
        $this->assertSame('large-a.jpg', $s->largePath('a.jpg'));
    }

    #[Test]
    public function ファイル名はヒント_basenameの順(): void
    {
        $s = new SourcePathSelector($this->dir);
        $this->assertSame('report.pdf', $s->fileName('001/x.pdf', 'report.pdf'));
        $this->assertSame('report.pdf', $s->fileName('001/x.pdf', 'report'));
        $this->assertSame('report', $s->fileName('001/x', 'report'));
        $this->assertSame('x.pdf', $s->fileName('001/x.pdf', ''));
        $this->assertSame('x.pdf', $s->fileName('001/x.pdf', '../evil.pdf'));
        $this->assertSame('x.pdf', $s->fileName('001/x.pdf', 'a\\b.pdf'));
    }
}
