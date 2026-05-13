<?php
/** Dashboard JSON API. Upload to /api/parkli/latest/index.php */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dataDir = __DIR__ . '/../../data';
$latestFile = $dataDir . '/latest.json';
$historyFile = $dataDir . '/history.jsonl';

$latest = [
    'updatedAt' => gmdate('c'),
    'deviceId' => null,
    'temperature' => ['s1' => null, 's2' => null, 's3' => null],
    'ph' => null,
    'tds' => null,
    'battery' => null,
    'rssi' => null,
    'snr' => null
];

if (file_exists($latestFile)) {
    $loaded = json_decode(file_get_contents($latestFile), true);
    if (is_array($loaded)) $latest = $loaded;
}

$history = [];
if (file_exists($historyFile)) {
    $lines = file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_slice($lines, -5000);
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry)) $history[] = $entry;
    }
}

$latest['history'] = $history;
echo json_encode($latest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
