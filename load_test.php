<?php
/**
 * Standalone Concurrent HTTP Load Tester (PHP cURL Multi)
 * Matches Part 2 Specifications:
 *   - Sends 100,000 requests (or custom via --total)
 *   - 20,000 duplicates (or custom via --duplicates)
 *   - Reports stored submissions (201), duplicates (200), errors, and throughput (RPS).
 */

$options = getopt('', [
    'url:',
    'total:',
    'duplicates:',
    'concurrency:',
    'form-id:',
    'report-interval:',
]);

$url = $options['url'] ?? 'http://127.0.0.1:8000/api/submissions';
$total = (int) ($options['total'] ?? 100000);
$duplicates = (int) ($options['duplicates'] ?? 20000);
$concurrency = (int) ($options['concurrency'] ?? 50);
$formId = $options['form-id'] ?? ('load-curl-' . substr(md5(uniqid()), 0, 6));
$reportInterval = (int) ($options['report-interval'] ?? 5000);

$uniqueCount = $total - $duplicates;
if ($uniqueCount <= 0) {
    fwrite(STDERR, "Error: Total requests must exceed duplicate count.\n");
    exit(1);
}

echo "======================================================================\n";
echo " MAILERCLOUD STANDALONE HTTP LOAD TEST (PHP cURL Multi)\n";
echo "======================================================================\n";
echo " Target Endpoint:    {$url}\n";
echo " Total Requests:     " . number_format($total) . "\n";
echo " Unique Submissions: " . number_format($uniqueCount) . "\n";
echo " Duplicate Requests: " . number_format($duplicates) . "\n";
echo " Concurrency Level:  {$concurrency} parallel connections\n";
echo " Form ID:            {$formId}\n";
echo "======================================================================\n\n";

echo "Generating {$total} JSON payloads...\n";
$uniqueKeys = [];
for ($i = 0; $i < $uniqueCount; $i++) {
    $uniqueKeys[] = 'k-' . bin2hex(random_bytes(5)) . "-{$i}";
}

$dupKeys = [];
for ($i = 0; $i < $duplicates; $i++) {
    $dupKeys[] = $uniqueKeys[array_rand($uniqueKeys)];
}

$uniques = [];
foreach ($uniqueKeys as $idx => $k) {
    $uniques[] = json_encode([
        'form_id' => $formId,
        'idempotency_key' => $k,
        'email' => "user_{$idx}@mailercloud.local",
        'data' => ['seq' => $idx, 'kind' => 'unique'],
    ]);
}

$dups = [];
foreach ($dupKeys as $idx => $k) {
    $dups[] = json_encode([
        'form_id' => $formId,
        'idempotency_key' => $k,
        'email' => "dup_{$idx}@mailercloud.local",
        'data' => ['seq' => $idx, 'kind' => 'duplicate'],
    ]);
}

$warmupCount = min(count($uniques), max(50, (int)($uniqueCount * 0.2)));
$firstBatch = array_slice($uniques, 0, $warmupCount);
$rest = array_merge(array_slice($uniques, $warmupCount), $dups);
shuffle($rest);
$allPayloads = array_merge($firstBatch, $rest);
$payloadCount = count($allPayloads);

echo "Payload generation complete. Starting HTTP requests...\n\n";

$status201 = 0;
$status200 = 0;
$status400 = 0;
$statusOther = 0;
$networkErrors = 0;
$completed = 0;
$latencies = [];

$mh = curl_multi_init();
$handles = [];
$payloadIdx = 0;
$startTime = microtime(true);

$addHandle = function (string $json) use ($mh, $url, &$handles) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: MailerCloud-CurlMultiLoad/1.0',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    curl_multi_add_handle($mh, $ch);
    $key = (int) $ch;
    $handles[$key] = [
        'ch' => $ch,
        'start' => microtime(true),
    ];
};

while ($payloadIdx < $payloadCount && count($handles) < $concurrency) {
    $addHandle($allPayloads[$payloadIdx++]);
}

do {
    $status = curl_multi_exec($mh, $active);
    if ($active) {
        curl_multi_select($mh, 0.05);
    }

    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $key = (int) $ch;
        $latency = (microtime(true) - $handles[$key]['start']) * 1000.0;
        $latencies[] = $latency;

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode === 201) {
            $status201++;
        } elseif ($httpCode === 200) {
            $status200++;
        } elseif ($httpCode === 400) {
            $status400++;
        } elseif ($httpCode > 0) {
            $statusOther++;
        } else {
            $networkErrors++;
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($handles[$key]);
        $completed++;

        if ($payloadIdx < $payloadCount) {
            $addHandle($allPayloads[$payloadIdx++]);
        }

        if ($completed % $reportInterval === 0 || $completed === $payloadCount) {
            $elapsed = microtime(true) - $startTime;
            $rps = $elapsed > 0 ? $completed / $elapsed : 0;
            $pct = ($completed / $payloadCount) * 100.0;
            $errors = $status400 + $statusOther + $networkErrors;

            printf(
                "[%7s/%7s] (%5.1f%%) | 201 Created: %s | 200 Dups: %s | Errors: %d | RPS: %6.1f | Elapsed: %5.1fs\n",
                number_format($completed),
                number_format($payloadCount),
                $pct,
                number_format($status201),
                number_format($status200),
                $errors,
                $rps,
                $elapsed
            );
        }
    }
} while ($active || !empty($handles));

curl_multi_close($mh);

$totalTime = microtime(true) - $startTime;
$totalErrors = $status400 + $statusOther + $networkErrors;
$throughput = $totalTime > 0 ? $completed / $totalTime : 0;

sort($latencies);
$n = count($latencies);
$avg = $n > 0 ? array_sum($latencies) / $n : 0;
$p50 = $n > 0 ? $latencies[(int)($n * 0.50)] : 0;
$p95 = $n > 0 ? $latencies[(int)($n * 0.95)] : 0;
$p99 = $n > 0 ? $latencies[(int)($n * 0.99)] : 0;

echo "\n======================================================================\n";
echo "                     LOAD TEST REPORT SUMMARY\n";
echo "======================================================================\n";
echo " Target Endpoint:               {$url}\n";
echo " Total Requests Sent:           " . number_format($completed) . "\n";
echo " Submissions Stored (201):      " . number_format($status201) . "\n";
echo " Duplicates Handled (200):      " . number_format($status200) . "\n";
echo " Total Errors Occurred:         " . number_format($totalErrors) . "\n";
echo " Total Time Elapsed:            " . sprintf("%.2f seconds (%.2f min)", $totalTime, $totalTime / 60) . "\n";
echo " Throughput (Requests/Sec):     " . sprintf("%.2f RPS", $throughput) . "\n";
echo "----------------------------------------------------------------------\n";
echo " Latencies:\n";
echo "   - Avg: " . sprintf("%.2f ms", $avg) . "\n";
echo "   - p50: " . sprintf("%.2f ms", $p50) . "\n";
echo "   - p95: " . sprintf("%.2f ms", $p95) . "\n";
echo "   - p99: " . sprintf("%.2f ms", $p99) . "\n";
echo "======================================================================\n";
