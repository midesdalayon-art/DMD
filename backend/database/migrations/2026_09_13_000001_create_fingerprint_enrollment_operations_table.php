<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fingerprint_enrollment_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('fingerprint_id');
            $table->string('status', 20)->index();
            $table->string('device_id')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_enrollment_operations');
    }
};
