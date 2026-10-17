<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Services\Domain;

/**
 * ジョブのオプション（設計書 §7.4）。JSON との相互変換と検証を担う値オブジェクト。
 */
final class JobOptions
{
    public const FIELD_MODE_SUFFIX = 'suffix';
    public const FIELD_MODE_SAME = 'same';
    public const FIELD_MODE_CUSTOM = 'custom';

    public const OWNERS = ['entry', 'category', 'blog', 'user', 'module', 'unit'];
    public const ENTRY_STATUSES = ['open', 'close', 'draft', 'trash'];
    public const ENLARGED = ['auto', 'yes', 'no'];

    /**
     * @param list<TargetType> $targets
     * @param array<string, string> $unitTypeMap 変換元ユニット種類 => メディアユニット種類
     * @param array<string, string> $fieldCustom 元フィールド名 => 書き込み先フィールド名
     * @param list<string> $includeKeys
     * @param list<string> $excludeKeys
     * @param list<string> $owners
     * @param list<string> $entryStatus
     * @param list<string> $tags
     */
    public function __construct(
        public readonly array $targets,
        public readonly array $unitTypeMap,
        public readonly string $fieldMode,
        public readonly string $fieldSuffix,
        public readonly array $fieldCustom,
        public readonly array $includeKeys,
        public readonly array $excludeKeys,
        public readonly bool $appendToGroup,
        public readonly bool $removeLegacyKeys,
        public readonly array $owners,
        public readonly bool $includeRevisions,
        public readonly bool $includeChildBlogs,
        public readonly array $entryStatus,
        public readonly bool $fillAltOnNew,
        public readonly string $enlarged,
        public readonly array $tags,
        public readonly int $chunkSize,
    ) {
    }

