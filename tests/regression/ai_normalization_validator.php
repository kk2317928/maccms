<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/application/common/util/AiNormalizationValidator.php';
if (!is_file($path)) { fwrite(STDERR, "FAIL: AiNormalizationValidator is missing.\n"); exit(1); }
require_once $path;

use app\common\util\AiNormalizationValidator;

function aiReject(AiNormalizationValidator $validator, array $data, string $message): void
{
    try { $validator->validate(json_encode($data)); } catch (InvalidArgumentException $exception) { return; }
    fwrite(STDERR, "FAIL: {$message}\n"); exit(1);
}

$validator = new AiNormalizationValidator();
$valid = [
    'normalized_title' => 'My Drama', 'original_title' => '원제',
    'title_tw' => '我的電視劇', 'title_cn' => '我的电视剧', 'title_en' => 'My Drama',
    'aliases' => ['Old Name', 'Old Name', '別名'], 'year' => 2025, 'media_type' => 'tv',
    'tmdb_clues' => ['title' => 'My Drama', 'year' => 2025, 'type' => 'tv'],
    'taxonomy' => ['regions' => ['KR'], 'genres' => ['Drama'], 'tags' => ['School']],
    'confidence' => 0.92, 'reason' => 'Titles and year agree.',
];
$result = $validator->validate(json_encode($valid, JSON_UNESCAPED_UNICODE));
if ($result['aliases'] !== ['Old Name', '別名'] || $result['confidence'] !== 0.92) {
    fwrite(STDERR, "FAIL: valid AI payload was not normalized deterministically.\n"); exit(1);
}

$missing = $valid; unset($missing['reason']); aiReject($validator, $missing, 'missing fields must fail');
$unknown = $valid; $unknown['raw_html'] = '<b>x</b>'; aiReject($validator, $unknown, 'unknown fields must fail');
$badYear = $valid; $badYear['year'] = '2025'; aiReject($validator, $badYear, 'year must be an integer');
$badConfidence = $valid; $badConfidence['confidence'] = 1.1; aiReject($validator, $badConfidence, 'confidence must be within zero and one');
$badType = $valid; $badType['media_type'] = 'podcast'; aiReject($validator, $badType, 'unsupported media type must fail');
$badTaxonomy = $valid; $badTaxonomy['taxonomy']['genres'] = [['name' => 'Drama']]; aiReject($validator, $badTaxonomy, 'taxonomy suggestions must be strings');
$htmlTitle = $valid; $htmlTitle['normalized_title'] = '<img src=x onerror=alert(1)>'; aiReject($validator, $htmlTitle, 'HTML in display fields must fail');

try { $validator->validate('{bad json'); } catch (InvalidArgumentException $exception) { fwrite(STDOUT, "OK: AI normalization schema contract passed.\n"); exit(0); }
fwrite(STDERR, "FAIL: malformed JSON must fail.\n"); exit(1);
