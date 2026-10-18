<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Media;

use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Repository\PathMapRepository;
use Acms\Plugins\ArchivesToMedia\Services\Support\AuditLog;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;
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
     * コアのメッセージ（例外文）から理由コードを判定するための対応表。上から順に最初に一致したものを使う。
     * コアのメッセージが変わると静かに MEDIA_REGISTER_FAILED へ落ちるので、テストで実在を確認している。
     *
     * @var list<array{0: string, 1: class-string, 2: ReasonCode}>
     */
    public const CORE_MESSAGES = [
        ['ファイルが見つかりません', MediaSourceImporter::class, ReasonCode::SourceNotFound],
        ['許可されていないローカルパス', MediaSourceImporter::class, ReasonCode::SourceForbidden],
        ['実体を解決できません', MediaSourceImporter::class, ReasonCode::SourceForbidden],
        ['サイズが上限', MediaSourceImporter::class, ReasonCode::SourceTooLarge],
        ['画像形式は登録できません', Helper::class, ReasonCode::SourceInvalidMime],
        ['許可されていないファイル形式', Helper::class, ReasonCode::SourceInvalidMime],
    ];

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
        private readonly ?Db $db = null,
        private readonly int $lockTimeout = 10,
    ) {
    }

    /**
     * 登録せずに、新規登録か再利用かの見込みを返す（ドライラン）。
     *
     * ファイルの存在・場所・サイズの確認は必ず行うが、新規か再利用かの判定にはファイル全体のハッシュ計算が要るので、
     * $hash が false のときは行わず 'unknown'（実行時に判定する）を返す。
     *
     * @return 'new'|'reuse'|'unknown'
     * @throws SourceException
     */
    public function preview(string $archivesPath, int $ownerBlogId, bool $preferLarge = true, bool $hash = true): string
    {
        $normalized = $this->selector->normalize($archivesPath, $preferLarge);
        if ($this->pathMap->find($ownerBlogId, $normalized) !== null) {
            return 'reuse';
        }
        $source = $this->selector->selectImport($normalized, $preferLarge);
        $this->precheck($source);
        if (!$hash) {
            return 'unknown';
        }
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

        $register = function () use ($normalized, $ownerBlogId, $jobId, $meta): ResolvedMedia {
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
        };
        if ($this->db === null) {
            return $register();
        }
        // 判定（重複の確認）から登録までの間に、別のブログで動く同じ処理が同じ内容を登録すると、
        // コアは既存のメディアを返すだけなのに、こちらは新規作成したと誤解してしまう。判定から登録までを直列化する。
        $locked = $this->db->withAdvisoryLock('media-register', $this->lockTimeout, $register);
        if ($locked === null) {
            throw new SourceException(ReasonCode::MediaRegisterFailed, '別の処理がメディアを登録中です。しばらくしてから再実行してください。');
        }
        return $locked[0];
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

    public static function reasonFor(string $message): ReasonCode
    {
        foreach (self::CORE_MESSAGES as [$phrase, , $reason]) {
            if (str_contains($message, $phrase)) {
                return $reason;
            }
        }
        return ReasonCode::MediaRegisterFailed;
    }

    private function translate(\Throwable $e): SourceException
    {
        // コアの文言には、サーバーのパスなどが含まれうる。画面には理由コードの文言だけを出し、詳細は監査ログに残す
        $reason = self::reasonFor($e->getMessage());
        (new AuditLog())->notice('メディアの取り込みに失敗しました', ['reason' => $reason->value, 'error' => $e->getMessage()]);
        return new SourceException($reason, '', $e);
    }
}
