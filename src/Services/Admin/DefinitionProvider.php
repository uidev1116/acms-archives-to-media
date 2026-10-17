<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Admin;

use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Services\Support\BlogScope;
use Acms\Plugins\ArchivesToMedia\Services\Support\Db;

/**
 * スキャン前の設定画面に必要な情報（メディアユニットの種類、ブログにある元ユニット種類、フィールド名）
 */
class DefinitionProvider
{
    /**
     * @param \Closure(): list<string> $mediaUnitTypes エディター設定で使えるユニット種類（config の column_add_type）
     */
    public function __construct(
        private readonly Db $db,
        private readonly BlogScope $scope,
        private readonly \Closure $mediaUnitTypes,
    ) {
    }

    /**
     * @return array{mediaUnitTypes: list<string>, sourceUnitTypes: list<array{type: string, count: int}>, fieldKeys: array{image: list<string>, file: list<string>}}
     */
    public function get(int $blogId): array
    {
        $ids = $this->scope->blogIds($blogId, true);
        $in = $this->db->placeholders($ids);

        $media = array_values(array_unique(array_filter(
            ($this->mediaUnitTypes)(),
            static fn (string $t): bool => preg_match('/^media(_\d+)?$/', $t) === 1
        )));

        $types = $this->db->all(
            'SELECT column_type, COUNT(*) AS c FROM ' . $this->db->t('column') . '
             WHERE column_blog_id IN (' . $in . ') AND column_field_2 <> \'\'
               AND (column_type REGEXP \'^(image|file)(_[0-9]+)?$\')
             GROUP BY column_type ORDER BY (column_type LIKE \'image%\') DESC, column_type',
            $ids
        );

        $keys = $this->db->all(
            'SELECT DISTINCT field_key FROM ' . $this->db->t('field') . '
             WHERE field_blog_id IN (' . $in . ') AND (field_key LIKE \'%@path\' OR field_key LIKE \'%@baseName\' OR field_key LIKE \'%@x\')',
            $ids
        );
        $paths = [];
        $base = [];
        $x = [];
        foreach ($keys as $row) {
            $key = Cast::str($row['field_key']);
            $fd = substr($key, 0, (int) strrpos($key, '@'));
            match (substr($key, (int) strrpos($key, '@') + 1)) {
                'path' => $paths[$fd] = true,
                'baseName' => $base[$fd] = true,
                default => $x[$fd] = true,
            };
        }
        $image = [];
        $file = [];
        foreach (array_keys($paths) as $fd) {
            if (isset($base[$fd]) && !isset($x[$fd])) {
                $file[] = $fd;
            } else {
                $image[] = $fd;
            }
        }
        sort($image);
        sort($file);

        return [
            'mediaUnitTypes' => $media === [] ? ['media'] : $media,
            'sourceUnitTypes' => array_map(static fn (array $r): array => ['type' => Cast::str($r['column_type']), 'count' => Cast::int($r['c'])], $types),
            'fieldKeys' => ['image' => $image, 'file' => $file],
        ];
    }
}
