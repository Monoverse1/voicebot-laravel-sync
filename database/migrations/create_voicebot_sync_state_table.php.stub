<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voicebot_sync_state', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_kind');
            $table->timestamp('watermark')->nullable();
            $table->timestamp('last_full_at')->nullable();
            $table->timestamps();
            $table->unique('entity_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voicebot_sync_state');
    }
};
