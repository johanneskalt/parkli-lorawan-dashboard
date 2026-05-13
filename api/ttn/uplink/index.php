<?php
/**
 * TTN webhook receiver.
 * Upload this file to /api/ttn/uplink/index.php
 * TTN should POST uplink messages to https://YOUR-SUBDOMAIN.example.org/api/ttn/uplink/
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$decoded = $body['uplink_message']['decoded_payload'] ?? [];
$rx = $body['uplink_message']['rx_metadata'][0] ?? [];

function clean_temperature($value) {
    if (!is_numeric($value)) return null;
    $n = floatval($value);
    if ($n <= -100) return null; // -127 usually means sensor missing/invalid
    return $n;
}

function numeric_or_null($value) {
    return is_numeric($value) ? floatval($value) : null;
}

$phRaw = numeric_or_null($decoded['PH'] ?? null);
$leitwertRaw = numeric_or_null($decoded['Leitwert'] ?? null);

$measurement = [
    'deviceId' => $body['end_device_ids']['device_id'] ?? null,
    'updatedAt' => $body['received_at'] ?? $body['uplink_message']['received_at'] ?? gmdate('c'),
    'temperature' => [
        's1' => clean_temperature($decoded['Temperatur_1'] ?? null),
        's2' => clean_temperature($decoded['Temperatur_2'] ?? null),
        's3' => clean_temperature($decoded['Temperatur_3'] ?? null),
    ],
    // Assumption: PH is pH x 100, e.g. 1175 => 11.75. Adjust if needed.
    'ph' => $phRaw !== null ? $phRaw / 100 : null,
    // Stored 1:1 from Leitwert. Adjust if you convert conductivity to TDS/ppm.
    'tds' => $leitwertRaw,
    'battery' => numeric_or_null($decoded['BatterieProzent'] ?? null),
    'rssi' => numeric_or_null($rx['rssi'] ?? null),
    'snr' => numeric_or_null($rx['snr'] ?? null),
    'airTemperature' => numeric_or_null($decoded['Air_Temperature'] ?? null),
    'humidity' => numeric_or_null($decoded['Humidity'] ?? null),
    'pressure' => numeric_or_null($decoded['Pressure'] ?? null)
];

$dataDir = __DIR__ . '/../../data';
if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);

file_put_contents($dataDir . '/latest.json', json_encode($measurement, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

$historyEntry = [
    'timestamp' => $measurement['updatedAt'],
    'date' => substr($measurement['updatedAt'], 0, 10),
    's1' => $measurement['temperature']['s1'],
    's2' => $measurement['temperature']['s2'],
    's3' => $measurement['temperature']['s3'],
    'ph' => $measurement['ph'],
    'tds' => $measurement['tds']
];

file_put_contents($dataDir . '/history.jsonl', json_encode($historyEntry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
http_response_code(204);
exit;
