<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The audit trail now also records requests made by tenant users (e.g. a Tenant Admin
     * requesting seats) and platform-wide actions without a tenant (plan changes).
     * Existing records are Platform Admin actions on a tenant and stay as they are.
     */
    public function up(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->string('actor_type')->default('platform_admin')->after('id');
            // The tenant user's id in the tenant database; no foreign key across databases.
            $table->unsignedBigInteger('tenant_user_id')->nullable()->after('platform_admin_id');
            $table->string('tenant_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->dropColumn(['actor_type', 'tenant_user_id']);
        });
    }
};
