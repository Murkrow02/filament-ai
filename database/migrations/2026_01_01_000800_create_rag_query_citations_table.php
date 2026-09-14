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
        $queries = Tables::queries();
        $chunks = Tables::chunks();
        $documents = Tables::documents();

        Schema::create(Tables::citations(), function (Blueprint $table) use ($queries, $chunks, $documents): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('query_id');
            $table->unsignedBigInteger('chunk_id')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();

            // The n in "[#n]" as shown to the model.
            $table->unsignedSmallInteger('marker');

            $table->float('score')->default(0);
            $table->unsignedSmallInteger('rank')->default(0);

            $table->unsignedInteger('position_start')->default(0);
            $table->unsignedInteger('position_end')->default(0);

            // Whether the answer actually referenced this citation.
            $table->boolean('used')->default(false);

            $table->text('snippet')->nullable();

            $table->foreign('query_id')->references('id')->on($queries)->cascadeOnDelete();
            $table->foreign('chunk_id')->references('id')->on($chunks)->nullOnDelete();
            $table->foreign('document_id')->references('id')->on($documents)->nullOnDelete();

            $table->index(['query_id', 'rank']);
            $table->index('chunk_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::citations());
    }
};
