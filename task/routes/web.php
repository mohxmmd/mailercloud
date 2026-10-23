<?php

use App\Http\Controllers\SubmissionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['status' => 'ok', 'service' => 'Submission API']);
});

Route::post('/submissions', [SubmissionController::class, 'store']);
Route::get('/forms/{form_id}/submissions', [SubmissionController::class, 'indexByForm']);
Route::get('/forms/{form_id}/aggregates/hourly', [SubmissionController::class, 'hourlyAggregates']);
