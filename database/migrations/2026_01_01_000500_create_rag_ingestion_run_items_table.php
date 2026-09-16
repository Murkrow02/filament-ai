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
        $runs = Tables::runs();
        $documents = Tables::documents();

        Schema::create(Tables::runItems(), function (Blueprint $table) use ($runs, $documents): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('external_id', 191);

            $table->string('status', 16)->default('pending');

            $table->unsignedInteger('chunks_created')->default(0);
            $table->unsignedInteger('chunks_reused')->default(0);
            $table->unsignedInteger('chunks_deleted')->default(0);
            $table->unsignedInteger('tokens')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();

            $table->text('error')->nullable();

            $table->timestamps();

            $table->foreign('run_id')->references('id')->on($runs)->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on($documents)->nullOnDelete();

            $table->unique(['run_id', 'external_id']);
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::runItems());
    }
};
