<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Support\Tables;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        Schema::create(Tables::documents(), function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('source_key', 64);

            // String on purpose: accommodates int, uuid and ulid host keys.
            $table->string('external_id', 191);

            $table->string('title', 512)->nullable();
            $table->jsonb('metadata')->nullable();

            $table->unsignedInteger('segment_count')->default(0);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('embedded_chunk_count')->default(0);
            $table->unsignedInteger('token_count')->default(0);

            // sha256 of the concatenated normalized segments.
            $table->char('content_checksum', 64)->nullable();

            // sha256 of the effective chunking params + model + dimensions.
            $table->char('params_checksum', 64)->nullable();

            $table->string('status', 16)->default('pending');
            $table->timestamp('last_ingested_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique(['source_key', 'external_id']);
            $table->index(['source_key', 'status']);
            $table->index('content_checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::documents());
    }
};
