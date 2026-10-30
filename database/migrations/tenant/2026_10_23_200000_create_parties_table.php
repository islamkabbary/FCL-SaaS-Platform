<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * FCL parties (customers, importers, agents, carriers): master data shared by every
     * branch of the company (BD-D01). A party needs no ACID (BD-P09). name_key and
     * tax_number_key hold normalised forms used only to warn about likely duplicates;
     * they are deliberately not unique.
     */
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('name_key')->index();
            $table->string('tax_number', 50)->nullable();
            $table->string('tax_number_key', 50)->nullable()->index();
            $table->char('country_code', 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
