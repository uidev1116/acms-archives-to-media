<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Support;

use Acms\Plugins\ArchivesToMedia\Services\Support\Db;

/**
 * 読み取りの SQL を記録する Db。実行計画（EXPLAIN）やクエリの形を検証するためのテスト用。
 */
class RecordingDb extends Db
{
    /** @var list<array{0: string, 1: list<scalar|null>}> */
    public array $selects = [];

    public function all(string $sql, array $params = []): array
    {
        $this->selects[] = [$sql, $params];
        return parent::all($sql, $params);
    }
}
