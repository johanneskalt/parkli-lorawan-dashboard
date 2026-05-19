<?php
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
    if (!is_numeric($value)) {
        return null;
    }

    $n = floatval($value);

    // -127 bedeutet bei euch offenbar: Sensor nicht vorhanden / ungültig
    if ($n <= -100) {
        return null;
    }

    return $n;
}

function numeric_or_null($value) {
    return is_numeric($value) ? floatval($value) : null;
}

$phRaw = numeric_or_null($decoded['PH'] ?? null);
$leitwertRaw = numeric_or_null($decoded['Leitwert'] ?? null);

function calculate_ph($phRaw, $temp1) {
    if (!is_numeric($phRaw) || !is_numeric($temp1)) {
        return null;
    }

    $ph_raw = floatval($phRaw);
    $temp1 = floatval($temp1);

    $ph = -(
        8.34290473e+02
        - ($ph_raw - 500)
        + (-8.46743264e+01 + $temp1) * 4.86429048
    ) * (
        4.68792704e-05 * (2.02571719e+02 + $temp1)
    ) + (
        3.24354493e-02
    ) * (
        -5.82350285e+01 + $temp1
    );

    return $ph;
}

function calculate_tds($leitwertRaw, $temp1) {
    if (!is_numeric($leitwertRaw) || !is_numeric($temp1)) {
        return null;
    }

    $leitwertRaw = floatval($leitwertRaw);
    $temp1 = floatval($temp1);

    $voltage = $leitwertRaw * 3.3 / 2400;
    $compensationCoefficient = 1.0 + 0.02 * ($temp1 - 25.0);
    $compensationVoltage = $voltage / $compensationCoefficient;

    $tds = (
        133.42 * $compensationVoltage * $compensationVoltage * $compensationVoltage
        - 255.86 * $compensationVoltage * $compensationVoltage
        + 857.39 * $compensationVoltage
    ) * 0.5;

    return $tds;
}

$temp1 = clean_temperature($decoded['Temperatur_1'] ?? null);
$temp2 = clean_temperature($decoded['Temperatur_2'] ?? null);
$temp3 = clean_temperature($decoded['Temperatur_3'] ?? null);

$phRaw = numeric_or_null($decoded['PH'] ?? null);
$leitwertRaw = numeric_or_null($decoded['Leitwert'] ?? null);

$phCalculated = calculate_ph($phRaw, $temp1);
$tdsCalculated = calculate_tds($leitwertRaw, $temp1);

$measurement = [
    'deviceId' => $body['end_device_ids']['device_id'] ?? null,
    'updatedAt' => $body['received_at'] ?? $body['uplink_message']['received_at'] ?? gmdate('c'),

    'temperature' => [
	's1' => $temp1,
	's2' => $temp2,
	's3' => $temp3,    ],

	'ph' => $phCalculated,

	'tds' => $tdsCalculated,

     'raw' => [
    'ph' => $phRaw,
    'leitwert' => $leitwertRaw
	],

    'battery' => numeric_or_null($decoded['BatterieProzent'] ?? null),
    'rssi' => numeric_or_null($rx['rssi'] ?? null),
    'snr' => numeric_or_null($rx['snr'] ?? null),

    'airTemperature' => numeric_or_null($decoded['Air_Temperature'] ?? null),
    'humidity' => numeric_or_null($decoded['Humidity'] ?? null),
    'pressure' => numeric_or_null($decoded['Pressure'] ?? null)
];

$dataDir = __DIR__ . '/../../data';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

file_put_contents(
    $dataDir . '/latest.json',
    json_encode($measurement, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

$historyEntry = [
    'timestamp' => $measurement['updatedAt'],
    'date' => substr($measurement['updatedAt'], 0, 10),
    's1' => $measurement['temperature']['s1'],
    's2' => $measurement['temperature']['s2'],
    's3' => $measurement['temperature']['s3'],
    'ph' => $measurement['ph'],
    'tds' => $measurement['tds']
];

file_put_contents(
    $dataDir . '/history.jsonl',
    json_encode($historyEntry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

http_response_code(204);
exit;
