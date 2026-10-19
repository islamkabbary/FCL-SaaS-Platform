<?php

/*
|--------------------------------------------------------------------------
| SaaS Billing
|--------------------------------------------------------------------------
|
| Technical defaults for the manually operated annual subscription billing.
| Commercial values (plan prices, plan currencies) are never configured here:
| Platform Admins set them on each plan.
|
*/

return [

    /*
     * Currencies a plan may be priced in, with the number of decimal places of the
     * currency's minor unit (ISO 4217). Amounts are stored as integers in minor units,
     * so this precision is fixed per currency. This list is a technical allow-list,
     * not a business decision; add currencies here when needed.
     */
    'currencies' => [
        'AED' => 2,
        'BHD' => 3,
        'EGP' => 2,
        'EUR' => 2,
        'GBP' => 2,
        'JOD' => 3,
        'KWD' => 3,
        'OMR' => 3,
        'QAR' => 2,
        'SAR' => 2,
        'USD' => 2,
    ],

    /*
     * Upper bound for purchased seats and for any single price, as a sanity limit
     * that also keeps every amount comfortably inside a signed 64-bit integer.
     */
    'max_seats' => 10000,
    'max_price_minor' => 100_000_000_000,

    /*
     * A subscription can be renewed from this many days before its current term ends.
     * The platform dashboard also lists these subscriptions as "renewal due".
     */
    'renewal_window_days' => 30,

    /*
     * Seconds an employee creation or reactivation waits for another seat-consuming
     * operation of the same tenant before giving up.
     */
    'seat_lock_timeout' => (int) env('BILLING_SEAT_LOCK_TIMEOUT', 5),

];
