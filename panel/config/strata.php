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

];
