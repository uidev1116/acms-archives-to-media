<?php

/**
 * 動作確認用の子ブログ（コード a2m-test）に、未変換のテストデータを作る。何度実行しても同じ状態に戻る。
 *
 *   docker exec -w /var/www/plugins/ArchivesToMedia \
 *     -e ACMS_DB_HOST=db -e ACMS_DB_NAME=db_my-blog-site -e ACMS_DB_USER=root \
 *     -e ACMS_DB_PASS="$(docker exec my-blog-site-backend-db-1 printenv MYSQL_ROOT_PASSWORD)" \
 *     my-blog-site-backend-web-1 php tests/e2e/seed-a2m-test.php
 *
 * 安全のため、コードが a2m-test の子ブログ（親ブログではない）だけを対象にする。
 * 親ブログ（既存コンテンツ）には一切触れない。
 *
 * 作るもの:
 *   エントリー a2m-units  … 画像ユニット（large 版あり／なし／ファイル欠落／多言語）、ファイルユニット
 *   エントリー a2m-fields … 画像フィールド（グループ内を含む）、ファイルフィールド、ファイルが無いフィールド
 *   エントリー a2m-locked … 編集ロック中のエントリー（ENTRY_LOCKED のスキップ確認用）
 *   エントリー a2m-many   … 画像ユニット 30 件（一時停止・再開の確認用）
 *   エントリー a2m-custom … カスタムユニット内のフィールド（画像・ファイル）とフィールドグループ（カスタムユニット所属の確認用）
 *   ブログ・カテゴリー・ユーザー・モジュール … それぞれに画像・ファイルフィールドとフィールドグループ（エントリー以外の所属の確認用）
 *   エントリー a2m-rev    … リビジョン（entry_rev / column_rev / field_rev）を持つエントリー（リビジョンを含める設定の確認用）
 *   archives/a2m-test/    … 上記が参照する実ファイル
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

\Acms\TestingFramework\Bootstrap::boot(getenv('ACMS_ROOT') ?: '/var/www/html');

use Acms\Services\Facades\Database;

const BLOG_CODE = 'a2m-test';
const UNIT_DELIM = ':acms_unit_delimiter:';

/**
 * @param array<scalar|null> $params
 * @return mixed
 */
function q(string $sql, array $params = [], string $mode = 'all')
{
    return Database::query(['sql' => $sql, 'params' => $params], $mode);
}

function t(string $table): string
{
    return DB_PREFIX . $table;
}

function nextval(string $column): int
{
    q('UPDATE ' . t('sequence') . " SET {$column} = {$column} + 1", [], 'exec');
    return (int) q('SELECT ' . $column . ' FROM ' . t('sequence'), [], 'one');
}

$blog = q('SELECT blog_id, blog_parent FROM ' . t('blog') . ' WHERE blog_code = ?', [BLOG_CODE], 'row');
if (!is_array($blog) || (int) $blog['blog_parent'] === 0) {
    fwrite(STDERR, "子ブログ " . BLOG_CODE . " が見つかりません。先に管理画面で作成してください。\n");
    exit(1);
}
$blogId = (int) $blog['blog_id'];
$archivesRoot = rtrim(SCRIPT_DIR, '/') . '/' . trim(ARCHIVES_DIR, '/') . '/';
$dir = $archivesRoot . BLOG_CODE . '/';

// ---------------------------------------------------------------- 片付け（この子ブログの分だけ）
$entryIds = array_map(static fn ($r) => (int) $r['entry_id'], q('SELECT entry_id FROM ' . t('entry') . ' WHERE entry_blog_id = ?', [$blogId]));
foreach (['column', 'column_rev', 'field', 'field_rev'] as $table) {
    $col = str_starts_with($table, 'column') ? 'column_blog_id' : 'field_blog_id';
    q('DELETE FROM ' . t($table) . " WHERE {$col} = ?", [$blogId], 'exec');
}
foreach (['entry', 'entry_rev'] as $table) {
    q('DELETE FROM ' . t($table) . ' WHERE entry_blog_id = ?', [$blogId], 'exec');
}
q('DELETE FROM ' . t('media') . ' WHERE media_blog_id = ?', [$blogId], 'exec');
q('DELETE FROM ' . t('category') . ' WHERE category_blog_id = ? AND category_code = ?', [$blogId, 'a2m-seed-category'], 'exec');
q('DELETE FROM ' . t('module') . ' WHERE module_blog_id = ? AND module_identifier = ?', [$blogId, 'a2m_seed_module'], 'exec');
q('DELETE FROM ' . t('user') . ' WHERE user_blog_id = ? AND user_code = ?', [$blogId, 'a2m-seed-user'], 'exec');
q('DELETE FROM ' . t('a2m_path_map') . ' WHERE map_blog_id = ?', [$blogId], 'exec');
q('DELETE i FROM ' . t('a2m_item') . ' i INNER JOIN ' . t('a2m_job') . ' j ON j.job_id = i.item_job_id WHERE j.job_blog_id = ?', [$blogId], 'exec');
q('DELETE FROM ' . t('a2m_job') . ' WHERE job_blog_id = ?', [$blogId], 'exec');
unset($entryIds);

