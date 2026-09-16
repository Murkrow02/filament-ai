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
        Schema::create(Tables::solveRuns(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();

            $table->string('status', 16)->default('queued');
            $table->text('goal');
            $table->text('criteria')->nullable();
            $table->json('context')->nullable();

            // What the run was allowed to spend, kept with the run so a later
            // reader knows why it stopped without digging for the config that
            // was in force that day.
            $table->json('budgets')->nullable();

            $table->unsignedSmallInteger('wave')->default(0);
            $table->unsignedSmallInteger('waves_total')->default(0);
            $table->unsignedSmallInteger('attempts_per_wave')->default(0);
            $table->unsignedInteger('attempts_total')->default(0);
            $table->unsignedInteger('attempts_failed')->default(0);

            $table->unsignedBigInteger('best_attempt_id')->nullable();
            $table->unsignedTinyInteger('best_score')->default(0);
            $table->text('message')->nullable();

            $table->unsignedBigInteger('tokens_used')->default(0);
            // Integer micros: these get summed in SQL, where floats drift.
            $table->unsignedBigInteger('cost_micros')->default(0);

            $table->string('batch_id', 36)->nullable();
            $table->string('assistant', 191)->nullable();
            $table->string('created_by', 64)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::solveRuns());
    }
};
