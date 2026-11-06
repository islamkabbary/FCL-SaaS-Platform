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
     * currency's minor unit. Lines and payments are append-only; a charge only changes its
     * percentage discount, and only while nothing has been paid on it.
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
            // Sum of the lines, the discount in basis points (1250 = 12.5%) and the amount due.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedSmallInteger('discount_basis_points')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('amount');
            $table->string('description');
            // The billed month of a monthly charge: its index in the term and [starts_on, ends_on).
            $table->unsignedTinyInteger('period_index')->nullable();
            $table->date('period_starts_on')->nullable();
            $table->date('period_ends_on')->nullable();
            // Identifies the business event that produced the charge, so it can never be charged twice.
            $table->string('idempotency_key')->unique();
            $table->date('issued_on');
            // Null for a monthly charge issued by the scheduler.
            $table->foreignId('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['issued_on', 'id']);
        });

        Schema::create('billing_charge_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_charge_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_amount');
            $table->unsignedBigInteger('amount');
            // Calculation inputs (e.g. the price, seats and months of a setup fee).
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
