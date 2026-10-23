<?php

namespace App\Services;

use App\Models\OutboxEvent;
use App\Models\Submission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubmissionService
{
    /**
     * Store a submission idempotently.
     *
     * @param array $payload
     * @return array [Submission $submission, bool $isNewlyCreated]
     */
    public function storeSubmission(array $payload): array
    {
        $idempotencyKey = $payload['idempotency_key'];

        // Fast-path: Check if already stored
        $existing = Submission::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return [$existing, false];
        }

        try {
            return DB::transaction(function () use ($payload, $idempotencyKey) {
                // Secondary check inside transaction
                $existing = Submission::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return [$existing, false];
                }

                $submission = Submission::create([
                    'form_id' => $payload['form_id'],
                    'idempotency_key' => $idempotencyKey,
                    'email' => $payload['email'],
                    'data' => $payload['data'],
                ]);

                $eventId = (string) Str::uuid();

                OutboxEvent::create([
                    'event_id' => $eventId,
                    'event_type' => 'submission.created',
                    'aggregate_id' => (string) $submission->id,
                    'payload' => [
                        'event_id' => $eventId,
                        'event_type' => 'submission.created',
                        'submission_id' => $submission->id,
                        'form_id' => $submission->form_id,
                        'idempotency_key' => $submission->idempotency_key,
                        'email' => $submission->email,
                        'data' => $submission->data,
                        'created_at' => $submission->created_at->toISOString(),
                    ],
                ]);

                return [$submission, true];
            });
        } catch (QueryException $e) {
            // MySQL error 1062 / SQLSTATE 23000: Duplicate entry
            if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                $existing = Submission::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return [$existing, false];
                }
            }

            throw $e;
        }
    }
}