if (is_dir($dir)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
} else {
    mkdir($dir, 0755, true);
}

// ---------------------------------------------------------------- 実ファイル
function image(string $path, int $w, int $h, int $r, int $g, int $b, string $label): void
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    for ($i = 0; $i < 8; $i++) {
        imagefilledellipse($im, (int) ($w * (0.1 + 0.11 * $i)), (int) ($h * (0.2 + 0.08 * $i)), (int) ($w * 0.25), (int) ($w * 0.25), imagecolorallocatealpha($im, 255, 255, 255, 100));
    }
    imagestring($im, 5, 12, 12, $label, imagecolorallocate($im, 255, 255, 255));
    str_ends_with($path, '.png') ? imagepng($im, $path) : imagejpeg($im, $path, 88);
    imagedestroy($im);
}

image($dir . 'hero.jpg', 800, 600, 31, 111, 235, 'hero (normal)');
image($dir . 'large-hero.jpg', 1600, 1200, 31, 111, 235, 'hero (large original)');
image($dir . 'plain.png', 640, 480, 26, 127, 55, 'plain (no large)');
image($dir . 'ja.jpg', 640, 480, 207, 34, 46, 'ja');
image($dir . 'en.jpg', 640, 480, 154, 103, 0, 'en');
image($dir . 'gallery-1.jpg', 640, 480, 90, 60, 160, 'gallery 1');
image($dir . 'gallery-2.jpg', 640, 480, 20, 140, 160, 'gallery 2');
image($dir . 'large-gallery-2.jpg', 1280, 960, 20, 140, 160, 'gallery 2 (large)');
copy($dir . 'plain.png', $dir . 'plain-copy.png'); // 内容が同じ別パス（既存メディアの再利用の確認）
file_put_contents(
    $dir . 'estimate.pdf',
    "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
);
image($dir . 'custom-1.jpg', 640, 480, 200, 90, 90, 'custom 1');
image($dir . 'custom-2.jpg', 640, 480, 90, 200, 90, 'custom 2');
image($dir . 'rev-a.jpg', 640, 480, 120, 80, 30, 'rev a');
image($dir . 'rev-b.jpg', 640, 480, 30, 80, 120, 'rev b');
for ($i = 1; $i <= 30; $i++) {
    image($dir . sprintf('many-%02d.jpg', $i), 320, 240, ($i * 37) % 200 + 30, ($i * 71) % 200 + 30, ($i * 113) % 200 + 30, 'many ' . $i);
}

// 管理画面のプレビュー（square / tiny）が壊れないよう、画像フィールドの画像には tiny- と square- の派生ファイルも作る
// （コアが画像フィールドのアップロードで作るファイルと同じ名前。many-XX.jpg は画像ユニット専用なので作らない）
foreach (glob($dir . '*.{jpg,png}', GLOB_BRACE) ?: [] as $file) {
    $name = basename($file);
    if (preg_match('/^(large|tiny|square)-|^many-/', $name) === 1) {
        continue;
    }
    [$w, $h, $type] = getimagesize($file);
    $src = $type === IMAGETYPE_PNG ? imagecreatefrompng($file) : imagecreatefromjpeg($file);
    foreach (['tiny' => [200, (int) round(200 * $h / $w)], 'square' => [200, 200]] as $prefix => [$tw, $th]) {
        $dst = imagecreatetruecolor($tw, $th);
        if ($prefix === 'square') {
            $side = min($w, $h);
            imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $tw, $th, $side, $side);
        } else {
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        }
        $type === IMAGETYPE_PNG ? imagepng($dst, $dir . $prefix . '-' . $name) : imagejpeg($dst, $dir . $prefix . '-' . $name, 88);
        imagedestroy($dst);
    }
    imagedestroy($src);
}

// ---------------------------------------------------------------- エントリー・ユニット・フィールド
$now = date('Y-m-d H:i:s');

