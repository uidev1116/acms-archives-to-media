<?php

declare(strict_types=1);

namespace Acms\Plugins\ArchivesToMedia\Tests\Integration\Converter;

use Acms\Plugins\ArchivesToMedia\Services\Domain\Candidate;
use Acms\Plugins\ArchivesToMedia\Services\Domain\JobOptions;
use Acms\Plugins\ArchivesToMedia\Services\Domain\Locator\FieldLocator;
use Acms\Plugins\ArchivesToMedia\Services\Domain\TargetType;
use Acms\Plugins\ArchivesToMedia\Services\Support\Cast;
use Acms\Plugins\ArchivesToMedia\Tests\Support\A2mTestCase;
use Acms\Services\Media\Helper;
use PHPUnit\Framework\Attributes\Test;

/**
 * 変換後のフィールドを、実際の表示処理（Media\Helper::injectMediaField）に通した結果を確かめる（設計書 §3.1 / §14）。
 */
final class DisplayInjectionTest extends A2mTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function mediaRow(int $id): array
    {
        return [
            'media_id' => $id, 'media_path' => '202610/hero.jpg', 'media_type' => 'image', 'media_file_name' => 'hero.jpg',
            'media_extension' => 'jpg', 'media_file_size' => '1234', 'media_field_1' => '', 'media_field_2' => '',
            'media_field_3' => 'メディア側の alt', 'media_field_4' => '', 'media_field_5' => '', 'media_field_6' => '',
            'media_update_date' => '2026-10-18 05:00:00', 'media_image_size' => '800 x 600', 'media_status' => '', 'media_thumbnail' => '',
        ];
    }

    /**
     * 所属のフィールド行から、テンプレートに渡る Field を組み立てる
     */
    private function loadField(): \Field
    {
        $field = new \Field();
        foreach ($this->fieldRows() as $row) {
            $field->addField(Cast::str($row['field_key']), Cast::str($row['field_value']));
        }
        return $field;
    }

    private function convert(string $mode): int
    {
        $this->insertImageField('hero', '001/hero.jpg', 1, '元の alt');
        $this->insertField('hero@largePath', '001/large-hero.jpg');
        $mediaId = 4242;
        $options = JobOptions::fromArray(['field' => ['mode' => $mode]]);
        $this->fieldConverter()->apply(
            new Candidate(TargetType::FieldImage, new FieldLocator('hero', 1, 'eid', $this->entryId, null, $this->blogId), 'entry', $this->entryId, $this->blogId, ['001/hero.jpg']),
            [$mediaId],
            $options
        );
        return $mediaId;
    }

    #[Test]
    public function 別名モード_旧キーは表示処理を通しても変わらず_新しいキーがメディアのURLを返す(): void
    {
        $mediaId = $this->convert('suffix');
        $field = $this->loadField();
        $oldPath = $field->get('hero@path');
        $oldAlt = $field->get('hero@alt');
        $oldLarge = $field->get('hero@largePath');

        (new Helper())->injectMediaField($field, [$mediaId => $this->mediaRow($mediaId)], ['hero_media']);

        $this->assertSame('001/hero.jpg', $oldPath);
        $this->assertSame($oldPath, $field->get('hero@path'), '旧 @path は上書きされない');
        $this->assertSame($oldAlt, $field->get('hero@alt'));
        $this->assertSame($oldLarge, $field->get('hero@largePath'));
        $this->assertStringContainsString('202610/hero.jpg', $field->get('hero_media@path'));
        $this->assertSame('メディア側の alt', $field->get('hero_media@alt'));
        $this->assertSame('image', $field->get('hero_media@type'));
    }

    #[Test]
    public function 同名モード_表示時に_pathがメディアのURLで上書きされる(): void
    {
        $mediaId = $this->convert('same');
        $field = $this->loadField();

        (new Helper())->injectMediaField($field, [$mediaId => $this->mediaRow($mediaId)], ['hero']);

        $this->assertStringContainsString('202610/hero.jpg', $field->get('hero@path'));
        $this->assertStringNotContainsString('001/hero.jpg', $field->get('hero@path'));
    }

    #[Test]
    public function メディアが見つからない値は空文字になる(): void
    {
        $this->convert('suffix');
        $field = $this->loadField();
        (new Helper())->injectMediaField($field, [], ['hero_media']);
        $this->assertSame('', $field->get('hero_media@path'));
    }
}
