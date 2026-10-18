<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services\Support;

use Acms\Plugins\ArchivesToMedia\Services\Converter\ConflictException;
use Acms\Plugins\ArchivesToMedia\Services\Converter\ConversionSkipException;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Job\JobBusyException;
use Acms\Plugins\ArchivesToMedia\Services\Media\SourceException;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntryBusyException;
use Acms\Plugins\ArchivesToMedia\Services\Support\ErrorMessage;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ErrorMessageTest extends TestCase
{
    #[Test]
    public function このプラグインが利用者向けに書いた例外の文言はそのまま返す(): void
    {
        foreach (
            [
                new SourceException(ReasonCode::SourceNotFound, '元ファイルがありません'),
                new ConversionSkipException(ReasonCode::StaleSnapshot, '変わりました'),
                new ConflictException('編集されています'),
                new EntryBusyException('更新中です'),
                new JobBusyException('実行中です'),
            ] as $e
        ) {
            $this->assertSame($e->getMessage(), ErrorMessage::of($e));
        }
    }

    #[Test]
    public function 想定外の例外は詳細を伏せた定型文にする(): void
    {
        $e = new \PDOException("SQLSTATE[42S02]: Base table or view not found: 'acms.secret_table' at /var/www/html/ablogcms/php/x.php");
        $msg = ErrorMessage::of($e);
        $this->assertSame(ErrorMessage::GENERIC, $msg);
        $this->assertStringNotContainsString('secret_table', $msg);
        $this->assertStringNotContainsString('/var/www', $msg);
    }
}
