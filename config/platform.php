<?php

/*
|--------------------------------------------------------------------------
| Central Platform
|--------------------------------------------------------------------------
|
| Platform Admins manage every tenant, so two-factor authentication is required
| for all of them by default. An admin without confirmed two-factor authentication
| can only reach the enrollment page (and log out) until they have set it up.
| Recovery when an authenticator is lost: php artisan platform:reset-two-factor.
|
*/

return [

    'require_two_factor' => (bool) env('PLATFORM_REQUIRE_TWO_FACTOR', true),

    /*
    | The master switch of two-factor authentication. While it is off (BD-SEC05), there is
    | no QR code, no code step and no requirement anywhere, whatever the settings above and
    | SUPPORT_REQUIRE_TWO_FACTOR say. Saved secrets are kept, so switching it on restores them.
    */

    'two_factor_enabled' => (bool) env('PLATFORM_TWO_FACTOR_ENABLED', false),

];
