<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('recipient')->index();
            $table->string('channel', 16);
            $table->string('priority', 16);
            $table->text('body');
            $table->string('status', 16);
            $table->jsonb('status_history')->default('[]');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('provider_message_id', 128)->nullable();
            $table->string('trace_id', 64)->nullable();
            $table->integer('version')->default(0);
            $table->timestampsTz();

            $table->index(['recipient', 'created_at']);
            $table->index('status')->where('status', 'queued');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
