<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The subject type is the subject's full class name (no morph map). 100 characters was
     * too short for longer FCL model names; a value that did not fit would have rolled back
     * the business change it described.
     */
    public function up(): void
    {
        Schema::table('tenant_audit_logs', function (Blueprint $table) {
            $table->string('subject_type', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_audit_logs', function (Blueprint $table) {
            $table->string('subject_type', 100)->nullable()->change();
        });
    }
};
