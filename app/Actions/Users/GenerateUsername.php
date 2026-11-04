<?php

namespace App\Actions\Users;

use App\Rules\ValidUsername;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A unique username built from a person's name: "Islam Kabbary" becomes "islam.kabbary",
 * and the next person with that name gets "islam.kabbary2". Uniqueness is per table, so
 * per company for tenant users.
 */
class GenerateUsername
{
    protected const Fallback = 'user';

    /**
     * @param  class-string<Model>  $model
     */
    public function handle(string $model, string $name): string
    {
        $base = $this->baseFrom($name);
        $candidate = $base;
        $suffix = 2;

        while ($model::query()->where('username', $candidate)->exists()) {
            $candidate = rtrim(substr($base, 0, ValidUsername::MaxLength - strlen((string) $suffix)), '.').$suffix;
            $suffix++;
        }

        return $candidate;
    }

    protected function baseFrom(string $name): string
    {
        $slug = ltrim(Str::slug(Str::transliterate($name), '.'), '.0123456789');
        $base = rtrim(substr($slug, 0, ValidUsername::MaxLength), '.');

        return strlen($base) >= ValidUsername::MinLength ? $base : self::Fallback;
    }
}
