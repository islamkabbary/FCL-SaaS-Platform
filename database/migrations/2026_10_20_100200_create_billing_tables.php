<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Internal commercial billing records (not tax invoices). Amounts are integers in the
     * currency's minor unit. Charges, lines and payments are append-only.
     */
    public function up(): void
    {
        Schema::create('billing_charges', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('tenant_subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_term_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->string('description');
            // Identifies the business event that produced the charge, so it can never be charged twice.
            $table->string('idempotency_key')->unique();
            $table->date('issued_on');
            $table->foreignId('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('billing_charge_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_charge_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedBigInteger('amount');
            // Calculation inputs (e.g. remaining and total term days of a proration).
            $table->json('calculation')->nullable();
        });

        Schema::create('billing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_charge_id')->constrained()->restrictOnDelete();
            $table->string('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();
            // One per submitted payment form, so a double submission records one payment.
            $table->string('submission_token')->unique();
            $table->foreignId('recorded_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_charge_lines');
        Schema::dropIfExists('billing_charges');
    }
};
