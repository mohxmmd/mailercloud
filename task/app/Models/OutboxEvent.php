<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_id',
        'payload',
        'published_at',
        'retry_count',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'published_at' => 'datetime',
        'retry_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
