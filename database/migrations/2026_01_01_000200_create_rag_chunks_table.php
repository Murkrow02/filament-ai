<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Support\Tables;

/**
 * Everything about a chunk except the vector payload, which the driver-aware
 * migration that runs after this one adds.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        $documents = Tables::documents();

        Schema::create(Tables::chunks(), function (Blueprint $table) use ($documents): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('document_id');

            // Denormalized so the hot filter never needs a join.
            $table->string('source_key', 64);

            $table->unsignedInteger('ordinal');

            // Real indexed columns, not JSON metadata: page-range filtering is
            // a first-class feature and the overlap test must be an index scan.
            $table->unsignedInteger('position_start');
            $table->unsignedInteger('position_end');

            // Offsets into the virtual (normalized, concatenated) document.
            $table->unsignedInteger('char_start')->default(0);
            $table->unsignedInteger('char_end')->default(0);

            $table->text('content');

            // sha256 of the embedding input, not of `content` -- see ChunkDraft.
            $table->char('content_hash', 64);

            $table->unsignedSmallInteger('token_count')->default(0);

            $table->string('embedding_model', 128)->nullable();
            $table->unsignedSmallInteger('embedding_dimensions')->nullable();

            // NULL means "waiting to be embedded".
            $table->timestamp('embedded_at')->nullable();

            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->foreign('document_id')->references('id')->on($documents)->cascadeOnDelete();

            $table->unique(['document_id', 'ordinal']);
            $table->index(['source_key', 'position_start', 'position_end']);
            $table->index(['document_id', 'position_start']);
            $table->index('content_hash');
            $table->index(['source_key', 'embedded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::chunks());
    }
};
