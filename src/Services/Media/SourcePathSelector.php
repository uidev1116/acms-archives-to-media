<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Media;

use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;

/**
 * 取り込むファイルの選択（設計書 §4.1）。large- 版が残っていればそれを優先する。
 *
 * DB の値は改ざんされている前提で、取り込み前に archives の外を指すパスを弾く。
 * 実ファイルの最終的な検証（realpath 後の再検証、MIME、サイズ）はコアの MediaSourceImporter が行う。
 */
class SourcePathSelector
{
    public const LARGE_PREFIX = 'large-';

    /**
     * @param string $baseDir ARCHIVES_DIR の絶対パス（末尾スラッシュ付き）
     */
    public function __construct(private readonly string $baseDir)
    {
    }

    /**
     * DB の値を archives 相対パスに正規化する。
     *
     * 画像は通常サイズのパスにそろえる（$stripLarge: basename の large- を除く）。ファイルはそのまま。
     *
     * @throws SourceException SOURCE_FORBIDDEN
     */
    public function normalize(string $path, bool $stripLarge = true): string
    {
        $path = trim($path);
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_contains($path, '://')
            || $path[0] === '/'
            || str_starts_with($path, 'file:')
            || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1
        ) {
            throw new SourceException(ReasonCode::SourceForbidden);
        }
        $dir = dirname($path);
        $name = basename($path);
        if ($stripLarge && str_starts_with($name, self::LARGE_PREFIX) && strlen($name) > strlen(self::LARGE_PREFIX)) {
            $name = substr($name, strlen(self::LARGE_PREFIX));
        }
        return ($dir === '.' ? '' : $dir . '/') . $name;
    }

    /**
     * large- 版のパス（存在確認はしない）
     */
    public function largePath(string $normalized): string
    {
        $dir = dirname($normalized);
        return ($dir === '.' ? '' : $dir . '/') . self::LARGE_PREFIX . basename($normalized);
    }

    public function hasLarge(string $normalized): bool
    {
        return is_file($this->baseDir . $this->largePath($normalized));
    }

    /**
     * 実際に取り込むファイル（large- 優先）。画像だけが対象。ファイルは常に元のパス。
     */
    public function selectImport(string $normalized, bool $preferLarge): string
    {
        if ($preferLarge && $this->hasLarge($normalized)) {
            return $this->largePath($normalized);
        }
        return $normalized;
    }

    /**
     * メディアに付けるファイル名。ヒントがあればそれ、無ければパスの basename（large- は除く）。
     */
    public function fileName(string $normalized, string $hint = ''): string
    {
        $hint = trim($hint);
        if ($hint !== '' && !str_contains($hint, '/') && !str_contains($hint, '\\') && !str_contains($hint, "\0")) {
            // 拡張子が無いヒントは元ファイルの拡張子を補う
            if (pathinfo($hint, PATHINFO_EXTENSION) === '') {
                $ext = pathinfo($normalized, PATHINFO_EXTENSION);
                return $ext === '' ? $hint : $hint . '.' . $ext;
            }
            return $hint;
        }
        return basename($normalized);
    }
}
