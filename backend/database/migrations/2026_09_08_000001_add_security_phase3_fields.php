<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('email_verification_attempts')->default(0);
            $table->timestamp('email_verification_locked_at')->nullable();
            $table->string('registration_cancel_token_hash', 64)->nullable();
            $table->timestamp('registration_cancel_token_issued_at')->nullable();
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->string('access_token_hash', 64)->nullable()->index();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('access_token_revoked_at')->nullable();
            $table->string('access_token', 80)->nullable()->change();
        });

        DB::table('chat_conversations')
            ->select(['id', 'access_token'])
            ->whereNotNull('access_token')
            ->orderBy('id')
            ->chunkById(100, function ($conversations): void {
                foreach ($conversations as $conversation) {
                    DB::table('chat_conversations')
                        ->where('id', $conversation->id)
                        ->update([
                            'access_token_hash' => hash('sha256', (string) $conversation->access_token),
                            'access_token' => null,
                            'access_token_expires_at' => now()->addDays((int) config('chatbot.anonymous_token_expire_days', 30)),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_verification_attempts',
                'email_verification_locked_at',
                'registration_cancel_token_hash',
                'registration_cancel_token_issued_at',
            ]);
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropIndex('chat_conversations_access_token_hash_index');
            $table->dropColumn([
                'access_token_hash',
                'access_token_expires_at',
                'access_token_revoked_at',
            ]);
            $table->string('access_token', 80)->nullable(false)->change();
        });
    }
};
