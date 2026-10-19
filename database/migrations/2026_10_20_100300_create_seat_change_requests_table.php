<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seat increases (charged prorated on approval) and reductions (scheduled for the next
     * renewal) requested by a Tenant Admin and reviewed by a Platform Admin.
     */
    public function up(): void
    {
        Schema::create('seat_change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('tenant_subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_term_id')->constrained()->restrictOnDelete();
            $table->string('type');
            // Increase: additional seats. Reduction: requested seat count for the next term.
            $table->unsignedInteger('seats');
            $table->string('status')->index();
            // Equals tenant_id while the request is pending, so a tenant has at most one pending request.
            $table->string('pending_tenant_id')->nullable()->unique();
            // The requesting user lives in the tenant database: copy the identity, no foreign key.
            $table->unsignedBigInteger('requested_by_user_id');
            $table->string('requested_by_name');
            $table->string('requested_by_email');
            $table->text('request_note')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('estimated_amount')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('billing_charge_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('applied_to_term_id')->nullable()->constrained('subscription_terms')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seat_change_requests');
    }
};
