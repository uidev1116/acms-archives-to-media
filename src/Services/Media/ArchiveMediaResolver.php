<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Media;

use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Repository\PathMapRepository;
use Acms\Services\Media\Exceptions\MediaSourceImportException;
use Acms\Services\Media\Helper;
use Acms\Services\Media\MediaImportPreview;
use Acms\Services\Media\MediaSourceImporter;

/**
 * archives のパスをメディアに解決する（対応表 → コアの MediaSourceImporter / Media\Helper）。
 *
 * 検証（パストラバーサル・サイズ上限・MIME・SHA-256 による重複判定）はコアの経路に乗せる。
 */
class ArchiveMediaResolver
{
    /**
     * @param string $baseDir ARCHIVES_DIR の絶対パス（末尾スラッシュ付き）
     */
    public function __construct(
        private readonly MediaSourceImporter $importer,
        private readonly Helper $media,
        private readonly PathMapRepository $pathMap,
        private readonly SourcePathSelector $selector,
        private readonly BlogOwnershipFixer $ownership,
        private readonly MediaMetaWriter $metaWriter,
        private readonly string $baseDir,
        private readonly int $maxFileSize,
    ) {
    }

    /**
     * 登録せずに、新規登録か再利用かの見込みを返す（ドライラン）。
     *
     * @return 'new'|'reuse'
     * @throws SourceException
     */
    public function preview(string $archivesPath, int $ownerBlogId, bool $preferLarge = true): string
    {
        $normalized = $this->selector->normalize($archivesPath, $preferLarge);
        if ($this->pathMap->find($ownerBlogId, $normalized) !== null) {
            return 'reuse';
        }
        $source = $this->selector->selectImport($normalized, $preferLarge);
        $this->precheck($source);
        return $this->dryRun($source)->isDuplicate() ? 'reuse' : 'new';
    }

    /**
     * @throws SourceException 理由コード付き
     */
    public function resolve(string $archivesPath, int $ownerBlogId, int $jobId, MediaMeta $meta): ResolvedMedia
    {
        $normalized = $this->selector->normalize($archivesPath, $meta->preferLarge);

        $mapped = $this->pathMap->find($ownerBlogId, $normalized);
        if ($mapped !== null) {
            return new ResolvedMedia($mapped, false);
        }

        $source = $this->selector->selectImport($normalized, $meta->preferLarge);
        $this->precheck($source);
        $preview = $this->dryRun($source);

        if ($preview->isDuplicate() && $preview->reusedMediaId() !== null) {
            $mediaId = $preview->reusedMediaId();
            $this->pathMap->save($ownerBlogId, $normalized, $mediaId, null);
            return new ResolvedMedia($mediaId, false);
        }

        $mediaId = $this->register($source, $this->selector->fileName($normalized, $meta->fileName));
        // 所属ブログの補正とメタデータの書き込みを済ませてから、最後に対応表へ記録する。
        // 先に記録すると、補正の途中で失敗したときに、次回は「対応表にあるから」と補正が二度と行われない。
        try {
            $this->ownership->fix($mediaId, $ownerBlogId);
            $this->metaWriter->write($mediaId, $ownerBlogId, $meta);
            $this->pathMap->save($ownerBlogId, $normalized, $mediaId, $jobId);
        } catch (\Throwable $e) {
            // 半端な状態（所属ブログ・alt・タグが未設定）のメディアを残すと、次回は重複として再利用されて補正されない。
            // 登録したメディアは削除して、次の実行でやり直す（削除できなければ孤立メディアが残るだけで、データは壊れない）。
            try {
                $this->media->deleteItem($mediaId);
            } catch (\Throwable) {
                // 削除に失敗しても、元の例外を優先する
            }
            throw $e;
        }

        return new ResolvedMedia($mediaId, true);
    }

    /**
     * コアの検証より前に、理由コードを安定して決められるものだけ先に確かめる。
     *
     * @throws SourceException
     */
    private function precheck(string $relative): void
    {
        $full = $this->baseDir . $relative;
        if (!is_file($full)) {
            throw new SourceException(ReasonCode::SourceNotFound);
        }
        $real = realpath($full);
        $baseReal = realpath($this->baseDir);
        if ($real === false || $baseReal === false || !str_starts_with($real, rtrim($baseReal, '/') . '/')) {
            throw new SourceException(ReasonCode::SourceForbidden);
        }
        $size = filesize($real);
        if ($size === false || $size > $this->maxFileSize) {
            throw new SourceException(ReasonCode::SourceTooLarge);
        }
    }

    /**
     * @throws SourceException
     */
    private function dryRun(string $source): MediaImportPreview
    {
        try {
            return $this->importer->importDryRun($source);
        } catch (MediaSourceImportException $e) {
            throw $this->translate($e);
        }
    }

    /**
     * 重複が無いときのファイル登録。コアの import() と同じく一時ファイル経由で registerLocalFile() に渡すが、
     * メディアのファイル名は large- を除いた名前にしたいので、import() ではなくこちらを使う。
     *
     * @throws SourceException
     */
    private function register(string $source, string $fileName): int
    {
        $ext = pathinfo($source, PATHINFO_EXTENSION);
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/a2m_' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
        if (!@copy($this->baseDir . $source, $tmp)) {
            throw new SourceException(ReasonCode::MediaRegisterFailed, 'ファイルのコピーに失敗しました。');
        }
        try {
            return $this->media->registerLocalFile($tmp, $fileName);
        } catch (\Throwable $e) {
            throw $this->translate($e);
        } finally {
            @unlink($tmp);
        }
    }

    private function translate(\Throwable $e): SourceException
    {
        $message = $e->getMessage();
        $reason = match (true) {
            str_contains($message, 'ファイルが見つかりません') => ReasonCode::SourceNotFound,
            str_contains($message, '許可されていないローカルパス'), str_contains($message, '実体を解決できません') => ReasonCode::SourceForbidden,
            str_contains($message, 'サイズが上限') => ReasonCode::SourceTooLarge,
            str_contains($message, '画像形式は登録できません'), str_contains($message, '許可されていない') => ReasonCode::SourceInvalidMime,
            default => ReasonCode::MediaRegisterFailed,
        };
        return new SourceException($reason, $message, $e);
    }
}
