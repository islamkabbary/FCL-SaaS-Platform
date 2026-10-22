<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The company's business audit log, inside the tenant database (never in the central
     * database). Identities are copied, without foreign keys, so the history survives
     * account deletion; the original actor of an impersonated session is a Platform Admin
     * (central) or a Tenant Admin of this company.
     */
    public function up(): void
    {
        Schema::create('tenant_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_name');
            $table->string('actor_email');
            $table->string('impersonation_kind', 20)->nullable();
            $table->unsignedBigInteger('impersonation_session_id')->nullable();
            $table->string('original_actor_type', 20)->nullable();
            $table->unsignedBigInteger('original_actor_id')->nullable();
            $table->string('original_actor_name')->nullable();
            $table->string('original_actor_email')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_audit_logs');
    }
};
