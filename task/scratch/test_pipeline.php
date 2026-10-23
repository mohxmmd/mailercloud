<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\HourlySubmissionAggregate;
use App\Models\OutboxEvent;
use App\Models\Submission;
use App\Services\SubmissionService;
use Illuminate\Support\Facades\Artisan;

echo "=== 1. Creating First Submission ===\n";
$service = app(SubmissionService::class);
$payload = [
    'form_id' => 'e2e-contact-form',
    'idempotency_key' => 'e2e-test-key-001',
    'email' => 'alice@example.com',
    'data' => [
        'name' => 'Alice Wonderland',
        'subject' => 'Real-time Flink Test',
        'message' => 'Testing live aggregation'
    ]
];

[$sub1, $created1] = $service->storeSubmission($payload);
echo "Submission 1 Created: " . ($created1 ? 'YES' : 'NO') . " (ID: {$sub1->id})\n";

echo "\n=== 2. Publishing Outbox Events ===\n";
Artisan::call('outbox:publish', ['--topic' => 'submission-events']);
echo Artisan::output();

echo "\n=== 3. Waiting for Flink to process event and sink to MySQL (3 seconds) ===\n";
sleep(3);

$agg1 = HourlySubmissionAggregate::where('form_id', 'e2e-contact-form')->first();
echo "Aggregate submission_count in MySQL: " . ($agg1 ? $agg1->submission_count : 'NOT FOUND') . "\n";
if ($agg1) {
    echo "Hour start: " . $agg1->hour_start . "\n";
}

echo "\n=== 4. Sending Duplicate Idempotency Key ===\n";
[$sub2, $created2] = $service->storeSubmission($payload);
echo "Submission 2 Created: " . ($created2 ? 'YES' : 'NO') . " (ID: {$sub2->id})\n";

echo "\n=== 5. Publishing Outbox Again (Should have 0 new events) ===\n";
Artisan::call('outbox:publish', ['--topic' => 'submission-events']);
echo Artisan::output();

sleep(2);
$agg2 = HourlySubmissionAggregate::where('form_id', 'e2e-contact-form')->first();
echo "Aggregate submission_count in MySQL after duplicate: " . ($agg2 ? $agg2->submission_count : 'NOT FOUND') . "\n";

echo "\n=== 6. Sending Second Unique Submission to Same Form ===\n";
$payload2 = [
    'form_id' => 'e2e-contact-form',
    'idempotency_key' => 'e2e-test-key-002',
    'email' => 'bob@example.com',
    'data' => [
        'name' => 'Bob Builder',
        'message' => 'Second event'
    ]
];
[$sub3, $created3] = $service->storeSubmission($payload2);
echo "Submission 3 Created: " . ($created3 ? 'YES' : 'NO') . " (ID: {$sub3->id})\n";

Artisan::call('outbox:publish', ['--topic' => 'submission-events']);
echo Artisan::output();

sleep(3);
$agg3 = HourlySubmissionAggregate::where('form_id', 'e2e-contact-form')->first();
echo "Aggregate submission_count in MySQL after second submission: " . ($agg3 ? $agg3->submission_count : 'NOT FOUND') . "\n";
