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
                            {--truncate : Truncate submissions and outbox table before test}';

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
        $this->info("==========================================================");

        if ($this->option('truncate')) {
            $this->warn('Truncating submissions and outbox_events tables...');
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('submissions')->truncate();
            DB::table('outbox_events')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            $this->info('Tables truncated successfully.');
        }

        $startDbCount = Submission::count();
        $this->info("Initial Submissions in DB: {$startDbCount}");
        $this->info("Generating workload and executing requests...");

        // Generate the 80,000 unique keys
        // We will repeat keys from this set for the 20,000 duplicates
        $startTime = microtime(true);

        $storedCount = 0;
        $duplicateHandledCount = 0;
        $errorCount = 0;

        $uniqueKeys = [];
        // Pre-generate unique keys
        for ($i = 0; $i < $uniqueCount; $i++) {
            $uniqueKeys[] = 'load-key-' . $i . '-' . Str::random(8);
        }

        // Build list of all 100,000 request keys
        // First, add all 80,000 unique keys
        $allKeys = $uniqueKeys;

        // Then randomly pick 20,000 keys from the unique set to reuse
        for ($i = 0; $i < $duplicateCount; $i++) {
            $duplicateKey = $uniqueKeys[$i % $uniqueCount];
            $allKeys[] = $duplicateKey;
        }

        // Shuffle so duplicates are distributed throughout the stream
        shuffle($allKeys);

        $bar = $this->output->createProgressBar($totalRequests);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $bar->setMessage('Running...');
        $bar->start();

        foreach ($allKeys as $index => $key) {
            $payload = [
                'form_id' => 'lead-capture-form',
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

        $finalDbCount = Submission::count();
        $actualStoredInDb = $finalDbCount - $startDbCount;

        $this->info("==========================================================");
        $this->info("               LOAD TEST REPORT RESULTS                   ");
        $this->info("==========================================================");
        $this->table(
            ['Metric', 'Expected', 'Actual Result', 'Status'],
            [
                ['Total Requests Sent', number_format($totalRequests), number_format($totalRequests), 'OK'],
                ['Submissions Stored', number_format($uniqueCount), number_format($actualStoredInDb), $actualStoredInDb === $uniqueCount ? 'PASS' : 'WARN'],
                ['Duplicates Handled / Returned', number_format($duplicateCount), number_format($duplicateHandledCount), $duplicateHandledCount === $duplicateCount ? 'PASS' : 'WARN'],
                ['Errors Occurred', '0', (string) $errorCount, $errorCount === 0 ? 'PASS' : 'FAIL'],
                ['Total Time Taken', '-', round($durationSeconds, 2) . ' seconds', 'INFO'],
                ['Throughput', '-', number_format($throughput, 2) . ' req/sec', 'INFO'],
            ]
        );
        $this->info("==========================================================");

        return $errorCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
