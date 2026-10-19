<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tenant-local audit of a Tenant Admin viewing the application as one of their
     * employees, and the trail of what was viewed or refused during it. Identities are
     * copied, without foreign keys, so the history survives account deletion.
     */
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('impersonator_user_id')->index();
            $table->string('impersonator_name');
            $table->string('impersonator_email');
            $table->unsignedBigInteger('target_user_id')->index();
            $table->string('target_name');
            $table->string('target_email');
            $table->text('reason');
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->string('ended_by_email')->nullable();
            $table->string('started_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('impersonation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonation_session_id')->constrained()->cascadeOnDelete();
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
        Schema::dropIfExists('impersonation_events');
        Schema::dropIfExists('impersonation_sessions');
    }
};
