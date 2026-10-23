<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubmissionRequest;
use App\Models\HourlySubmissionAggregate;
use App\Models\Submission;
use App\Services\SubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubmissionController extends Controller
{
    public function __construct(
        protected SubmissionService $submissionService
    ) {}

    /**
     * Store a new form submission.
     */
    public function store(StoreSubmissionRequest $request): JsonResponse
    {
        [$submission, $isNewlyCreated] = $this->submissionService->storeSubmission($request->validated());

        return response()->json($submission, $isNewlyCreated ? 201 : 200);
    }

    /**
     * List submissions for a specific form with pagination (10 per page).
     */
    public function indexByForm(Request $request, string $form_id): JsonResponse
    {
        if (trim($form_id) === '') {
            return response()->json([
                'message' => 'form_id cannot be empty',
            ], 400);
        }

        // Validate page parameter if provided
        if ($request->has('page')) {
            $pageVal = $request->query('page');
            if (!ctype_digit((string) $pageVal) || (int) $pageVal < 1) {
                return response()->json([
                    'message' => 'Invalid page parameter. Must be a positive integer.',
                ], 400);
            }
        }

        $perPage = 10;
        $submissions = Submission::where('form_id', $form_id)
            ->orderBy('id', 'asc')
            ->paginate($perPage);

        return response()->json([
            'data' => $submissions->items(),
            'meta' => [
                'current_page' => $submissions->currentPage(),
                'per_page' => $submissions->perPage(),
                'total' => $submissions->total(),
                'last_page' => $submissions->lastPage(),
            ],
        ]);
    }

    /**
     * List hourly aggregates for a specific form.
     */
    public function hourlyAggregates(string $form_id): JsonResponse
    {
        if (trim($form_id) === '') {
            return response()->json([
                'message' => 'form_id cannot be empty',
            ], 400);
        }

        $aggregates = HourlySubmissionAggregate::where('form_id', $form_id)
            ->orderBy('hour_start', 'asc')
            ->get();

        return response()->json([
            'form_id' => $form_id,
            'aggregates' => $aggregates,
        ]);
    }
}
