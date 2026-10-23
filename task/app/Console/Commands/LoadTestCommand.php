<?php

namespace App\Console\Commands;

use App\Models\Submission;
use App\Services\SubmissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoadTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'submissions:load-test
                            {--total=100000 : Total requests to send}
                            {--duplicates=20000 : Number of duplicate requests}
                            {--chunk=2000 : Batch size for progress reporting}
                            {--form-id= : Custom form_id (defaults to unique run id)}
                            {--truncate : Truncate submissions and outbox_events tables before test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Part 2 Load Test: 100,000 requests (80,000 unique + 20,000 duplicates) and report metrics';

    /**
     * Execute the console command.
     */
    public function handle(SubmissionService $submissionService): int
    {
        $totalRequests = (int) $this->option('total');
        $duplicateCount = (int) $this->option('duplicates');
        $uniqueCount = $totalRequests - $duplicateCount;
        $chunkSize = (int) $this->option('chunk');
        $runId = Str::random(6);
        $formId = $this->option('form-id') ?: 'load-test-' . $runId;

        if ($duplicateCount > $totalRequests) {
            $this->error('Duplicate count cannot exceed total requests.');
            return Command::FAILURE;
        }

        $this->info("==========================================================");
        $this->info("      MAILERCLOUD LOAD TEST — PART 2 SPECIFICATION        ");
        $this->info("==========================================================");
        $this->info("Total Requests:  " . number_format($totalRequests));
        $this->info("Unique Keys:     " . number_format($uniqueCount));
        $this->info("Duplicate Keys:  " . number_format($duplicateCount));
        $this->info("Form ID:         {$formId}");
        $this->info("==========================================================");

        if ($this->option('truncate')) {
            $this->warn('Truncating submissions and outbox_events tables...');
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('submissions')->truncate();
            DB::table('outbox_events')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->info('Tables truncated successfully.');
        }

        $initialFormCount = Submission::where('form_id', $formId)->count();
        $this->info("Initial Submissions in DB for {$formId}: {$initialFormCount}");
        $this->info("Generating workload and executing requests...");

        $startTime = microtime(true);

        $storedCount = 0;
        $duplicateHandledCount = 0;
        $errorCount = 0;

        $uniqueKeys = [];
        for ($i = 0; $i < $uniqueCount; $i++) {
            $uniqueKeys[] = 'load-' . $runId . '-' . $i . '-' . Str::random(6);
        }

        // Duplicate pool: exactly $duplicateCount items sampled from $uniqueKeys
        $duplicateKeys = [];
        for ($i = 0; $i < $duplicateCount; $i++) {
            $duplicateKeys[] = $uniqueKeys[$i % $uniqueCount];
        }

        // Mix: warmup uniques first, then shuffle the rest with duplicates
        $warmupCount = min(count($uniqueKeys), max(100, (int)($uniqueCount * 0.2)));
        $firstBatch = array_slice($uniqueKeys, 0, $warmupCount);
        $remaining = array_merge(array_slice($uniqueKeys, $warmupCount), $duplicateKeys);
        shuffle($remaining);
        $allKeys = array_merge($firstBatch, $remaining);

        $bar = $this->output->createProgressBar($totalRequests);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $bar->setMessage('Executing...');
        $bar->start();

        foreach ($allKeys as $index => $key) {
            $payload = [
                'form_id' => $formId,
                'idempotency_key' => $key,
                'email' => 'loadtest_' . ($index % 5000) . '@mailercloud.test',
                'data' => [
                    'source' => 'load_test',
                    'seq' => $index,
                ],
            ];

            try {
                [$submission, $isNewlyCreated] = $submissionService->storeSubmission($payload);

                if ($isNewlyCreated) {
                    $storedCount++;
                } else {
                    $duplicateHandledCount++;
                }
            } catch (\Throwable $e) {
                $errorCount++;
            }

            if (($index + 1) % $chunkSize === 0) {
                $bar->advance($chunkSize);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $endTime = microtime(true);
        $durationSeconds = max(0.001, $endTime - $startTime);
        $throughput = round($totalRequests / $durationSeconds, 2);

        $finalFormCount = Submission::where('form_id', $formId)->count();
        $actualStoredInDb = $finalFormCount - $initialFormCount;

        $this->info("==========================================================");
        $this->info("               LOAD TEST REPORT RESULTS                   ");
        $this->info("==========================================================");
        $this->table(
            ['Metric', 'Expected', 'Actual Result', 'Status'],
            [
                ['Total Requests Sent', number_format($totalRequests), number_format($totalRequests), 'OK'],
                ['Submissions Stored (201)', number_format($uniqueCount), number_format($actualStoredInDb), $actualStoredInDb === $uniqueCount ? 'PASS' : 'WARN'],
                ['Duplicates Handled (200)', number_format($duplicateCount), number_format($duplicateHandledCount), $duplicateHandledCount === $duplicateCount ? 'PASS' : 'WARN'],
                ['Errors Occurred', '0', (string) $errorCount, $errorCount === 0 ? 'PASS' : 'FAIL'],
                ['Total Time Taken', '-', round($durationSeconds, 2) . ' seconds', 'INFO'],
                ['Throughput', '-', number_format($throughput, 2) . ' req/sec', 'INFO'],
            ]
        );
        $this->info("==========================================================");

        return $errorCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
