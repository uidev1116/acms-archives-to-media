<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services\Domain;

use Acms\Plugins\ArchivesToMedia\Services\Domain\JobStatus;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\ColumnLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\LocatorFactory;
use Acms\Plugins\ArchivesToMedia\Services\Domain\ReasonCode;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class EnumsAndLocatorsTest extends TestCase
{
    #[Test]
    public function 対象種類の分類とラベル(): void
    {
        $this->assertTrue(TargetType::UnitImage->isUnit());
        $this->assertTrue(TargetType::UnitFile->isUnit());
        $this->assertTrue(TargetType::FieldImage->isField());
        $this->assertTrue(TargetType::FieldFile->isField());
        foreach (TargetType::cases() as $t) {
            $this->assertNotSame('', $t->label());
        }
    }

    #[Test]
    #[DataProvider('statusMatrix')]
    public function 状態ごとの操作可否(JobStatus $s, bool $run, bool $scan, bool $rollback): void
    {
        $this->assertSame($run, $s->canRun());
        $this->assertSame($scan, $s->canScan());
        $this->assertSame($rollback, $s->canRollback());
    }

    /**
     * @return array<string, array{0: JobStatus, 1: bool, 2: bool, 3: bool}>
     */
    public static function statusMatrix(): array
    {
        return [
            'draft' => [JobStatus::Draft, false, true, false],
            'scanning' => [JobStatus::Scanning, false, true, false],
            'scanned' => [JobStatus::Scanned, true, true, false],
            'running' => [JobStatus::Running, true, false, true],
            'paused' => [JobStatus::Paused, true, false, true],
            'completed' => [JobStatus::Completed, false, false, true],
            'failed' => [JobStatus::Failed, true, true, true],
            'rolling_back' => [JobStatus::RollingBack, false, false, true],
            'rolled_back' => [JobStatus::RolledBack, false, false, false],
        ];
    }

    #[Test]
    public function 理由コードには全部ラベルがある(): void
    {
        foreach (ReasonCode::cases() as $c) {
            $this->assertNotSame('', $c->label());
        }
    }

    #[Test]
    public function ColumnLocatorの往復(): void
    {
        $l = new ColumnLocator('abc', null);
        $this->assertSame('column', $l->table());
        $again = LocatorFactory::fromJson((string) json_encode($l->toArray()));
        $this->assertEquals($l, $again);

        $rev = new ColumnLocator('abc', 3);
        $this->assertSame('column_rev', $rev->table());
        $this->assertEquals($rev, LocatorFactory::fromArray($rev->toArray()));
    }

    #[Test]
    public function FieldLocatorの往復と所属種別(): void
    {
        $cases = [
            ['eid', 5, 'entry'], ['cid', 5, 'category'], ['uid', 5, 'user'],
            ['bid', 5, 'blog'], ['mid', 5, 'module'], ['unit_id', 'u-1', 'unit'],
        ];
        foreach ($cases as [$col, $id, $type]) {
            $l = new FieldLocator('hero', 2, $col, $id, null, 7);
            $this->assertSame($type, $l->ownerType());
            $this->assertSame('field', $l->table());
            $this->assertEquals($l, LocatorFactory::fromJson((string) json_encode($l->toArray())));
        }
        $rev = new FieldLocator('hero', 1, 'eid', 5, 9, 7);
        $this->assertSame('field_rev', $rev->table());
        $this->assertEquals($rev, LocatorFactory::fromArray($rev->toArray()));
    }

    #[Test]
    public function 不正なロケーターは例外(): void
    {
        $bad = [
            fn () => new ColumnLocator(''),
            fn () => new FieldLocator('k', 1, 'xid', 1, null, 1),
            fn () => new FieldLocator('', 1, 'eid', 1, null, 1),
            fn () => new FieldLocator('k', 1, 'eid', 0, null, 1),
            fn () => LocatorFactory::fromJson('nope'),
            fn () => LocatorFactory::fromArray(['kind' => 'x']),
        ];
        foreach ($bad as $fn) {
            try {
                $fn();
                $this->fail('例外が必要');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
