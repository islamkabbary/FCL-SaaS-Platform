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
     * The username a user signs in with, generated from their name for existing users.
     * username_changed_at records the user's one allowed change.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable();
            $table->timestamp('username_changed_at')->nullable();
        });

        $taken = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'name']) as $user) {
            $base = rtrim(substr(ltrim(Str::slug(Str::transliterate($user->name), '.'), '.0123456789'), 0, 30), '.');
            $base = strlen($base) >= 3 ? $base : 'user';
            $candidate = $base;
            $suffix = 2;

            while (isset($taken[$candidate])) {
                $candidate = rtrim(substr($base, 0, 30 - strlen((string) $suffix)), '.').$suffix;
                $suffix++;
            }

            $taken[$candidate] = true;
            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable(false)->change();
            $table->unique('username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'username_changed_at']);
        });
    }
};
