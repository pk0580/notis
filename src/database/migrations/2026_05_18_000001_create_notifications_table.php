<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('recipient');
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
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement(
            "CREATE INDEX notifications_recipient_created_idx
             ON notifications (recipient, created_at DESC)"
        );

        DB::statement(
            "CREATE INDEX notifications_status_queued_idx
             ON notifications (status) WHERE status = 'queued'"
        );

        DB::statement(
            "ALTER TABLE notifications
             ADD CONSTRAINT notifications_status_chk
             CHECK (status IN ('queued','sent','delivered','dropped'))"
        );

        DB::statement(
            "ALTER TABLE notifications
             ADD CONSTRAINT notifications_channel_chk
             CHECK (channel IN ('sms','email'))"
        );

        DB::statement(
            "ALTER TABLE notifications
             ADD CONSTRAINT notifications_priority_chk
             CHECK (priority IN ('transactional','marketing'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
