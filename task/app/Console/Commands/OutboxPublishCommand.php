<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class OutboxPublishCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbox:publish
                            {--batch=100 : Number of events to fetch per batch}
                            {--poll : Run continuously polling for new events}
                            {--interval=1000 : Poll interval in milliseconds when idle}
                            {--topic=submission-events : Kafka topic to publish to}
                            {--brokers= : Kafka bootstrap brokers}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish pending outbox events to Kafka';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $batch = (int) $this->option('batch');
        $poll = (bool) $this->option('poll');
        $interval = (int) $this->option('interval');
        $topic = (string) $this->option('topic');
        $brokers = $this->option('brokers');

        if (!$brokers) {
            $discovered = trim(shell_exec("wsl -u root -d Ubuntu -e bash -c \"DOCKER_HOST=unix:///var/run/docker.sock docker inspect mailercloud-kafka --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null\"") ?: '');
            $brokers = $discovered ? "{$discovered}:29092" : '172.18.0.2:29092';
        }

        $this->info("Launching Outbox Publisher (Kafka: {$brokers}, Topic: {$topic}, Batch: {$batch})...");

        $scriptPath = base_path('scripts/publish_outbox.py');
        $wslScriptPath = '/mnt/c/' . str_replace('\\', '/', str_replace('C:\\', '', $scriptPath));

        $args = [
            'wsl',
            '-u', 'root',
            '-d', 'Ubuntu',
            '-e', 'python3', $wslScriptPath,
            '--batch', (string) $batch,
            '--interval', (string) $interval,
            '--topic', $topic,
            '--brokers', $brokers,
        ];

        if ($poll) {
            $args[] = '--poll';
        }

        $process = new Process($args);
        $process->setTimeout(null);

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }
}