function entry(int $blogId, string $code, string $title, array $extra = []): int
{
    $id = nextval('sequence_entry_id');
    \Acms\TestingFramework\Seeder\EntrySeeder::seed($blogId, 1, null, array_merge([
        'entry_id' => $id, 'entry_code' => $code, 'entry_title' => $title, 'entry_status' => 'open',
        'entry_datetime' => date('Y-m-d H:i:s'),
    ], $extra));
    return $id;
}

/**
 * @param array<string, string> $f
 */
function unit(int $blogId, int $entryId, int $sort, string $type, array $f = [], string $size = ''): void
{
    $row = [
        'column_id' => (string) nextval('sequence_column_id'), 'column_status' => 'open', 'column_sort' => $sort,
        'column_align' => 'auto', 'column_type' => $type, 'column_parent_id' => null, 'column_anker' => '', 'column_attr' => '',
        'column_group' => '', 'column_size' => $size, 'column_field_1' => '', 'column_field_2' => '', 'column_field_3' => '',
        'column_field_4' => '', 'column_field_5' => '', 'column_field_6' => '', 'column_field_7' => '', 'column_field_8' => '',
        'column_group_class' => '', 'column_group_tag' => '', 'column_entry_id' => $entryId, 'column_blog_id' => $blogId,
    ];
    foreach ($f as $k => $v) {
        $row['column_field_' . $k] = $v;
    }
    q('INSERT INTO ' . t('column') . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row), 'exec');
}

/**
 * @param array<string, string> $kv
 */
function fieldRows(int $blogId, int $entryId, int $sort, array $kv): void
{
    foreach ($kv as $key => $value) {
        q(
            'INSERT INTO ' . t('field') . ' (field_key, field_value, field_type, field_sort, field_search, field_eid, field_blog_id) VALUES (?, ?, NULL, ?, \'off\', ?, ?)',
            [$key, $value, $sort, $entryId, $blogId],
            'exec'
        );
    }
}

/**
 * 画像フィールドの値。コアのアップロード処理が作る形（@path / @x / @y / @fileSize と、
 * large- / tiny- / square- の派生ファイルがあればその @…Path など）を、実ファイルから組み立てる。
 *
 * @return array<string, string>
 */
function imageField(string $fd, string $path, string $large, string $alt): array
{
    global $archivesRoot;
    $info = static function (string $rel) use ($archivesRoot): ?array {
        $file = $archivesRoot . $rel;
        $size = is_file($file) ? getimagesize($file) : false;
        return $size === false ? null : [(string) $size[0], (string) $size[1], (string) filesize($file)];
    };
    $variant = static fn (string $prefix): string => dirname($path) . '/' . $prefix . '-' . basename($path);
    $row = ["{$fd}@path" => $path, "{$fd}@x" => '', "{$fd}@y" => '', "{$fd}@alt" => $alt, "{$fd}@fileSize" => ''];
    if (($n = $info($path)) !== null) {
        [$row["{$fd}@x"], $row["{$fd}@y"], $row["{$fd}@fileSize"]] = $n;
    }
    foreach (['large' => 'large', 'tiny' => 'tiny', 'square' => 'square'] as $prefix => $key) {
        $row["{$fd}@{$key}Path"] = '';
        $row["{$fd}@{$key}X"] = '';
        $row["{$fd}@{$key}Y"] = '';
        if (($v = $info($variant($prefix))) !== null) {
            $row["{$fd}@{$key}Path"] = $variant($prefix);
            [$row["{$fd}@{$key}X"], $row["{$fd}@{$key}Y"]] = $v;
            $row["{$fd}@{$key}Alt"] = $alt;
            $row["{$fd}@{$key}FileSize"] = $v[2];
        }
    }
    $row["{$fd}@secret"] = md5($fd . '@' . $path);
    return $row;
}

/**
 * ファイルフィールドの値（@path / @baseName / @fileSize / @downloadName / @originalName / @secret）。
 *
 * @return array<string, string>
 */
function fileField(string $fd, string $path, string $downloadName): array
{
    global $archivesRoot;
    $file = $archivesRoot . $path;
    return [
        "{$fd}@path" => $path, "{$fd}@baseName" => basename($path), "{$fd}@fileSize" => is_file($file) ? (string) filesize($file) : '',
        "{$fd}@downloadName" => $downloadName, "{$fd}@originalName" => basename($path), "{$fd}@secret" => md5($fd . '@' . $path),
    ];
}

