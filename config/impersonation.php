<?php

/*
|--------------------------------------------------------------------------
| Restricted Interactive Impersonation
|--------------------------------------------------------------------------
|
| Two workflows share one restriction policy (App\Impersonation\RestrictionPolicy):
|
| - Support access: a Platform Admin temporarily enters a tenant as one of its
|   Tenant Admins. Disabled by default; enable it explicitly per environment once
|   the security requirements in docs/MILESTONE_05_SUPPORT_IMPERSONATION_SECURITY.md
|   are verified there.
| - Employee view: a Tenant Admin temporarily views the application as one of
|   their own lower-privileged employees.
|
| Lifetimes are clamped to 1–60 minutes whatever the environment says.
|
*/

return [

    'support' => [
        'enabled' => (bool) env('SUPPORT_ACCESS_ENABLED', false),

        'lifetime_minutes' => max(1, min(60, (int) env('SUPPORT_SESSION_LIFETIME', 15))),

        // Validity of the single-use credential that carries the Platform Admin to the tenant.
        'handoff_seconds' => max(10, min(60, (int) env('SUPPORT_HANDOFF_SECONDS', 60))),

        // Require a confirmed authenticator app and a fresh code to start a support session.
        'require_two_factor' => (bool) env('SUPPORT_REQUIRE_TWO_FACTOR', true),
    ],

    'employee' => [
        'enabled' => (bool) env('EMPLOYEE_IMPERSONATION_ENABLED', true),

        'lifetime_minutes' => max(1, min(60, (int) env('EMPLOYEE_IMPERSONATION_LIFETIME', 15))),
    ],

];
