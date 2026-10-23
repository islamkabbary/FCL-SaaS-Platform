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

];
