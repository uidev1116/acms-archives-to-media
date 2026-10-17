<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain\Locator;

final class LocatorFactory
{
    public static function fromJson(string $json): LocatorInterface
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('locator が不正です。');
        }
        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): LocatorInterface
    {
        $rev = isset($data['rev_id']) && $data['rev_id'] !== null ? (int) $data['rev_id'] : null;
        if (($data['kind'] ?? '') === 'column') {
            return new ColumnLocator((string) ($data['column_id'] ?? ''), $rev);
        }
        if (($data['kind'] ?? '') === 'field') {
            $ownerId = $data['owner_id'] ?? 0;
            $owner = (string) ($data['owner'] ?? '');
            return new FieldLocator(
                (string) ($data['key'] ?? ''),
                (int) ($data['sort'] ?? 0),
                $owner,
                $owner === 'unit_id' ? (string) $ownerId : (int) $ownerId,
                $rev,
                (int) ($data['blog_id'] ?? 0),
            );
        }
        throw new \InvalidArgumentException('locator の種類が不正です。');
    }
}
