<?php
/** Optional admin endpoint to reset the chart history. Change the secret before deploying. */

header('Content-Type: text/plain; charset=utf-8');

$secret = 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET';

if (!isset($_GET['secret']) || $_GET['secret'] !== $secret) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$dataDir = __DIR__ . '/../data';
$historyFile = $dataDir . '/history.jsonl';

if (file_exists($historyFile)) unlink($historyFile);
if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);

file_put_contents($dataDir . '/reset.txt', "History reset: " . gmdate('c') . PHP_EOL, FILE_APPEND | LOCK_EX);
echo "History reset done.";
