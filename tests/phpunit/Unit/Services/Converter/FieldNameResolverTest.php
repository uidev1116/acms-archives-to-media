<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Converter\FieldNameResolver;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FieldNameResolverTest extends TestCase
{
    #[Test]
    public function 別名モードは接尾辞を付ける(): void
    {
        $r = new FieldNameResolver();
        $this->assertSame('hero_media', $r->resolve('hero', JobOptions::defaults()));
        $o = JobOptions::fromArray(['field' => ['suffix' => '_m']]);
        $this->assertSame('hero_m', $r->resolve('hero', $o));
    }

    #[Test]
    public function 同名モードはそのまま(): void
    {
        $o = JobOptions::fromArray(['field' => ['mode' => 'same']]);
        $this->assertSame('hero', (new FieldNameResolver())->resolve('hero', $o));
    }

    #[Test]
    public function カスタムモードは指定名_無ければ接尾辞(): void
    {
        $o = JobOptions::fromArray(['field' => ['mode' => 'custom', 'custom' => ['hero' => 'visual']]]);
        $r = new FieldNameResolver();
        $this->assertSame('visual', $r->resolve('hero', $o));
        $this->assertSame('other_media', $r->resolve('other', $o));
    }

    #[Test]
    public function 対象フィールドの絞り込み(): void
    {
        $r = new FieldNameResolver();
        $this->assertTrue($r->isTarget('a', JobOptions::defaults()));
        $o = JobOptions::fromArray(['field' => ['includeKeys' => ['a'], 'excludeKeys' => ['b']]]);
        $this->assertTrue($r->isTarget('a', $o));
        $this->assertFalse($r->isTarget('c', $o));
        $o = JobOptions::fromArray(['field' => ['includeKeys' => ['a', 'b'], 'excludeKeys' => ['b']]]);
        $this->assertFalse($r->isTarget('b', $o));
    }
}
