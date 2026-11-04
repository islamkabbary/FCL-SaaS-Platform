<?php

namespace App\Actions\Localization;

use App\Models\PlatformAdmin;
use App\Models\User;

/**
 * Save the language an account chose for itself.
 */
class UpdateLocalePreference
{
    public function handle(User|PlatformAdmin $account, string $locale): void
    {
        $account->forceFill(['locale' => $locale])->save();
    }
}