    /**
     * 既定値のオプション
     */
    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /**
     * 入力（JSON をデコードした配列）から検証してオブジェクトを作る。
     *
     * 不正な値は黙って直さず例外にする（ただし欠けているキーは既定値で補う）。
     *
     * @param array<mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $targets = [];
        $rawTargets = self::listOf($data['targets'] ?? null) ?? array_map(
            static fn (TargetType $t): string => $t->value,
            TargetType::cases()
        );
        foreach ($rawTargets as $raw) {
            $target = is_string($raw) ? TargetType::tryFrom($raw) : null;
            if ($target === null) {
                throw new \InvalidArgumentException('不正な変換対象です。');
            }
            if (!in_array($target, $targets, true)) {
                $targets[] = $target;
            }
        }

        $unitTypeMap = [];
        $rawMap = $data['unitTypeMap'] ?? [];
        if (!is_array($rawMap)) {
            throw new \InvalidArgumentException('unitTypeMap が不正です。');
        }
        foreach ($rawMap as $from => $to) {
            if (!is_string($to) || preg_match('/^(image|file)(_\d+)?$/', (string) $from) !== 1 || preg_match('/^media(_\d+)?$/', $to) !== 1) {
                throw new \InvalidArgumentException('ユニット種類のマッピングが不正です。');
            }
            $unitTypeMap[(string) $from] = $to;
        }

        $field = $data['field'] ?? [];
        if (!is_array($field)) {
            throw new \InvalidArgumentException('field が不正です。');
        }
        $mode = $field['mode'] ?? self::FIELD_MODE_SUFFIX;
        if (!in_array($mode, [self::FIELD_MODE_SUFFIX, self::FIELD_MODE_SAME, self::FIELD_MODE_CUSTOM], true)) {
            throw new \InvalidArgumentException('フィールドの書き込み先モードが不正です。');
        }
        $suffix = $field['suffix'] ?? '_media';
        if (!is_string($suffix) || preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $suffix) !== 1) {
            throw new \InvalidArgumentException('フィールド名の接尾辞が不正です。');
        }
        $custom = [];
        $rawCustom = $field['custom'] ?? [];
        if (!is_array($rawCustom)) {
            throw new \InvalidArgumentException('field.custom が不正です。');
        }
        foreach ($rawCustom as $from => $to) {
            if (!is_string($to) || !self::isFieldName((string) $from) || !self::isFieldName($to)) {
                throw new \InvalidArgumentException('フィールド名のマッピングが不正です。');
            }
            $custom[(string) $from] = $to;
        }
        $includeKeys = self::fieldNames($field['includeKeys'] ?? []);
        $excludeKeys = self::fieldNames($field['excludeKeys'] ?? []);

        $owners = self::enumList($data['owners'] ?? null, self::OWNERS, ['entry', 'category', 'blog', 'user', 'module', 'unit']);
        $entryStatus = self::enumList($data['entryStatus'] ?? null, self::ENTRY_STATUSES, ['open', 'close', 'draft']);

        $media = $data['media'] ?? [];
        if (!is_array($media)) {
            throw new \InvalidArgumentException('media が不正です。');
        }
        $enlarged = $media['enlarged'] ?? 'auto';
        if (!is_string($enlarged) || !in_array($enlarged, self::ENLARGED, true)) {
            throw new \InvalidArgumentException('enlarged が不正です。');
        }
        $tags = [];
        foreach (self::listOf($media['tags'] ?? []) ?? [] as $tag) {
            if (!is_string($tag) || trim($tag) === '' || str_contains($tag, ',') || mb_strlen($tag) > 64) {
                throw new \InvalidArgumentException('タグが不正です。');
            }
            $tags[] = trim($tag);
        }

        $chunk = $data['chunkSize'] ?? 20;
        if (!is_int($chunk) && !(is_string($chunk) && ctype_digit($chunk))) {
            throw new \InvalidArgumentException('chunkSize が不正です。');
        }
        $chunk = max(1, min(100, (int) $chunk));

        return new self(
            targets: $targets,
            unitTypeMap: $unitTypeMap,
            fieldMode: $mode,
            fieldSuffix: $suffix,
            fieldCustom: $custom,
            includeKeys: $includeKeys,
            excludeKeys: $excludeKeys,
            appendToGroup: self::bool($field['appendToGroup'] ?? true),
            removeLegacyKeys: $mode === self::FIELD_MODE_SAME && self::bool($field['removeLegacyKeys'] ?? true),
            owners: $owners,
            includeRevisions: self::bool($data['includeRevisions'] ?? false),
            includeChildBlogs: self::bool($data['includeChildBlogs'] ?? false),
            entryStatus: $entryStatus,
            fillAltOnNew: self::bool($media['fillAltOnNew'] ?? true),
            enlarged: $enlarged,
            tags: $tags,
            chunkSize: $chunk,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'targets' => array_map(static fn (TargetType $t): string => $t->value, $this->targets),
            'unitTypeMap' => (object) $this->unitTypeMap,
            'field' => [
                'mode' => $this->fieldMode,
                'suffix' => $this->fieldSuffix,
                'custom' => (object) $this->fieldCustom,
                'includeKeys' => $this->includeKeys,
                'excludeKeys' => $this->excludeKeys,
                'appendToGroup' => $this->appendToGroup,
                'removeLegacyKeys' => $this->removeLegacyKeys,
            ],
            'owners' => $this->owners,
            'includeRevisions' => $this->includeRevisions,
            'includeChildBlogs' => $this->includeChildBlogs,
            'entryStatus' => $this->entryStatus,
            'media' => [
                'fillAltOnNew' => $this->fillAltOnNew,
                'enlarged' => $this->enlarged,
                'tags' => $this->tags,
            ],
            'chunkSize' => $this->chunkSize,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        return self::fromArray(is_array($data) ? $data : []);
    }

    public function hasTarget(TargetType $target): bool
    {
        return in_array($target, $this->targets, true);
    }

    /**
     * 変換元ユニット種類に対応するメディアユニット種類。未指定は 'media'。
     */
    public function mediaUnitTypeFor(string $sourceType): string
    {
        return $this->unitTypeMap[$sourceType] ?? 'media';
    }

    public static function isFieldName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $name) === 1;
    }

    /**
     * @return list<mixed>|null
     */
    private static function listOf(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('配列が必要です。');
        }
        return array_values($value);
    }

    /**
     * @return list<string>
     */
    private static function fieldNames(mixed $value): array
    {
        $out = [];
        foreach (self::listOf($value) ?? [] as $name) {
            if (!is_string($name) || !self::isFieldName($name)) {
                throw new \InvalidArgumentException('フィールド名が不正です。');
            }
            $out[] = $name;
        }
        return $out;
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $default
     * @return list<string>
     */
    private static function enumList(mixed $value, array $allowed, array $default): array
    {
        $list = self::listOf($value);
        if ($list === null) {
            return $default;
        }
        $out = [];
        foreach ($list as $v) {
            if (!is_string($v) || !in_array($v, $allowed, true)) {
                throw new \InvalidArgumentException('不正な値が含まれています。');
            }
            if (!in_array($v, $out, true)) {
                $out[] = $v;
            }
        }
        return $out;
    }

    private static function bool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array($v, [1, '1', 'true', 'on', 'yes'], true);
    }
}
