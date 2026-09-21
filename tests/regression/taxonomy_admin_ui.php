<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = [
    'application/admin/controller/TaxonomyDictionary.php',
    'application/admin/view_new/taxonomy_dictionary/index.html',
    'application/admin/view_new/taxonomy_dictionary/info.html',
    'application/common/util/VodTaxonomyPanel.php',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        fwrite(STDERR, "FAIL: taxonomy admin artifact missing: {$path}\n");
        exit(1);
    }
}

$controller = (string) file_get_contents($root . '/application/admin/controller/TaxonomyDictionary.php');
foreach (['TaxonomyDictionaryService', "validate('Token')", 'ajaxErrorWithFreshToken', 'delete(', 'deactivate('] as $needle) {
    assertTaxonomyAdmin(strpos($controller, $needle) !== false, "dictionary controller missing {$needle}");
}

$list = (string) file_get_contents($root . '/application/admin/view_new/taxonomy_dictionary/index.html');
$form = (string) file_get_contents($root . '/application/admin/view_new/taxonomy_dictionary/info.html');
foreach (['詞典', 'kind', 'status', 'name_tw', 'synonyms'] as $needle) {
    assertTaxonomyAdmin(strpos($list . $form, $needle) !== false, "dictionary UI missing {$needle}");
}

$vod = (string) file_get_contents($root . '/application/admin/controller/Vod.php');
$vodForm = (string) file_get_contents($root . '/application/admin/view_new/vod/info.html');
foreach (['VodTaxonomyPanel', "assign('taxonomy_panel'", '自動分類／詞典', 'taxonomy-suggestion', '接受', '拒絕', '重新分類'] as $needle) {
    assertTaxonomyAdmin(strpos($vod . $vodForm, $needle) !== false, "video taxonomy panel missing {$needle}");
}
assertTaxonomyAdmin(strpos($vodForm, 'name="vod_id"') !== false, 'video editor identity is missing');
assertTaxonomyAdmin(strpos($vodForm, 'placeholder="輸入 vod_id"') === false, 'normal taxonomy flow asks for a manually typed vod_id');

$workspace = (string) file_get_contents($root . '/application/admin/controller/ContentWorkspace.php');
assertTaxonomyAdmin(strpos($workspace, 'accept_taxonomy') !== false && strpos($workspace, 'reject_taxonomy') !== false, 'review actions are not wired');

fwrite(STDOUT, "PASS: taxonomy administrator UI contract\n");

function assertTaxonomyAdmin(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
