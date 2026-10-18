<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Support;

use Acms\Services\Unit\Exceptions\UnitLockTimeoutException;

/**
 * エントリー単位で書き込みを直列化する。
 *
 * コアは、エントリーの保存やリビジョン切り替え（column の全削除→再登録）を UnitLock（MySQL の GET_LOCK）で
 * エントリー単位に直列化している。このプラグインの書き込みも同じロックの中で行い、保存と重ならないようにする。
 * 編集ロック（entry_lock）は Professional 版以上の機能で、無効なサイトでは働かないので、これとは別に必要。
 */
class EntrySerializer
{
    /**
     * @param (\Closure(int, callable(): mixed): mixed)|null $withLock null なら直列化しない（ロック機構が無い環境）
     */
    public function __construct(private readonly ?\Closure $withLock = null)
    {
    }

    /**
     * @template T
     * @param int|null $entryId null（エントリーに属さない対象）ならロックを取らない
     * @param \Closure(): T $fn
     * @return T
     * @throws EntryBusyException ロックを取れなかった
     */
    public function run(?int $entryId, \Closure $fn): mixed
    {
        if ($entryId === null || $this->withLock === null) {
            return $fn();
        }
        try {
            return ($this->withLock)($entryId, $fn);
        } catch (UnitLockTimeoutException $e) {
            throw new EntryBusyException('エントリーが別の処理で更新中です。', 0, $e);
        }
    }
}
