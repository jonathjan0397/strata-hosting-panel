<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Installation Identity
    |--------------------------------------------------------------------------
    |
    | Generated once at install time. install_token is the public identifier
    | sent to the license server. install_secret is used to verify the HMAC
    | signature on the server's response.
    |
    */

    'install_token'  => env('STRATA_INSTALL_TOKEN', ''),
    'install_secret' => env('STRATA_INSTALL_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | License Server
    |--------------------------------------------------------------------------
    */

    'license_server_url' => env('STRATA_LICENSE_SERVER_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Version
    |--------------------------------------------------------------------------
    */

    'version' => env('STRATA_VERSION', 'dev'),

    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the login page shows publicly accessible test credentials
    | and the license ping includes a demo_mode flag.
    |
    */

    'demo_mode' => env('STRATA_DEMO_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Browser Shell
    |--------------------------------------------------------------------------
    |
    | The browser shell opens an interactive node shell from the panel. This
    | is high impact and should stay disabled unless the operator explicitly
    | enables it for a trusted environment.
    |
    */

    'browser_shell_enabled' => env('STRATA_BROWSER_SHELL_ENABLED', false),
    'browser_shell_allow_production' => env('STRATA_BROWSER_SHELL_ALLOW_PRODUCTION', false),

];
