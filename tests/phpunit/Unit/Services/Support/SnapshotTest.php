<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services\Support;

use Acms\Plugins\ArchivesToMedia\Services\Support\Snapshot;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SnapshotTest extends TestCase
{
    #[Test]
    public function 値は文字列とnullにそろえ_キー順に並べる(): void
    {
        $row = Snapshot::normalizeRow(['b' => 2, 'a' => null, 'c' => 1.5, 'd' => ['x']]);
        $this->assertSame(['a' => null, 'b' => '2', 'c' => '1.5', 'd' => '["x"]'], $row);
    }

    #[Test]
    public function 型が違っても同じ値なら等しい(): void
    {
        $a = Snapshot::normalizeRow(['id' => 1, 'v' => 'x']);
        $b = Snapshot::normalizeRow(['v' => 'x', 'id' => '1']);
        $this->assertTrue(Snapshot::equals($a, $b));
    }

    #[Test]
    public function 複数行は順序に依存しない(): void
    {
        $a = Snapshot::normalizeRows([['k' => 'a'], ['k' => 'b']]);
        $b = Snapshot::normalizeRows([['k' => 'b'], ['k' => 'a']]);
        $this->assertTrue(Snapshot::equals($a, $b));
        $this->assertFalse(Snapshot::equals($a, Snapshot::normalizeRows([['k' => 'a']])));
    }
}
