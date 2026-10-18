<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Unit\Services\Domain;

use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class JobOptionsTest extends TestCase
{
    #[Test]
    public function 既定値は同名モードでリビジョンなし_enlargedはauto(): void
    {
        $o = JobOptions::defaults();

        $this->assertCount(4, $o->targets);
        $this->assertSame(JobOptions::FIELD_MODE_SAME, $o->fieldMode);
        $this->assertSame('_media', $o->fieldSuffix);
        $this->assertFalse($o->includeRevisions);
        $this->assertFalse($o->includeChildBlogs);
        $this->assertSame('auto', $o->enlarged);
        $this->assertTrue($o->appendToGroup);
        $this->assertTrue($o->removeLegacyKeys);
        $this->assertSame(['open', 'close', 'draft'], $o->entryStatus);
        $this->assertSame(20, $o->chunkSize);
    }

    #[Test]
    public function JSONで往復できる(): void
    {
        $o = JobOptions::fromArray([
            'targets' => ['unit_image', 'field_file'],
            'unitTypeMap' => ['image' => 'media', 'file_2' => 'media_2'],
            'field' => ['mode' => 'custom', 'custom' => ['hero' => 'visual'], 'includeKeys' => ['hero'], 'excludeKeys' => ['og'], 'appendToGroup' => false],
            'owners' => ['entry'],
            'includeRevisions' => true,
            'entryStatus' => ['open'],
            'media' => ['fillAltOnNew' => false, 'enlarged' => 'yes', 'tags' => ['archives-import']],
            'chunkSize' => 5,
        ]);

        $again = JobOptions::fromJson($o->toJson());

        $this->assertEquals($o->toArray(), $again->toArray());
        $this->assertTrue($again->hasTarget(TargetType::UnitImage));
        $this->assertFalse($again->hasTarget(TargetType::UnitFile));
        $this->assertSame('media_2', $again->mediaUnitTypeFor('file_2'));
        $this->assertSame('media', $again->mediaUnitTypeFor('image_9'));
        $this->assertSame(5, $again->chunkSize);
    }

    #[Test]
    public function 同名モードでは旧キー削除が既定でオン(): void
    {
        $o = JobOptions::fromArray(['field' => ['mode' => 'same']]);
        $this->assertTrue($o->removeLegacyKeys);

        $o = JobOptions::fromArray(['field' => ['mode' => 'same', 'removeLegacyKeys' => false]]);
        $this->assertFalse($o->removeLegacyKeys);
    }

    #[Test]
    public function 別名モードでは旧キー削除を指定しても無効(): void
    {
        $o = JobOptions::fromArray(['field' => ['mode' => 'suffix', 'removeLegacyKeys' => true]]);
        $this->assertFalse($o->removeLegacyKeys);
    }

    #[Test]
    public function chunkSizeは1から100に丸める(): void
    {
        $this->assertSame(1, JobOptions::fromArray(['chunkSize' => 0])->chunkSize);
        $this->assertSame(100, JobOptions::fromArray(['chunkSize' => 9999])->chunkSize);
        $this->assertSame(30, JobOptions::fromArray(['chunkSize' => '30'])->chunkSize);
    }

    /**
     * @param array<mixed> $input
     */
    #[Test]
    #[DataProvider('invalidInputs')]
    public function 不正な入力は例外(array $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JobOptions::fromArray($input);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function invalidInputs(): array
    {
        return [
            '未知の対象' => [['targets' => ['nope']]],
            '対象が配列でない' => [['targets' => 'unit_image']],
            'ユニット種類マップが配列でない' => [['unitTypeMap' => 'x']],
            'マップのキーが不正' => [['unitTypeMap' => ['text' => 'media']]],
            'マップの値が不正' => [['unitTypeMap' => ['image' => 'image']]],
            'fieldが配列でない' => [['field' => 'x']],
            '未知のモード' => [['field' => ['mode' => 'x']]],
            '接尾辞が不正' => [['field' => ['suffix' => 'a b']]],
            'customが配列でない' => [['field' => ['custom' => 'x']]],
            'customの名前が不正' => [['field' => ['custom' => ['a' => 'b c']]]],
            'includeKeysが不正' => [['field' => ['includeKeys' => [1]]]],
            '未知の所属' => [['owners' => ['dog']]],
            '未知のエントリー状態' => [['entryStatus' => ['x']]],
            'mediaが配列でない' => [['media' => 'x']],
            'enlargedが不正' => [['media' => ['enlarged' => 'maybe']]],
            'タグが不正' => [['media' => ['tags' => ['a,b']]]],
            'chunkSizeが不正' => [['chunkSize' => 'abc']],
        ];
    }

    #[Test]
    public function 重複する対象は1つにまとめる(): void
    {
        $o = JobOptions::fromArray(['targets' => ['unit_image', 'unit_image'], 'owners' => ['entry', 'entry']]);
        $this->assertCount(1, $o->targets);
        $this->assertSame(['entry'], $o->owners);
    }

    #[Test]
    public function fromJsonは壊れたJSONでも既定値(): void
    {
        $this->assertEquals(JobOptions::defaults()->toArray(), JobOptions::fromJson('not json')->toArray());
    }

    #[Test]
    public function フィールド名の判定(): void
    {
        $this->assertTrue(JobOptions::isFieldName('main_image-1'));
        $this->assertFalse(JobOptions::isFieldName(''));
        $this->assertFalse(JobOptions::isFieldName('a@b'));
    }
}
