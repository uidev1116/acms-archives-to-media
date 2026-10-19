<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services;

use Acms\Plugins\ArchivesToMedia\Services\Requirements;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class RequirementsTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function versions(): array
    {
        return [
            '最低バージョン' => ['3.2.38', true],
            'それより新しい' => ['3.2.39', true],
            '次のマイナー' => ['3.3.0', true],
            '1 つ前' => ['3.2.37', false],
            '古い' => ['3.2.29', false],
            'バージョン不明（定数が無い）は止めない' => [null, true],
            '空文字は止めない' => ['', true],
        ];
    }

    #[Test]
    #[DataProvider('versions')]
    public function 最低バージョンを確かめる(mixed $version, bool $expected): void
    {
        $this->assertSame($expected, Requirements::isSupported($version));
    }

    #[Test]
    public function 最低バージョンは_コアがReact_DOMとデザインシステムの部品を公開した3_2_38(): void
    {
        $this->assertSame('3.2.38', Requirements::MIN_ACMS_VERSION);
    }
}
