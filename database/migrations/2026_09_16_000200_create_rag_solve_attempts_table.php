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
        $runs = Tables::solveRuns();

        Schema::create(Tables::solveAttempts(), function (Blueprint $table) use ($runs): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('run_id');

            $table->unsignedSmallInteger('wave');
            $table->unsignedSmallInteger('position');
            $table->string('status', 16)->default('pending');

            $table->longText('answer')->nullable();
            $table->unsignedTinyInteger('score')->default(0);
            $table->text('reason')->nullable();
            $table->text('error')->nullable();

            // Which tools the attempt reached for, so a reader can tell a
            // lucky guess from a worked answer.
            $table->json('tool_calls')->nullable();

            $table->float('temperature')->nullable();
            $table->string('conversation_id', 36)->nullable();

            $table->unsignedBigInteger('tokens_used')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->foreign('run_id')->references('id')->on($runs)->cascadeOnDelete();
            $table->unique(['run_id', 'wave', 'position']);
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::solveAttempts());
    }
};