// 1. 画像ユニット・ファイルユニット
$e1 = entry($blogId, 'a2m-units', '画像ユニットとファイルユニット');
$p = BLOG_CODE . '/';
unit($blogId, $e1, 1, 'image', ['1' => 'large 版あり（リンク無し→拡大 yes の見込み）', '2' => $p . 'hero.jpg', '3' => '', '4' => 'ヒーロー画像', '5' => '50'], '800');
unit($blogId, $e1, 2, 'image', ['1' => 'large 版なし', '2' => $p . 'plain.png', '3' => 'https://example.com/', '4' => 'プレーン画像', '5' => '']);
unit($blogId, $e1, 3, 'image', ['1' => '内容が同じ別パス（再利用）', '2' => $p . 'plain-copy.png', '4' => '同じ画像']);
unit($blogId, $e1, 4, 'image', ['1' => 'ファイルが無い', '2' => $p . 'missing.jpg', '4' => '欠落']);
unit($blogId, $e1, 5, 'image', ['1' => '日本語' . UNIT_DELIM . 'English', '2' => $p . 'ja.jpg' . UNIT_DELIM . $p . 'en.jpg', '3' => '', '4' => 'あ' . UNIT_DELIM . 'a']);
unit($blogId, $e1, 6, 'file', ['1' => '見積書（PDF）', '2' => $p . 'estimate.pdf']);

// 2. フィールド
$e2 = entry($blogId, 'a2m-fields', '画像フィールドとファイルフィールド');
fieldRows($blogId, $e2, 1, imageField('hero', $p . 'hero.jpg', $p . 'large-hero.jpg', 'ヒーロー'));
fieldRows($blogId, $e2, 1, ['caption' => 'グループ内のキャプション']);
fieldRows($blogId, $e2, 1, imageField('gallery', $p . 'gallery-1.jpg', '', 'ギャラリー 1'));
fieldRows($blogId, $e2, 2, imageField('gallery', $p . 'gallery-2.jpg', $p . 'large-gallery-2.jpg', 'ギャラリー 2'));
fieldRows($blogId, $e2, 1, imageField('legacy', $p . 'gone.jpg', '', '消えた画像'));
fieldRows($blogId, $e2, 1, fileField('attachment', $p . 'estimate.pdf', '見積書.pdf'));
fieldRows($blogId, $e2, 1, ['@gallery_group' => 'gallery']);
fieldRows($blogId, $e2, 1, ['@profile' => 'hero']);
fieldRows($blogId, $e2, 2, ['@profile' => 'caption']);

// 3. 編集ロック中
$e3 = entry($blogId, 'a2m-locked', '編集ロック中のエントリー', ['entry_lock_uid' => 1, 'entry_lock_datetime' => $now]);
unit($blogId, $e3, 1, 'image', ['1' => 'ロック中', '2' => $p . 'ja.jpg', '4' => 'ロック中の画像']);

// 4. たくさん
$e4 = entry($blogId, 'a2m-many', '画像ユニットがたくさん');
for ($i = 1; $i <= 30; $i++) {
    unit($blogId, $e4, $i, 'image', ['1' => "many {$i}", '2' => $p . sprintf('many-%02d.jpg', $i), '4' => "alt {$i}"]);
}

