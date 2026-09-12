<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('scheduled_check_in_at')->nullable()->after('check_out_at');
            $table->timestamp('cancellation_requested_at')->nullable()->after('cancelled_at');
            $table->timestamp('cancellation_approved_at')->nullable()->after('cancellation_requested_at');
            $table->timestamp('cancellation_deadline_at')->nullable()->after('cancellation_approved_at');
            $table->boolean('refund_eligible')->nullable()->after('cancellation_deadline_at');
            $table->unsignedBigInteger('eligible_down_payment_amount_minor')->default(0)->after('refund_eligible');
            $table->unsignedBigInteger('estimated_refund_amount_minor')->default(0)->after('eligible_down_payment_amount_minor');
            $table->unsignedBigInteger('estimated_retained_amount_minor')->default(0)->after('estimated_refund_amount_minor');
            $table->string('refund_status', 30)->default('not_applicable')->after('estimated_retained_amount_minor');
            $table->unsignedBigInteger('refunded_amount_minor')->default(0)->after('refund_status');
            $table->timestamp('refunded_at')->nullable()->after('refunded_amount_minor');
            $table->string('refund_reference', 120)->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn([
                'scheduled_check_in_at', 'cancellation_requested_at', 'cancellation_approved_at',
                'cancellation_deadline_at', 'refund_eligible', 'eligible_down_payment_amount_minor',
                'estimated_refund_amount_minor', 'estimated_retained_amount_minor', 'refund_status',
                'refunded_amount_minor', 'refunded_at', 'refund_reference',
            ]);
        });
    }
};
