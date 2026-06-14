<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voicebot_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->string('ingest_url');
            // Encrypted at rest via the model's `encrypted` cast; holds base64 of raw secret bytes.
            $table->text('secret_b64');
            $table->timestamp('paired_at')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voicebot_connections');
    }
};
