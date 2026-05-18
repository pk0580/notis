<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('notification_id');
            $table->string('priority', 16);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('published_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->foreign('notification_id')->references('id')->on('notifications')->onDelete('cascade');

            $table->index('created_at')->whereNull('published_at');
            $table->index('published_at')->whereNotNull('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
