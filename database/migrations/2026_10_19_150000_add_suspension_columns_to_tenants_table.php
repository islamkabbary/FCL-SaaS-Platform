<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant access lifecycle, kept separate from the provisioning "status" column:
 * a tenant is suspended while "suspended_at" is set. Existing tenants stay active.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->index()->after('provisioned_at');
            $table->text('suspension_reason')->nullable()->after('suspended_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['suspended_at']);
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
