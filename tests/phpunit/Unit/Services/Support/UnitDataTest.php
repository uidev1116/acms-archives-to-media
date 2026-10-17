<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services\Support;

use Acms\Plugins\ArchivesToMedia\Services\Support\UnitData;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class UnitDataTest extends TestCase
{
    #[Test]
    public function 区切りで分割_結合できる(): void
    {
        $this->assertSame(['a', 'b'], UnitData::explode('a:acms_unit_delimiter:b'));
        $this->assertSame('a:acms_unit_delimiter:b', UnitData::implode(['a', 'b']));
        $this->assertSame([''], UnitData::explode(''));
    }

    #[Test]
    public function 区切りだけになる場合は空文字(): void
    {
        $this->assertSame('', UnitData::implode(['', '']));
        $this->assertSame('', UnitData::implode(['']));
    }

    #[Test]
    public function 値に含まれる区切りは無害化する(): void
    {
        $this->assertSame('x:acms-unit-delimiter:y', UnitData::implode(['x:acms_unit_delimiter:y']));
    }

    #[Test]
    public function 個数をそろえる(): void
    {
        $this->assertSame(['a', ''], UnitData::pad(['a'], 2));
        $this->assertSame(['a'], UnitData::pad(['a', 'b'], 1));
    }
}
