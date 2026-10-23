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
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->string('form_id', 128)->index();
            $table->string('idempotency_key', 191)->unique();
            $table->string('email', 255);
            $table->json('data');
            $table->timestamps();

            $table->index(['form_id', 'created_at', 'id'], 'submissions_form_created_id_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