// 5. リビジョン付き（本体と同じ内容を entry_rev / column_rev / field_rev に 2 世代ぶん持つ。2 世代目は別の画像）
$e5 = entry($blogId, 'a2m-rev', 'リビジョンを持つエントリー');
unit($blogId, $e5, 1, 'image', ['1' => 'リビジョン付き', '2' => $p . 'rev-b.jpg', '4' => 'rev alt']);
fieldRows($blogId, $e5, 1, imageField('cover', $p . 'rev-b.jpg', '', 'カバー'));
foreach ([1 => 'rev-a.jpg', 2 => 'rev-b.jpg'] as $revId => $file) {
    $entryRow = q('SELECT * FROM ' . t('entry') . ' WHERE entry_id = ?', [$e5], 'row');
    $entryRow += ['entry_approval_public_point' => 0, 'entry_approval_reject_point' => 0, 'entry_rev_id' => $revId, 'entry_rev_status' => 'none', 'entry_rev_user_id' => 1, 'entry_rev_datetime' => $now, 'entry_rev_memo' => 'seed rev ' . $revId];
    $revColumns = array_map(static fn ($r) => (string) $r['column_name'], q('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [t('entry_rev')]));
    $entryRow = array_intersect_key($entryRow, array_flip($revColumns));
    q('INSERT INTO ' . t('entry_rev') . ' (' . implode(',', array_keys($entryRow)) . ') VALUES (' . implode(',', array_fill(0, count($entryRow), '?')) . ')', array_values($entryRow), 'exec');
    foreach (q('SELECT * FROM ' . t('column') . ' WHERE column_entry_id = ?', [$e5]) as $row) {
        $row['column_rev_id'] = $revId;
        $row['column_field_2'] = $p . $file;
        q('INSERT INTO ' . t('column_rev') . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row), 'exec');
    }
    foreach (q('SELECT * FROM ' . t('field') . ' WHERE field_eid = ?', [$e5]) as $row) {
        $row['field_rev_id'] = $revId;
        if ($row['field_key'] === 'cover@path') {
            $row['field_value'] = $p . $file;
        }
        q('INSERT INTO ' . t('field_rev') . ' (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row), 'exec');
    }
}

// 6. カスタムユニット（画像フィールド・ファイルフィールドと、ユニット内のフィールドグループ）
$e6 = entry($blogId, 'a2m-custom', 'カスタムユニットを持つエントリー');
foreach ([1, 2] as $n) {
    unit($blogId, $e6, $n, 'custom_a2m_card');
    $uid = (string) q('SELECT column_id FROM ' . t('column') . ' WHERE column_entry_id = ? AND column_sort = ?', [$e6, $n], 'one');
    $rows = imageField('photo', $p . "custom-{$n}.jpg", '', "カスタム画像 {$n}") + fileField('doc', $p . 'estimate.pdf', "資料 {$n}.pdf") + ['title' => "カスタム {$n}"];
    foreach ($rows as $key => $value) {
        q('INSERT INTO ' . t('field') . ' (field_key, field_value, field_type, field_sort, field_search, field_eid, field_unit_id, field_blog_id) VALUES (?, ?, NULL, 1, \'off\', NULL, ?, ?)', [$key, $value, $uid, $blogId], 'exec');
    }
    // ユニット内のフィールドグループ（title / photo / doc を 1 グループに）
    foreach (['title', 'photo', 'doc'] as $i => $fd) {
        q('INSERT INTO ' . t('field') . ' (field_key, field_value, field_type, field_sort, field_search, field_eid, field_unit_id, field_blog_id) VALUES (\'@card\', ?, NULL, ?, \'off\', NULL, ?, ?)', [$fd, $i + 1, $uid, $blogId], 'exec');
    }
}

// 7. エントリー以外の所属（ブログ・カテゴリー・ユーザー・モジュール）のフィールド
$ownerIds = [
    'bid' => $blogId,
    'cid' => \Acms\TestingFramework\Seeder\CategorySeeder::seed($blogId, ['category_code' => 'a2m-seed-category', 'category_name' => 'a2m category']),
    'uid' => \Acms\TestingFramework\Seeder\UserSeeder::seed($blogId, ['user_code' => 'a2m-seed-user', 'user_name' => 'a2m user', 'user_mail' => 'a2m-seed-user@example.invalid']),
    'mid' => \Acms\TestingFramework\Seeder\ModuleSeeder::seed($blogId, ['module_identifier' => 'a2m_seed_module', 'module_name' => 'Calendar_Month']),
];
foreach ($ownerIds as $col => $ownerId) {
    $rows = imageField('ownerphoto', $p . 'custom-1.jpg', '', "{$col} の画像") + fileField('ownerdoc', $p . 'estimate.pdf', '資料.pdf') + ['ownertitle' => "{$col} のタイトル"];
    foreach ($rows as $key => $value) {
        q('INSERT INTO ' . t('field') . ' (field_key, field_value, field_type, field_sort, field_search, field_eid, field_' . $col . ', field_blog_id) VALUES (?, ?, NULL, 1, \'off\', NULL, ?, ?)', [$key, $value, $ownerId, $blogId], 'exec');
    }
    foreach (['ownertitle', 'ownerphoto', 'ownerdoc'] as $i => $fd) {
        q('INSERT INTO ' . t('field') . ' (field_key, field_value, field_type, field_sort, field_search, field_eid, field_' . $col . ', field_blog_id) VALUES (\'@owner\', ?, NULL, ?, \'off\', NULL, ?, ?)', [$fd, $i + 1, $ownerId, $blogId], 'exec');
    }
}

$moduleId = $ownerIds['mid'];
fwrite(STDERR, "seeded blog {$blogId}: entries {$e1}, {$e2}, {$e3}, {$e4}, {$e5}, {$e6}; module {$moduleId}\n");
