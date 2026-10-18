<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services\Support;

use Acms\Plugins\ArchivesToMedia\Services\Support\EntryBusyException;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntrySerializer;
use Acms\Services\Unit\Exceptions\UnitLockTimeoutException;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EntrySerializerTest extends TestCase
{
    #[Test]
    public function エントリーIDがあれば_ロックの中で実行し結果を返す(): void
    {
        $calls = [];
        $s = new EntrySerializer(static function (int $eid, callable $fn) use (&$calls) {
            $calls[] = $eid;
            return $fn();
        });

        $this->assertSame('ok', $s->run(7, static fn (): string => 'ok'));
        $this->assertSame([7], $calls);
    }

    #[Test]
    public function エントリーに属さない対象は_ロックを取らずに実行する(): void
    {
        $s = new EntrySerializer(static function (): never {
            throw new \LogicException('呼ばれてはいけない');
        });
        $this->assertSame(1, $s->run(null, static fn (): int => 1));
    }

    #[Test]
    public function ロック機構が無い環境では_そのまま実行する(): void
    {
        $this->assertSame(2, (new EntrySerializer())->run(5, static fn (): int => 2));
    }

    #[Test]
    public function ロックを取れなければEntryBusyExceptionにする(): void
    {
        $s = new EntrySerializer(static function (int $eid): never {
            throw new UnitLockTimeoutException($eid);
        });
        $this->expectException(EntryBusyException::class);
        $s->run(3, static fn (): int => 1);
    }

    #[Test]
    public function 中の例外はそのまま伝える(): void
    {
        $s = new EntrySerializer(static fn (int $eid, callable $fn): mixed => $fn());
        $this->expectException(\DomainException::class);
        $s->run(3, static function (): never {
            throw new \DomainException('x');
        });
    }
}
