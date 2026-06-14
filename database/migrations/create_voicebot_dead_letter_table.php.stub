<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voicebot_dead_letter', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id');
            $table->string('entity_kind');
            $table->string('external_id')->nullable();
            $table->string('op_type')->default('upsert');
            $table->json('payload')->nullable();
            $table->text('error');
            $table->unsignedInteger('attempts')->default(0);
            // Set once attempts reach dead_letter_max_attempts — stops re-counting and
            // flags the op for manual inspection/replay rather than infinite re-parking.
            $table->boolean('exhausted')->default(false);
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index('batch_id');
            // One row per op identity; re-failures bump attempts instead of duplicating.
            $table->unique(['entity_kind', 'external_id', 'op_type'], 'voicebot_dead_letter_op_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voicebot_dead_letter');
    }
};
