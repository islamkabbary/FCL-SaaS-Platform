<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The username a Platform Admin signs in with, generated from the name for existing admins.
     */
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->string('username', 30)->nullable();
        });

        $taken = [];

        foreach (DB::table('platform_admins')->orderBy('id')->get(['id', 'name']) as $admin) {
            $base = rtrim(substr(ltrim(Str::slug(Str::transliterate($admin->name), '.'), '.0123456789'), 0, 30), '.');
            $base = strlen($base) >= 3 ? $base : 'user';
            $candidate = $base;
            $suffix = 2;

            while (isset($taken[$candidate])) {
                $candidate = rtrim(substr($base, 0, 30 - strlen((string) $suffix)), '.').$suffix;
                $suffix++;
            }

            $taken[$candidate] = true;
            DB::table('platform_admins')->where('id', $admin->id)->update(['username' => $candidate]);
        }

        Schema::table('platform_admins', function (Blueprint $table) {
            $table->string('username', 30)->nullable(false)->change();
            $table->unique('username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
