<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Support\Tables;

/*
 * Folders for the assistant's chats. The chats themselves are laravel/ai's
 * (agent_conversations), a table this package does not own and does not alter:
 * which folder a chat is in lives in a table of our own, keyed by its id.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return Tables::connection();
    }

    public function up(): void
    {
        Schema::create(Tables::chatFolders(), function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The owner as laravel/ai records a conversation's participant:
            // morph class and key, the key a string so no users table shape
            // is assumed.
            $table->string('owner_type');
            $table->string('owner_id', 64);

            $table->string('name', 100);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create(Tables::chatFolderItems(), function (Blueprint $table): void {
            // One folder per chat: the conversation id is the key.
            $table->string('conversation_id', 36)->primary();
            $table->foreignId('folder_id')->constrained(Tables::chatFolders())->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::chatFolderItems());
        Schema::dropIfExists(Tables::chatFolders());
    }
};
