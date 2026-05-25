<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dataDir = __DIR__ . '/../../data';

$latestFile = $dataDir . '/latest.json';
$historyFile = $dataDir . '/history.jsonl';

$latest = [
    'updatedAt' => gmdate('c'),
    'deviceId' => null,
    'temperature' => [
        's1' => null,
        's2' => null,
        's3' => null
    ],
    'ph' => null,
    'tds' => null,
    'battery' => null,
    'rssi' => null,
    'snr' => null
];

if (file_exists($latestFile)) {
    $loaded = json_decode(file_get_contents($latestFile), true);
    if (is_array($loaded)) {
        $latest = $loaded;
    }
}

$history = [];

if (file_exists($historyFile)) {
    $lines = file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    // Für den Anfang: letzte 5000 Messpunkte laden
    $lines = array_slice($lines, -5000);

    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry)) {
            $history[] = $entry;
        }
    }
}

/**
 * Berechnet einen gleitenden Mittelwert über die letzten gültigen Werte.
 *
 * Beispiel:
 * moving_average($history, 'ph', 6)
 * nimmt die letzten 6 numerischen pH-Werte aus der Historie.
 */
function moving_average($history, $key, $count = 6) {
    if (!is_array($history) || count($history) === 0) {
        return null;
    }

    $values = [];

    for ($i = count($history) - 1; $i >= 0; $i--) {
        if (isset($history[$i][$key]) && is_numeric($history[$i][$key])) {
            $values[] = floatval($history[$i][$key]);
        }

        if (count($values) >= $count) {
            break;
        }
    }

    if (count($values) === 0) {
        return null;
    }

    return array_sum($values) / count($values);
}

$averageWindow = 6;

// Ungeglättete letzte Werte zur Kontrolle behalten
$latest['phLatest'] = $latest['ph'] ?? null;
$latest['tdsLatest'] = $latest['tds'] ?? null;

// Geglättete Werte aus der Historie berechnen
$phAverage = moving_average($history, 'ph', $averageWindow);
$tdsAverage = moving_average($history, 'tds', $averageWindow);

// Durchschnittswerte zusätzlich ausgeben
$latest['phAverage'] = $phAverage;
$latest['tdsAverage'] = $tdsAverage;
$latest['averageWindow'] = $averageWindow;

// Für das Dashboard: ph und tds auf geglättete Werte setzen,
// aber nur wenn genug gültige Werte vorhanden sind.
if ($phAverage !== null) {
    $latest['ph'] = $phAverage;
}

if ($tdsAverage !== null) {
    $latest['tds'] = $tdsAverage;
}

function is_valid_temperature_value($value) {
    if (!is_numeric($value)) {
        return false;
    }

    $n = floatval($value);

    // Allgemeiner physikalisch plausibler Bereich.
    // Werte außerhalb dieses Bereichs werden immer entfernt.
    return $n >= -2 && $n <= 40;
}

function median_value($values) {
    $clean = [];

    foreach ($values as $value) {
        if (is_numeric($value)) {
            $clean[] = floatval($value);
        }
    }

    $count = count($clean);

    if ($count === 0) {
        return null;
    }

    sort($clean, SORT_NUMERIC);

    $middle = intdiv($count, 2);

    if ($count % 2 === 1) {
        return $clean[$middle];
    }

    return ($clean[$middle - 1] + $clean[$middle]) / 2;
}

function local_median_for_index($history, $key, $index, $radius = 4) {
    $values = [];
    $count = count($history);

    for ($i = $index - $radius; $i <= $index + $radius; $i++) {
        if ($i < 0 || $i >= $count || $i === $index) {
            continue;
        }

        if (isset($history[$i][$key]) && is_valid_temperature_value($history[$i][$key])) {
            $values[] = floatval($history[$i][$key]);
        }
    }

    return median_value($values);
}

