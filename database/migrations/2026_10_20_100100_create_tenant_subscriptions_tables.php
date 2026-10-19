<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * Tenants created before subscriptions existed keep their access ("legacy") until a
         * Platform Admin activates a subscription for them; every tenant created from now on
         * requires one.
         */
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('subscription_required')->default(true)->after('suspension_reason');
        });

        DB::table('tenants')->update(['subscription_required' => false]);

        // One subscription per tenant; its terms hold the dates, seats and pricing snapshots.
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->timestamp('activated_at');
            $table->foreignId('activated_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
        });

        /*
         * Each annual term covers [starts_on, ends_on). Prices are snapshots taken when the
         * term was created and never change; "seats" is the term's purchased entitlement
         * (initial_seats plus approved increases).
         */
        Schema::create('subscription_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_subscription_id')->constrained()->restrictOnDelete();
            $table->string('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('kind');
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->string('plan_name');
            $table->char('currency', 3);
            $table->unsignedBigInteger('annual_base_fee');
            $table->unsignedBigInteger('annual_seat_price');
            $table->unsignedInteger('initial_seats');
            $table->unsignedInteger('seats');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignId('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_subscription_id', 'sequence']);
            $table->unique(['tenant_subscription_id', 'starts_on']);
            $table->index(['tenant_id', 'starts_on', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_terms');
        Schema::dropIfExists('tenant_subscriptions');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('subscription_required');
        });
    }
};
