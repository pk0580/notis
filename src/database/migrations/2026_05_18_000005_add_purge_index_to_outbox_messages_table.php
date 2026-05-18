<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            // Удаляем старый индекс, созданный Laravel по умолчанию
            $table->dropIndex('outbox_messages_published_at_index');
            
            // Создаем индекс с именем и условием из плана (§7)
            // Используем raw для гарантированного WHERE в PostgreSQL
        });

        DB::statement('CREATE INDEX outbox_published_at_idx ON outbox_messages (published_at) WHERE published_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropIndex('outbox_published_at_idx');
            $table->index('published_at')->whereNotNull('published_at');
        });
    }
};