function clean_temperature_history($history, $keys = ['s1', 's2', 's3']) {
    if (!is_array($history) || count($history) === 0) {
        return [];
    }

    $cleaned = $history;
    $count = count($history);

    // Einstellungen
    $maxDeviationFromLocalMedian = 2.5; // °C Abweichung vom lokalen Median
    $maxJumpFromStableValue = 2.5;      // °C Sprung vom letzten stabilen Wert
    $lowValueThreshold = 5.0;           // Werte darunter sind verdächtig, wenn Umgebung deutlich wärmer ist
    $warmEnvironmentThreshold = 8.0;    // Umgebung gilt als "warm", wenn Median darüber liegt

    foreach ($keys as $key) {
        $lastStable = null;

        for ($i = 0; $i < $count; $i++) {
            $currentRaw = $history[$i][$key] ?? null;

            if (!is_valid_temperature_value($currentRaw)) {
                $cleaned[$i][$key] = null;
                continue;
            }

            $current = floatval($currentRaw);
            $localMedian = local_median_for_index($history, $key, $i, 4);

            $isOutlier = false;

            // Regel 1:
            // Wert weicht stark vom lokalen Median ab.
            // Das erkennt Einzel-Ausreißer und kurze Ausreißergruppen besser
            // als nur der direkte Vorher/Nachher-Vergleich.
            if ($localMedian !== null && abs($current - $localMedian) > $maxDeviationFromLocalMedian) {
                $isOutlier = true;
            }

            // Regel 2:
            // Plötzlicher Absturz nach unten in eigentlich warmer Umgebung.
            // Beispiel: Umgebung ca. 18 °C, Messwert fällt kurz auf 0 °C.
            if (
                $localMedian !== null &&
                $localMedian >= $warmEnvironmentThreshold &&
                $current < $lowValueThreshold &&
                ($localMedian - $current) > $maxDeviationFromLocalMedian
            ) {
                $isOutlier = true;
            }

            // Regel 3:
            // Vergleich mit dem letzten stabilen Wert.
            // Dadurch werden auch kurze Serien wie 18 → 0 → 0 → 18 entfernt.
            if (
                $lastStable !== null &&
                abs($current - $lastStable) > $maxJumpFromStableValue
            ) {
                // Wenn der lokale Median eher beim letzten stabilen Wert liegt,
                // ist der aktuelle Wert sehr wahrscheinlich ein Ausreißer.
                if ($localMedian === null || abs($localMedian - $lastStable) <= $maxDeviationFromLocalMedian) {
                    $isOutlier = true;
                }
            }

            if ($isOutlier) {
                $cleaned[$i][$key] = null;
                continue;
            }

            // Nur nicht-ausreißende Werte werden als stabiler Referenzwert verwendet.
            $lastStable = $current;
        }
    }

    return $cleaned;
}

function last_valid_temperature_from_history($history, $key) {
    if (!is_array($history)) {
        return null;
    }

    for ($i = count($history) - 1; $i >= 0; $i--) {
        if (isset($history[$i][$key]) && is_numeric($history[$i][$key])) {
            return floatval($history[$i][$key]);
        }
    }

    return null;
}

$displayHistory = clean_temperature_history($history);

// Bereinigte History ans Dashboard geben
$latest['history'] = $displayHistory;

// Auch die großen aktuellen Temperaturkarten gegen aktuelle Ausreißer schützen.
// Falls der neueste Wert ein Ausreißer war, wird der letzte gültige Wert aus der
// bereinigten History angezeigt.
$lastS1 = last_valid_temperature_from_history($displayHistory, 's1');
$lastS2 = last_valid_temperature_from_history($displayHistory, 's2');
$lastS3 = last_valid_temperature_from_history($displayHistory, 's3');

if (!isset($latest['temperature']) || !is_array($latest['temperature'])) {
    $latest['temperature'] = ['s1' => null, 's2' => null, 's3' => null];
}

if ($lastS1 !== null) {
    $latest['temperature']['s1'] = $lastS1;
}

if ($lastS2 !== null) {
    $latest['temperature']['s2'] = $lastS2;
}

if ($lastS3 !== null) {
    $latest['temperature']['s3'] = $lastS3;
}

echo json_encode($latest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
