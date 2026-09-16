<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Support\Tables;

/**
 * Links a logged query to the conversation it belongs to.
 *
 * Additive and nullable on purpose: every existing query row -- and every
 * query that still arrives from the CLI, MCP or the Filament playground --
 * has no conversation, and must keep working exactly as before.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        $conversations = Tables::conversations();

        // SQLite cannot attach a foreign key to a table that already exists,
        // and the test suite runs on SQLite. The column and its index are what
        // the application actually reads; the constraint is a production
        // guarantee, so it is added only where the driver supports it.
        $supportsForeignKeys = Schema::connection($this->getConnection())
            ->getConnection()->getDriverName() !== 'sqlite';

        Schema::table(Tables::queries(), function (Blueprint $table) use ($conversations, $supportsForeignKeys): void {
            $table->unsignedBigInteger('conversation_id')->nullable()->after('user_id');
            $table->unsignedSmallInteger('turn')->default(0)->after('conversation_id');

            if ($supportsForeignKeys) {
                $table->foreign('conversation_id')
                    ->references('id')->on($conversations)
                    ->cascadeOnDelete();
            }

            $table->index(['conversation_id', 'turn']);
        });
    }

    public function down(): void
    {
        $supportsForeignKeys = Schema::connection($this->getConnection())
            ->getConnection()->getDriverName() !== 'sqlite';

        Schema::table(Tables::queries(), function (Blueprint $table) use ($supportsForeignKeys): void {
            if ($supportsForeignKeys) {
                $table->dropForeign(['conversation_id']);
            }

            $table->dropIndex(['conversation_id', 'turn']);
            $table->dropColumn(['conversation_id', 'turn']);
        });
    }
};
