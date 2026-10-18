<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain;

enum ReasonCode: string
{
    case SourceNotFound = 'SOURCE_NOT_FOUND';
    case SourceForbidden = 'SOURCE_FORBIDDEN';
    case SourceTooLarge = 'SOURCE_TOO_LARGE';
    case SourceInvalidMime = 'SOURCE_INVALID_MIME';
    case EntryLocked = 'ENTRY_LOCKED';
    case StaleSnapshot = 'STALE_SNAPSHOT';
    case DestConflict = 'DEST_CONFLICT';
    case PartialSource = 'PARTIAL_SOURCE';
    case UnitTypeUnmapped = 'UNIT_TYPE_UNMAPPED';
    case MediaRegisterFailed = 'MEDIA_REGISTER_FAILED';
    case RollbackConflict = 'ROLLBACK_CONFLICT';
    case AltMismatch = 'ALT_MISMATCH';
    case MediaOtherBlog = 'MEDIA_OTHER_BLOG';
    case CleanupBlocked = 'CLEANUP_BLOCKED';
    case UnknownError = 'UNKNOWN_ERROR';

    public function label(): string
    {
        return match ($this) {
            self::SourceNotFound => '元ファイルが見つかりません',
            self::SourceForbidden => 'archives の外を指しています',
            self::SourceTooLarge => 'サイズ上限を超えています',
            self::SourceInvalidMime => '許可されていないファイル形式です',
            self::EntryLocked => 'エントリーが編集ロック中です',
            self::StaleSnapshot => 'スキャン後にデータが変更されました',
            self::DestConflict => '書き込み先のフィールドが既に存在します',
            self::PartialSource => '多言語ユニットの一部の言語で失敗しました',
            self::UnitTypeUnmapped => 'ユニット種類のマッピングがありません',
            self::MediaRegisterFailed => 'メディア登録に失敗しました',
            self::RollbackConflict => '変換後に編集されたため戻せません',
            self::AltMismatch => '再利用メディアの alt と一致しません',
            self::MediaOtherBlog => '別のブログのメディアを再利用しました',
            self::CleanupBlocked => '新しいフィールドが無いため、旧データを消せません',
            self::UnknownError => '予期しないエラー',
        };
    }
}
