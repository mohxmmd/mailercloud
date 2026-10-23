<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hourly_submission_aggregates', function (Blueprint $table) {
            $table->id();
            $table->string('form_id', 128);
            $table->dateTime('hour_start');
            $table->unsignedBigInteger('submission_count')->default(0);
            $table->timestamps();

            $table->unique(['form_id', 'hour_start'], 'form_hour_unique');
            $table->index(['form_id', 'hour_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hourly_submission_aggregates');
    }
};
