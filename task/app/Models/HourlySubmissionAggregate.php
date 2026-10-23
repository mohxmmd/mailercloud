<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HourlySubmissionAggregate extends Model
{
    use HasFactory;

    protected $fillable = [
        'form_id',
        'hour_start',
        'submission_count',
    ];

    protected $casts = [
        'hour_start' => 'datetime',
        'submission_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
