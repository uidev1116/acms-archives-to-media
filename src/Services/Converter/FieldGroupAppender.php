<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Support\FieldStore;

/**
 * フィールドグループ（@groupName の行）に、書き込み先のフィールド名を追記する。
 *
 * グループ定義は、field_key が @xxx で、値が所属フィールド名の行（1 フィールド名につき 1 行）。
 */
class FieldGroupAppender
{
    public function __construct(private readonly FieldStore $store)
    {
    }

    /**
     * {fd} を含むグループへ {dest} を追記し、追記した行を返す（ロールバック用）。
     *
     * @return list<array<string, mixed>>
     */
    public function append(FieldLocator $locator, string $fd, string $dest): array
    {
        $groupRows = $this->store->groupRows($locator);
        $added = [];
        $groups = [];
        foreach ($groupRows as $row) {
            $groups[Cast::str($row['field_key'])][] = $row;
        }
        foreach ($groups as $key => $rows) {
            $values = array_map(static fn (array $r): string => Cast::str($r['field_value']), $rows);
            if (!$this->declares($values, $fd) || in_array($dest, $values, true)) {
                continue;
            }
            $maxSort = max(array_map(static fn (array $r): int => Cast::int($r['field_sort']), $rows));
            $new = $rows[0];
            $new['field_key'] = $key;
            $new['field_value'] = $dest;
            $new['field_sort'] = $maxSort + 1;
            $this->store->insertRow($locator->table(), $new);
            $added[] = $new;
        }
        return $added;
    }

    /**
     * グループの項目に $fd が含まれるか。画像・ファイルは、カスタムフィールドメーカーが fd@path のように
     * 副キーで宣言する（コアは宣言された項目ごとに行を作るので、副キーを並べないと {fd@path} が行に入らない）。
     *
     * @param list<string> $members
     */
    private function declares(array $members, string $fd): bool
    {
        foreach ($members as $member) {
            if ($member === $fd || str_starts_with($member, $fd . '@')) {
                return true;
            }
        }
        return false;
    }
}
