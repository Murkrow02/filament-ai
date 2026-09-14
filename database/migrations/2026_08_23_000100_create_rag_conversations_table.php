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
        Schema::create(Tables::conversations(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            // A string, like rag_queries.user_id: the package never assumes the
            // host's users table has an integer key, or that it exists at all.
            $table->string('user_id', 64)->nullable();

            $table->string('title', 200)->nullable();

            // Whatever the chat page let this user change: model, sources,
            // top_k, min_score. Kept per conversation so reopening one restores
            // the settings the answers were produced under.
            $table->jsonb('settings')->nullable();

            $table->boolean('pinned')->default(false);

            $table->unsignedInteger('turns')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);

            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::conversations());
    }
};
