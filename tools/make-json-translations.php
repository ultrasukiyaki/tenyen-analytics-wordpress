<?php

declare(strict_types=1);

$project = dirname(__DIR__);
$poFile = $project . '/languages/tenyen-analytics-ja.po';
$contents = (string)file_get_contents($poFile);
$translations = [];

if (preg_match_all('/^msgid "((?:[^"\\\\]|\\\\.)*)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/m', $contents, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $match) {
        $id = stripcslashes($match[1]);
        $value = stripcslashes($match[2]);
        if ($id !== '' && $value !== '') {
            $translations[$id] = [$value];
        }
    }
}

$header = [
    '' => [
        'domain' => 'tenyen-analytics',
        'lang' => 'ja',
        'plural-forms' => 'nplurals=1; plural=0;',
    ],
];

foreach ([
    'assets/admin-charts.js', 'assets/admin-history.js', 'assets/admin-sessions.js',
    'assets/admin-metadata.js', 'assets/admin-exclusions.js', 'assets/admin-lifecycle.js', 'assets/dashboard-widget.js',
] as $relative) {
    $json = [
        'translation-revision-date' => gmdate('Y-m-d H:iO'),
        'generator' => 'tools/make-json-translations.php',
        'source' => $relative,
        'domain' => 'messages',
        'locale_data' => ['messages' => $header + $translations],
    ];
    $hash = md5($relative);
    $target = $project . '/languages/tenyen-analytics-ja-' . $hash . '.json';
    file_put_contents($target, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
}
