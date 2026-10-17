<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Job;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Support\EntryLockGuard;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use Acms\Services\Entry\Lock;
use PHPUnit\Framework\Attributes\Test;

final class EntryLockGuardTest extends A2mTestCase
{
    private function guard(): EntryLockGuard
    {
        return new EntryLockGuard($this->db, new Lock('on', 'off', 48));
    }

    private function lockEntry(string $table = 'entry', ?string $at = null): void
    {
        $at ??= date('Y-m-d H:i:s');
        if ($table === 'entry_rev') {
            $this->insertTestData('entry_rev', [
                'entry_id' => $this->entryId, 'entry_rev_id' => 2, 'entry_lock_uid' => 2, 'entry_lock_datetime' => $at,
                'entry_title' => 't', 'entry_code' => 'c', 'entry_status' => 'open', 'entry_blog_id' => $this->blogId,
                'entry_user_id' => 1, 'entry_datetime' => $at, 'entry_updated_datetime' => $at, 'entry_posted_datetime' => $at,
            ]);
            return;
        }
        $this->db->exec('UPDATE ' . $this->db->t('entry') . ' SET entry_lock_uid = 2, entry_lock_datetime = ? WHERE entry_id = ?', [$at, $this->entryId]);
    }

    #[Test]
    public function ロックが無ければfalse(): void
    {
        $this->insertColumn('u1', 'image');
        $this->assertFalse($this->guard()->isLocked(new ColumnLocator('u1')));
    }

    #[Test]
    public function entryのロックをユニットとフィールドで検出する(): void
    {
        $this->insertColumn('u1', 'image');
        $this->insertColumn('cu', 'custom');
        $this->lockEntry();

        $this->assertTrue($this->guard()->isLocked(new ColumnLocator('u1')));
        $this->assertTrue($this->guard()->isLocked(new FieldLocator('hero', 1, 'eid', $this->entryId, null, $this->blogId)));
        $this->assertTrue($this->guard()->isLocked(new FieldLocator('hero', 1, 'unit_id', 'cu', null, $this->blogId)));
    }

    #[Test]
    public function entry_revのロックも検出する(): void
    {
        $this->lockEntry('entry_rev');
        $this->assertTrue($this->guard()->isLocked(new FieldLocator('hero', 1, 'eid', $this->entryId, null, $this->blogId)));
    }

    #[Test]
    public function 期限切れのロックは無視する(): void
    {
        $this->lockEntry('entry', date('Y-m-d H:i:s', time() - 49 * 3600));
        $this->assertFalse($this->guard()->isLocked(new FieldLocator('hero', 1, 'eid', $this->entryId, null, $this->blogId)));
    }

    #[Test]
    public function エントリーに属さない所属_存在しないユニットはfalse(): void
    {
        $this->lockEntry();
        $this->assertFalse($this->guard()->isLocked(new FieldLocator('hero', 1, 'cid', 5, null, $this->blogId)));
        $this->assertFalse($this->guard()->isLocked(new FieldLocator('hero', 1, 'unit_id', 'ghost', null, $this->blogId)));
        $this->assertFalse($this->guard()->isLocked(new ColumnLocator('ghost')));
    }

    #[Test]
    public function リビジョンのユニットも所属エントリーで判定する(): void
    {
        $this->insertColumn('u1', 'image', [], null, null, 3);
        $this->lockEntry();
        $this->assertTrue($this->guard()->isLocked(new ColumnLocator('u1', 3)));
        $this->insertColumn('cu', 'custom', [], null, null, 3);
        $this->assertTrue($this->guard()->isLocked(new FieldLocator('h', 1, 'unit_id', 'cu', 3, $this->blogId)));
    }
}
