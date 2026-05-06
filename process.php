<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Polyfills for mbstring — fall back gracefully if extension not loaded
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $str, ?string $encoding = null): string { return strtolower($str); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $str, ?string $encoding = null): int { return strlen($str); }
}

require_once __DIR__ . '/src/LogEntry.php';
require_once __DIR__ . '/src/LogParser.php';
require_once __DIR__ . '/src/LogFilter.php';
require_once __DIR__ . '/src/LogOutput.php';

function jsonError(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// --- Read raw log text ---
$rawText = '';

if (!empty($_FILES['logfile']['tmp_name'])) {
    $tmp = $_FILES['logfile']['tmp_name'];
    if ($_FILES['logfile']['error'] !== UPLOAD_ERR_OK) {
        jsonError('File upload error: ' . $_FILES['logfile']['error']);
    }
    $rawText = file_get_contents($tmp);
    if ($rawText === false) {
        jsonError('Could not read uploaded file.');
    }
} elseif (!empty($_POST['text'])) {
    $rawText = $_POST['text'];
} else {
    jsonError('No log text provided.');
}

// --- Parse filter options ---
$preset        = $_POST['preset'] ?? 'medium';
$mergeSplits   = ($_POST['merge_splits'] ?? '1') === '1';
$minPosts      = max(1, (int) ($_POST['min_posts'] ?? 2));
$filtersJson   = $_POST['filters'] ?? '[]';
$customJson    = $_POST['custom_filters'] ?? '[]';
$ignoredJson   = $_POST['ignored_speakers'] ?? '[]';

$activeFilters   = json_decode($filtersJson,  true) ?? [];
$customPatterns  = json_decode($customJson,   true) ?? [];
$ignoredSpeakers = json_decode($ignoredJson,  true) ?? [];

if (!is_array($activeFilters))   $activeFilters   = [];
if (!is_array($customPatterns))  $customPatterns  = [];
if (!is_array($ignoredSpeakers)) $ignoredSpeakers = [];

// Resolve preset to filter list (preset overrides individual selection unless 'custom')
if ($preset !== 'custom' && array_key_exists($preset, LogFilter::PRESETS)) {
    $activeFilters = LogFilter::PRESETS[$preset];
}

// Sanitise — keep only strings
$customPatterns  = array_values(array_filter($customPatterns,  'is_string'));
$ignoredSpeakers = array_values(array_filter($ignoredSpeakers, 'is_string'));

// --- Process ---
try {
    $parser  = new LogParser();
    $entries = $parser->parse($rawText, $mergeSplits);
} catch (\OverflowException $e) {
    jsonError($e->getMessage(), 413);
} catch (\Throwable $e) {
    jsonError('Parse error: ' . $e->getMessage());
}

$filter  = new LogFilter();
$entries = $filter->apply($entries, $activeFilters, $customPatterns, $ignoredSpeakers);

$output = new LogOutput();
$result = $output->generate($entries, $minPosts);

echo json_encode([
    'success'     => true,
    'summary'     => $result['summary'],
    'cleaned_log' => $result['cleaned_log'],
    'stats'       => $result['stats'],
]);
