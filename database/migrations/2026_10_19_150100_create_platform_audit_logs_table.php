<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            // Records Platform Admin actions and requests made by tenant users.
            $table->string('actor_type')->default('platform_admin');
            // The admin's email is copied so the record stays meaningful if the admin is removed.
            $table->foreignId('platform_admin_id')->nullable()->constrained()->nullOnDelete();
            // The tenant user's id in the tenant database; no foreign key across databases.
            $table->unsignedBigInteger('tenant_user_id')->nullable();
            $table->string('actor_email');
            // Null for platform-wide actions. Restrict, so a tenant with audit history can never
            // be deleted (and its history lost) silently.
            $table->string('tenant_id')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('action')->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
    }
};
