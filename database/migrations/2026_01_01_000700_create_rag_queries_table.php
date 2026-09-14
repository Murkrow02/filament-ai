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
        Schema::create(Tables::queries(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->jsonb('source_keys')->nullable();
            $table->text('question');
            $table->char('question_hash', 64);

            $table->string('embedding_model', 128)->nullable();
            $table->string('llm_model', 128)->nullable();

            $table->jsonb('filters')->nullable();

            $table->unsignedSmallInteger('top_k')->default(0);
            $table->unsignedSmallInteger('retrieved_count')->default(0);
            $table->float('top_score')->nullable();
            $table->float('min_score')->nullable();

            $table->longText('answer')->nullable();
            $table->boolean('refused')->default(false);

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('embedding_tokens')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);

            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('retrieval_ms')->nullable();

            $table->string('channel', 24)->default('api');
            $table->string('user_id', 64)->nullable();

            // -1 / 0 / 1 -- thumbs down, neutral, thumbs up.
            $table->tinyInteger('feedback')->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index(['channel', 'created_at']);
            $table->index('question_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::queries());
    }
};
