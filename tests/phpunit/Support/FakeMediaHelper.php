<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Support;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Services\Facades\Database;
use Acms\Services\Media\Helper;
use Acms\Services\Media\MediaImportPreview;

/**
 * 実ファイルの保存（画像のリサイズなど）をせず、media テーブルへの登録とハッシュ重複の再利用だけ行う Media\Helper の代用。
 */
final class FakeMediaHelper extends Helper
{
    /** @var list<string> 登録したファイル名 */
    public array $registered = [];

    /** @var list<array{string, string}> saveTags の呼び出し */
    public array $tagCalls = [];

    public bool $failRegister = false;

    public function previewRegisterLocalFile(string $filepath, string $filename): MediaImportPreview
    {
        $hash = hash_file('sha256', $filepath);
        $existing = Database::query([
            'sql' => 'SELECT media_id FROM ' . DB_PREFIX . 'media WHERE media_hash = ? ORDER BY media_id LIMIT 1',
            'params' => [$hash],
        ], 'one');
        return is_numeric($existing) && (int) $existing > 0 ? MediaImportPreview::reuse((int) $existing, $filename) : MediaImportPreview::create($filename);
    }

    public function registerLocalFile(string $filepath, string $filename): int
    {
        if ($this->failRegister) {
            throw new \RuntimeException('この画像形式は登録できません。');
        }
        $preview = $this->previewRegisterLocalFile($filepath, $filename);
        if ($preview->isDuplicate() && $preview->reusedMediaId() !== null) {
            return $preview->reusedMediaId();
        }
        $hash = hash_file('sha256', $filepath);
        $mid = Cast::int(Database::query(\SQL::nextval('media_id', dsn()), 'seq'));
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
        Database::query([
            'sql' => 'INSERT INTO ' . DB_PREFIX . 'media
                (media_id, media_status, media_type, media_extension, media_path, media_thumbnail, media_original, media_file_name,
                 media_image_size, media_file_size, media_upload_date, media_update_date, media_field_1, media_field_2, media_field_3,
                 media_field_4, media_field_5, media_field_6, media_user_id, media_blog_id, media_hash)
                VALUES (?, ?, ?, ?, ?, \'\', \'\', ?, \'\', ?, NOW(), NOW(), \'\', \'\', \'\', \'\', \'\', \'\', ?, ?, ?)',
            'params' => [
                $mid, $isImage ? '' : 'entry', $isImage ? 'image' : 'file', $ext, 'fake/' . $mid . '-' . $filename,
                $filename, (string) filesize($filepath), 1, \BID, $hash,
            ],
        ], 'exec');
        $this->registered[] = $filename;
        return $mid;
    }

    /** @var list<int> */
    public array $deleted = [];

    public function deleteItem($mid)
    {
        $this->deleted[] = $mid;
        Database::query(['sql' => 'DELETE FROM ' . DB_PREFIX . 'media WHERE media_id = ?', 'params' => [$mid]], 'exec');
    }

    public function saveTags($mid, $tags, $bid = BID)
    {
        $this->tagCalls[] = [Cast::str($mid), Cast::str($tags)];
    }
}
