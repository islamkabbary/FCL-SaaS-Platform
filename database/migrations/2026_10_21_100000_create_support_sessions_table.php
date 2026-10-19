<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Support sessions: a Platform Admin's temporary, restricted access to a tenant as one
     * of its Tenant Admins, and the trail of what was viewed or refused during it.
     */
    public function up(): void
    {
        Schema::create('support_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('actor_name');
            $table->string('actor_email');
            $table->string('tenant_id')->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnUpdate()->restrictOnDelete();
            // The target lives in the tenant database: copy the identity, no foreign key.
            $table->unsignedBigInteger('target_user_id');
            $table->string('target_name');
            $table->string('target_email');
            $table->text('reason');
            // SHA-256 of the single-use handoff credential; cleared once it is consumed.
            $table->char('handoff_token_hash', 64)->nullable()->unique();
            $table->timestamp('handoff_expires_at');
            $table->timestamp('handoff_consumed_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->string('ended_by_type')->nullable();
            $table->string('ended_by_email')->nullable();
            $table->string('started_ip', 45)->nullable();
            $table->string('handoff_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('support_session_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_session_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('action');
            $table->string('http_method', 10)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_session_events');
        Schema::dropIfExists('support_sessions');
    }
};
