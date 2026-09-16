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
        Schema::create(Tables::settings(), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('key', 191)->unique();
            $table->text('value')->nullable();
            $table->string('type', 16)->default('string');
            $table->string('updated_by', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::settings());
    }
};
