<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The platform's single default price per user and month, in the currency's minor
     * unit. New subscriptions and renewals copy it into their term; a change never
     * affects a term that already exists.
     */
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->unsignedBigInteger('seat_price');
            $table->foreignId('updated_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
