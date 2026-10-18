<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Support;

use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;

/**
 * テスト用のオプション。既定の書き込み先は同名だが、別名モードを前提にするテストが多いので、明示して作る。
 */
final class Opts
{
    /**
     * @param array<string, mixed> $field field の上書き
     * @param array<string, mixed> $rest field 以外のオプション
     */
    public static function suffix(array $field = [], array $rest = []): JobOptions
    {
        return JobOptions::fromArray($rest + ['field' => $field + ['mode' => 'suffix']]);
    }
}
