<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$codecPath = $root . '/application/common/util/VodPlaybackCodec.php';
if (!is_file($codecPath)) {
    fwrite(STDERR, "FAIL: VodPlaybackCodec is missing.\n");
    exit(1);
}
require $codecPath;

use app\common\util\VodPlaybackCodec;

function playback_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n");
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . "\n");
        fwrite(STDERR, 'Actual: ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

function playback_assert_invalid(callable $operation, $message)
{
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$native = [
    'from' => 'line_a$$$line_b',
    'url' => '第1集$https://a.test/1.m3u8#第2集$https://a.test/path/$segment?sig=a$1$$$正片$https://b.test/movie.m3u8',
    'server' => 'server_a$$$',
    'note' => '主線$$$備用',
];
$decoded = VodPlaybackCodec::decode($native['from'], $native['url'], $native['server'], $native['note']);
playback_assert_same($native, VodPlaybackCodec::encode($decoded), 'decode/encode must preserve native parallel values.');
playback_assert_same(
    ['name' => '第2集', 'url' => 'https://a.test/path/$segment?sig=a$1', 'format' => 'named'],
    $decoded[0]['episodes'][1],
    'named records must split only on the first dollar sign.'
);

$recordForms = VodPlaybackCodec::decode(
    'forms',
    'https://a.test/raw.m3u8##名稱$#尾段$https://a.test/end.m3u8#',
    '',
    ''
);
playback_assert_same(
    [
        ['name' => '', 'url' => 'https://a.test/raw.m3u8', 'format' => 'url_only'],
        ['name' => '', 'url' => '', 'format' => 'empty'],
        ['name' => '名稱', 'url' => '', 'format' => 'named'],
        ['name' => '尾段', 'url' => 'https://a.test/end.m3u8', 'format' => 'named'],
        ['name' => '', 'url' => '', 'format' => 'empty'],
    ],
    $recordForms[0]['episodes'],
    'decoder must preserve URL-only, interior/trailing empty, and named-empty records.'
);
playback_assert_same(
    [
        'from' => 'forms',
        'url' => 'https://a.test/raw.m3u8##名稱$#尾段$https://a.test/end.m3u8#',
        'server' => '',
        'note' => '',
    ],
    VodPlaybackCodec::encode($recordForms),
    'record forms must encode losslessly and all-empty parallel columns must be canonical.'
);

$controlAndSchemeCases = [
    ["bad\nsource", 'https://a.test/1'],
    ['line', "https://a.test/\tbad"],
    ['line', ' javascript:alert(1)'],
    ['line', 'file:///tmp/video'],
    ['line', 'data:text/plain,no'],
    ['line', 'gopher://example.test/x'],
    ['line', 'ftp://example.test/x'],
    ['line', 'Episode$ javascript:alert(1)'],
];
foreach ($controlAndSchemeCases as $case) {
    playback_assert_invalid(function () use ($case) {
        VodPlaybackCodec::decode($case[0], $case[1]);
    }, 'unsafe source or URL input must be rejected.');
}

$validSource = [[
    'source' => 'line',
    'server' => '',
    'note' => '',
    'episodes' => [['name' => 'Episode', 'url' => 'https://a.test/1', 'format' => 'named']],
]];
$reservedCases = [
    ['source', 'bad$$$source'],
    ['server', 'bad$$$server'],
    ['note', 'bad$$$note'],
    ['episode_name', 'bad#name'],
    ['episode_name', 'bad$name'],
    ['episode_url', 'https://a.test/bad#fragment'],
    ['episode_url', 'https://a.test/bad$$$value'],
    ['named_url', '$$ambiguous'],
];
foreach ($reservedCases as $case) {
    $invalid = $validSource;
    if ($case[0] === 'source' || $case[0] === 'server' || $case[0] === 'note') {
        $invalid[0][$case[0]] = $case[1];
    } elseif ($case[0] === 'episode_name') {
        $invalid[0]['episodes'][0]['name'] = $case[1];
    } else {
        $invalid[0]['episodes'][0]['url'] = $case[1];
    }
    playback_assert_invalid(function () use ($invalid) {
        VodPlaybackCodec::encode($invalid);
    }, "encode must reject unrepresentable {$case[0]} values.");
    playback_assert_invalid(function () use ($invalid) {
        VodPlaybackCodec::merge($invalid, []);
    }, "merge must reject unrepresentable {$case[0]} values.");
}

$invalidShape = $validSource;
$invalidShape[0]['episodes'][0]['extra'] = true;
playback_assert_invalid(function () use ($invalidShape) {
    VodPlaybackCodec::encode($invalidShape);
}, 'encode must require the exact decoded shape.');

$primary = [[
    'source' => 'line_a',
    'server' => 'primary-server',
    'note' => 'primary-note',
    'episodes' => [
        ['name' => '主名稱', 'url' => ' https://a.test/watch?sig=a%2Bb ', 'format' => 'named'],
    ],
]];
$secondary = [
    [
        'source' => 'line_a',
        'server' => 'secondary-server',
        'note' => 'secondary-note',
        'episodes' => [
            ['name' => '不得覆蓋', 'url' => 'https://a.test/watch?sig=a%2Bb', 'format' => 'named'],
            ['name' => '', 'url' => 'https://a.test/new?sig=x+y', 'format' => 'url_only'],
        ],
    ],
    [
        'source' => 'line_b',
        'server' => '',
        'note' => '',
        'episodes' => [['name' => '新來源', 'url' => 'https://b.test/1', 'format' => 'named']],
    ],
];
$merged = VodPlaybackCodec::merge($primary, $secondary);
playback_assert_same(['line_a', 'line_b'], array_column($merged, 'source'), 'merge must retain primary source order and append new sources.');
playback_assert_same(2, count($merged[0]['episodes']), 'merge must deduplicate normalized URLs within a source.');
playback_assert_same($primary[0]['episodes'][0], $merged[0]['episodes'][0], 'a duplicate URL must retain the primary record byte-for-byte.');
playback_assert_same('https://a.test/new?sig=x+y', $merged[0]['episodes'][1]['url'], 'merge must not rewrite signed query parameters.');
playback_assert_same('primary-server', $merged[0]['server'], 'merge must retain primary source metadata.');
VodPlaybackCodec::encode($merged);

fwrite(STDOUT, "OK: lossless video playback codec contract passed.\n");
