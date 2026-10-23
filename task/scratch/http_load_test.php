<?php

/**
 * High-performance concurrent HTTP load testing script using curl_multi.
 * 
 * Usage:
 *   php scratch/http_load_test.php [total_requests] [duplicate_count] [concurrency] [base_url]
 * 
 * Example:
 *   php scratch/http_load_test.php 1000 200 50 http://127.0.0.1:8000
 */

$totalRequests = isset($argv[1]) ? (int) $argv[1] : 1000;
$duplicates = isset($argv[2]) ? (int) $argv[2] : 200;
$concurrency = isset($argv[3]) ? (int) $argv[3] : 50;
$baseUrl = isset($argv[4]) ? rtrim($argv[4], '/') : 'http://127.0.0.1:8000';
$url = $baseUrl . '/submissions';

$uniqueCount = $totalRequests - $duplicates;

echo "==========================================================\n";
echo "       HTTP CONCURRENT LOAD TEST (cURL Multi)             \n";
echo "==========================================================\n";
echo "Target URL:       {$url}\n";
echo "Total Requests:   {$totalRequests}\n";
echo "Unique Keys:      {$uniqueCount}\n";
echo "Duplicate Keys:   {$duplicates}\n";
echo "Concurrency:      {$concurrency}\n";
echo "==========================================================\n\n";

// Generate unique keys
$uniqueKeys = [];
for ($i = 0; $i < $uniqueCount; $i++) {
    $uniqueKeys[] = 'http-load-' . $i . '-' . bin2hex(random_bytes(4));
}

// Generate the full sequence of keys
$allKeys = $uniqueKeys;
for ($i = 0; $i < $duplicates; $i++) {
    $allKeys[] = $uniqueKeys[$i % $uniqueCount];
}
shuffle($allKeys);

$mh = curl_multi_init();
$activeRequests = [];
$stats = [
    '201_created' => 0,
    '200_duplicate' => 0,
    '400_bad_request' => 0,
    '500_server_error' => 0,
    'other_status' => 0,
    'network_errors' => 0,
];

$keyIndex = 0;
$totalKeys = count($allKeys);
$completed = 0;
$startTime = microtime(true);

function createHandle($url, $key, $seq) {
    $ch = curl_init($url);
    $payload = json_encode([
        'form_id' => 'http-load-form',
        'idempotency_key' => $key,
        'email' => 'loaduser_' . ($seq % 200) . '@mailercloud.test',
        'data' => [
            'name' => 'Load Test User ' . $seq,
            'timestamp' => microtime(true)
        ]
    ]);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    return $ch;
}

// Prime the queue with initial concurrent requests
while ($keyIndex < $totalKeys && count($activeRequests) < $concurrency) {
    $ch = createHandle($url, $allKeys[$keyIndex], $keyIndex);
    curl_multi_add_handle($mh, $ch);
    $activeRequests[(int)$ch] = $allKeys[$keyIndex];
    $keyIndex++;
}

// Main event loop
$running = null;
do {
    curl_multi_exec($mh, $running);

    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = $info['result'];

        if ($curlErr !== CURLE_OK) {
            $stats['network_errors']++;
        } elseif ($httpCode === 201) {
            $stats['201_created']++;
        } elseif ($httpCode === 200) {
            $stats['200_duplicate']++;
        } elseif ($httpCode === 400) {
            $stats['400_bad_request']++;
        } elseif ($httpCode >= 500) {
            $stats['500_server_error']++;
        } else {
            $stats['other_status']++;
        }

        $completed++;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($activeRequests[(int)$ch]);

        // Add next request to maintain concurrency
        if ($keyIndex < $totalKeys) {
            $newCh = createHandle($url, $allKeys[$keyIndex], $keyIndex);
            curl_multi_add_handle($mh, $newCh);
            $activeRequests[(int)$newCh] = $allKeys[$keyIndex];
            $keyIndex++;
        }

        if ($completed % max(1, (int)($totalRequests / 10)) === 0 || $completed === $totalRequests) {
            $pct = round(($completed / $totalRequests) * 100);
            echo "Progress: {$completed}/{$totalRequests} ({$pct}%)\n";
        }
    }

    if ($running > 0) {
        curl_multi_select($mh, 0.05);
    }
} while ($running > 0 || count($activeRequests) > 0);

curl_multi_close($mh);

$endTime = microtime(true);
$duration = max(0.001, $endTime - $startTime);
$rps = round($totalRequests / $duration, 2);

echo "\n==========================================================\n";
echo "              HTTP LOAD TEST REPORT                       \n";
echo "==========================================================\n";
echo sprintf("%-30s: %s\n", "Total Requests", number_format($totalRequests));
echo sprintf("%-30s: %s\n", "201 Created (New Records)", number_format($stats['201_created']));
echo sprintf("%-30s: %s\n", "200 OK (Duplicates Handled)", number_format($stats['200_duplicate']));
echo sprintf("%-30s: %s\n", "400 Bad Requests", number_format($stats['400_bad_request']));
echo sprintf("%-30s: %s\n", "500 Server Errors", number_format($stats['500_server_error']));
echo sprintf("%-30s: %s\n", "Network/Connect Errors", number_format($stats['network_errors']));
echo sprintf("%-30s: %s s\n", "Total Duration", round($duration, 2));
echo sprintf("%-30s: %s req/sec\n", "Throughput", number_format($rps, 2));
echo "==========================================================\n";
