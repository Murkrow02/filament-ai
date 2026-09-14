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
        Schema::create(Tables::runs(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->string('source_key', 64);
            $table->string('status', 16)->default('queued');
            $table->string('mode', 20)->default('incremental');

            $table->jsonb('filters')->nullable();

            // Frozen at launch so a mid-flight config change cannot produce a
            // corpus chunked two different ways.
            $table->jsonb('chunking_params')->nullable();

            $table->string('embedding_model', 128);
            $table->unsignedSmallInteger('embedding_dimensions');
            $table->string('vector_driver', 32);

            $table->string('chunk_batch_id', 36)->nullable();
            $table->string('embed_batch_id', 36)->nullable();

            $table->unsignedInteger('documents_total')->default(0);
            $table->unsignedInteger('documents_done')->default(0);
            $table->unsignedInteger('documents_skipped')->default(0);
            $table->unsignedInteger('documents_failed')->default(0);

            $table->unsignedInteger('chunks_created')->default(0);
            $table->unsignedInteger('chunks_reused')->default(0);
            $table->unsignedInteger('chunks_deleted')->default(0);
            $table->unsignedInteger('chunks_total')->default(0);
            $table->unsignedInteger('chunks_embedded')->default(0);
            $table->unsignedInteger('chunks_failed')->default(0);

            $table->unsignedBigInteger('tokens_used')->default(0);

            // Micro-USD integers: summable in SQL without float drift.
            $table->unsignedBigInteger('cost_micros')->default(0);

            $table->unsignedInteger('api_calls')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();

            // No foreign key: the host's user table is unknown to the package.
            $table->string('created_by', 64)->nullable();

            $table->timestamps();

            $table->index(['source_key', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('chunk_batch_id');
            $table->index('embed_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::runs());
    }
};
