<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

/**
 * 例外を、画面・CSV・ジョブの記録に出してよい文言にする。
 */
final class ErrorMessage
{
    public const GENERIC = '予期しないエラーが発生しました。詳細は管理画面の監査ログを確認してください。';

    /**
     * @param string $context 監査ログに残す場面の説明
     */
    public static function of(\Throwable $e, string $context = ''): string
    {
        if ($e instanceof PublicMessage) {
            return $e->getMessage();
        }
        (new AuditLog())->notice($context !== '' ? $context : '想定外のエラー', ['error' => $e::class . ': ' . $e->getMessage()]);
        return self::GENERIC;
    }
}
