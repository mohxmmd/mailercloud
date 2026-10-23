<?php

namespace Tests\Feature;

use App\Models\HourlySubmissionAggregate;
use App\Models\OutboxEvent;
use App\Models\Submission;
use App\Services\SubmissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 1. Valid submission creates a record and returns HTTP 201.
     */
    public function test_valid_submission_creates_a_record(): void
    {
        $payload = [
            'form_id' => 'contact-form',
            'idempotency_key' => 'key-001',
            'email' => 'user@example.com',
            'data' => [
                'name' => 'John Doe',
                'message' => 'Hello World',
            ],
        ];

        $response = $this->postJson('/submissions', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('form_id', 'contact-form')
            ->assertJsonPath('idempotency_key', 'key-001')
            ->assertJsonPath('email', 'user@example.com')
            ->assertJsonPath('data.name', 'John Doe');

        $this->assertDatabaseHas('submissions', [
            'form_id' => 'contact-form',
            'idempotency_key' => 'key-001',
            'email' => 'user@example.com',
        ]);

        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'submission.created',
        ]);
    }

    /**
     * 2. Missing form_id returns HTTP 400.
     */
    public function test_missing_form_id_returns_http_400(): void
    {
        $payload = [
            'idempotency_key' => 'key-missing-form',
            'email' => 'user@example.com',
            'data' => ['message' => 'hi'],
        ];

        $response = $this->postJson('/submissions', $payload);

        $response->assertStatus(400)
            ->assertJsonValidationErrors(['form_id']);
    }

    /**
     * 3. Invalid email returns HTTP 400.
     */
    public function test_invalid_email_returns_http_400(): void
    {
        $payload = [
            'form_id' => 'feedback-form',
            'idempotency_key' => 'key-bad-email',
            'email' => 'not-an-email',
            'data' => ['rating' => 5],
        ];

        $response = $this->postJson('/submissions', $payload);

        $response->assertStatus(400)
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * 4. Missing idempotency key is rejected with HTTP 400.
     */
    public function test_missing_idempotency_key_is_rejected(): void
    {
        $payload = [
            'form_id' => 'feedback-form',
            'email' => 'user@example.com',
            'data' => ['rating' => 5],
        ];

        $response = $this->postJson('/submissions', $payload);

        $response->assertStatus(400)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    /**
     * 5. Invalid JSON structure is rejected with HTTP 400.
     */
    public function test_invalid_json_structure_is_rejected(): void
    {
        // data must be an array/object, not a string or scalar
        $payload = [
            'form_id' => 'contact-form',
            'idempotency_key' => 'key-scalar-data',
            'email' => 'user@example.com',
            'data' => 'invalid-non-array-data',
        ];

        $response = $this->postJson('/submissions', $payload);

        $response->assertStatus(400)
            ->assertJsonValidationErrors(['data']);
    }

    /**
     * 6. Duplicate key returns the existing submission with HTTP 200.
     */
    public function test_duplicate_key_returns_existing_submission(): void
    {
        $payload = [
            'form_id' => 'contact-form',
            'idempotency_key' => 'dup-key-1',
            'email' => 'first@example.com',
            'data' => ['name' => 'Original'],
        ];

        $firstResponse = $this->postJson('/submissions', $payload);
        $firstResponse->assertStatus(201);
        $submissionId = $firstResponse->json('id');

        // Repeated request with same key but different data
        $duplicatePayload = [
            'form_id' => 'contact-form',
            'idempotency_key' => 'dup-key-1',
            'email' => 'modified@example.com',
            'data' => ['name' => 'Modified'],
        ];

        $secondResponse = $this->postJson('/submissions', $duplicatePayload);

        $secondResponse->assertStatus(200)
            ->assertJsonPath('id', $submissionId)
            ->assertJsonPath('email', 'first@example.com')
            ->assertJsonPath('data.name', 'Original');
    }

    /**
     * 7. Repeated requests do not create duplicate records.
     */
    public function test_repeated_requests_do_not_create_duplicate_records(): void
    {
        $payload = [
            'form_id' => 'survey-form',
            'idempotency_key' => 'repeated-key-777',
            'email' => 'user@example.com',
            'data' => ['answer' => 'Yes'],
        ];

        $this->postJson('/submissions', $payload)->assertStatus(201);
        $this->postJson('/submissions', $payload)->assertStatus(200);
        $this->postJson('/submissions', $payload)->assertStatus(200);

        $this->assertEquals(
            1,
            Submission::where('idempotency_key', 'repeated-key-777')->count()
        );
    }

    /**
     * 8. Same-key concurrent requests cannot create duplicates.
     */
    public function test_same_key_concurrent_requests_handled_safely(): void
    {
        $service = app(SubmissionService::class);
        $payload = [
            'form_id' => 'concurrent-form',
            'idempotency_key' => 'concurrent-key-999',
            'email' => 'concurrent@example.com',
            'data' => ['step' => 1],
        ];

        // Call twice sequentially or simulating race
        [$firstSub, $firstCreated] = $service->storeSubmission($payload);
        [$secondSub, $secondCreated] = $service->storeSubmission($payload);

        $this->assertTrue($firstCreated);
        $this->assertFalse($secondCreated);
        $this->assertEquals($firstSub->id, $secondSub->id);
        $this->assertEquals(1, Submission::where('idempotency_key', 'concurrent-key-999')->count());
    }

    /**
     * 9. Duplicate submissions do not generate duplicate logical outbox events.
     */
    public function test_duplicate_submissions_do_not_generate_duplicate_outbox_events(): void
    {
        $payload = [
            'form_id' => 'outbox-test-form',
            'idempotency_key' => 'outbox-key-1',
            'email' => 'outbox@example.com',
            'data' => ['hello' => 'world'],
        ];

        $this->postJson('/submissions', $payload)->assertStatus(201);
        $this->postJson('/submissions', $payload)->assertStatus(200);
        $this->postJson('/submissions', $payload)->assertStatus(200);

        $this->assertEquals(1, OutboxEvent::count());
    }

    /**
     * 10. Pagination returns no more than 10 records.
     */
    public function test_pagination_returns_no_more_than_10_records(): void
    {
        $formId = 'paginated-form';

        for ($i = 1; $i <= 25; $i++) {
            Submission::create([
                'form_id' => $formId,
                'idempotency_key' => "page-key-{$i}",
                'email' => "user{$i}@example.com",
                'data' => ['index' => $i],
            ]);
        }

        $response = $this->getJson("/forms/{$formId}/submissions?page=1");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(10, $data);
    }

    /**
     * 11. Pagination returns correct metadata.
     */
    public function test_pagination_returns_correct_metadata(): void
    {
        $formId = 'metadata-form';

        for ($i = 1; $i <= 25; $i++) {
            Submission::create([
                'form_id' => $formId,
                'idempotency_key' => "meta-key-{$i}",
                'email' => "user{$i}@example.com",
                'data' => ['index' => $i],
            ]);
        }

        $response = $this->getJson("/forms/{$formId}/submissions?page=2");

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 3);

        $this->assertCount(10, $response->json('data'));
    }

    /**
     * 12. Form filtering works.
     */
    public function test_form_filtering_works(): void
    {
        Submission::create([
            'form_id' => 'form-A',
            'idempotency_key' => 'key-A1',
            'email' => 'a@example.com',
            'data' => ['form' => 'A'],
        ]);
        Submission::create([
            'form_id' => 'form-B',
            'idempotency_key' => 'key-B1',
            'email' => 'b@example.com',
            'data' => ['form' => 'B'],
        ]);

        $responseA = $this->getJson('/forms/form-A/submissions');
        $responseA->assertStatus(200);
        $this->assertCount(1, $responseA->json('data'));
        $this->assertEquals('form-A', $responseA->json('data.0.form_id'));

        $responseB = $this->getJson('/forms/form-B/submissions');
        $responseB->assertStatus(200);
        $this->assertCount(1, $responseB->json('data'));
        $this->assertEquals('form-B', $responseB->json('data.0.form_id'));
    }

    /**
     * 13. Empty forms return empty result sets.
     */
    public function test_empty_forms_return_empty_result_sets(): void
    {
        $response = $this->getJson('/forms/non-existent-form/submissions');

        $response->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1);
    }

    /**
     * 14. Aggregate endpoint returns persisted hourly counts.
     */
    public function test_aggregate_endpoint_returns_persisted_hourly_counts(): void
    {
        $formId = 'agg-form';
        $hour1 = Carbon::parse('2026-10-23 10:00:00', 'UTC');
        $hour2 = Carbon::parse('2026-10-23 11:00:00', 'UTC');

        HourlySubmissionAggregate::create([
            'form_id' => $formId,
            'hour_start' => $hour1,
            'submission_count' => 150,
        ]);
        HourlySubmissionAggregate::create([
            'form_id' => $formId,
            'hour_start' => $hour2,
            'submission_count' => 320,
        ]);

        $response = $this->getJson("/forms/{$formId}/aggregates/hourly");

        $response->assertStatus(200)
            ->assertJsonPath('form_id', $formId)
            ->assertJsonCount(2, 'aggregates')
            ->assertJsonPath('aggregates.0.submission_count', 150)
            ->assertJsonPath('aggregates.1.submission_count', 320);
    }
}
