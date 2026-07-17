<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Self-service trial
    |--------------------------------------------------------------------------
    |
    | Settings for the public self-registration flow. A newly registered
    | business is granted a short, full-featured trial so the owner can start
    | selling immediately; when it lapses the device locks until the owner pays
    | and an activation code is redeemed.
    |
    */

    // Tier granted during the trial. Defaults to the top tier so owners
    // experience the full product and are more likely to convert.
    'tier' => env('TRIAL_TIER', 'ultimate'),

    // Trial length in days.
    'duration_days' => (int) env('TRIAL_DURATION_DAYS', 1),

];
