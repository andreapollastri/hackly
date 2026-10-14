<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When disabled, the package registers nothing visible: no routes, no
    | login button. By default it is on whenever a client id is present,
    | so a project without CHECKIN_* variables behaves as before.
    |
    */

    'enabled' => (bool) env('CHECKIN_ENABLED', (bool) env('CHECKIN_CLIENT_ID')),

    /*
    |--------------------------------------------------------------------------
    | Issuer
    |--------------------------------------------------------------------------
    |
    | The base URL of your Checkin server. Discovery happens at
    | {issuer}/.well-known/openid-configuration and the "iss" claim of every
    | ID token must match this value exactly (scheme, host, no trailing slash).
    |
    */

    'issuer' => rtrim((string) env('CHECKIN_URL', 'https://iam.ap.it'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Client credentials
    |--------------------------------------------------------------------------
    |
    | Issued by the Checkin administrator for this application. Confidential
    | clients send the secret at the token endpoint; public clients (no
    | secret) rely on PKCE alone, which is always used anyway.
    |
    */

    'client_id' => env('CHECKIN_CLIENT_ID'),

    'client_secret' => env('CHECKIN_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    |
    | "openid" is mandatory for an ID token. "profile" and "email" add the
    | name and e-mail claims used to create or link local users.
    |
    */

    'scopes' => ['openid', 'profile', 'email'],

    /*
    |--------------------------------------------------------------------------
    | Button label
    |--------------------------------------------------------------------------
    */

    'label' => env('CHECKIN_LABEL', 'Continue with Checkin'),

    /*
    |--------------------------------------------------------------------------
    | Guard & redirects
    |--------------------------------------------------------------------------
    |
    | The stateful guard to log the user into (null = the default guard).
    | "redirect" is used after login when no intended URL is pending, and
    | "failure_redirect" after an error (null = the "login" route, or "/").
    |
    */

    'guard' => env('CHECKIN_GUARD'),

    'remember' => (bool) env('CHECKIN_REMEMBER', false),

    'redirect' => env('CHECKIN_REDIRECT', '/'),

    'failure_redirect' => env('CHECKIN_FAILURE_REDIRECT'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The package registers GET {prefix}/redirect, GET {prefix}/callback and
    | GET|POST {prefix}/logout. The callback URL must be registered on the
    | Checkin server as this client's redirect URI.
    |
    */

    'routes' => [
        'enabled' => true,
        'prefix' => env('CHECKIN_ROUTE_PREFIX', 'auth/checkin'),
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    |
    | The package's own logout route ends the local session and, when
    | "remote" is true, also the Checkin session (RP-initiated logout).
    | The application's native logout is left untouched.
    |
    */

    'logout' => [
        'remote' => (bool) env('CHECKIN_LOGOUT_REMOTE', true),
        'redirect' => env('CHECKIN_LOGOUT_REDIRECT', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    |
    | How a Checkin identity becomes a local user. The subject ("sub") is
    | stored in "column". When no user carries that subject, one with the
    | same verified e-mail is linked (link_by_email), otherwise a new user
    | is created (auto_create). "sync" lists the attributes refreshed from
    | Checkin at every login. "resolver" may point to your own class
    | implementing Checkin\Contracts\ResolvesUsers.
    |
    */

    'user' => [
        'model' => env('CHECKIN_USER_MODEL'),
        'column' => 'checkin_id',
        'auto_create' => (bool) env('CHECKIN_AUTO_CREATE_USERS', true),
        'link_by_email' => (bool) env('CHECKIN_LINK_BY_EMAIL', true),
        'require_verified_email' => true,
        'sync' => ['name'],
        'defaults' => [],
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Discovery documents and signing keys are cached for "cache_ttl"
    | seconds; "leeway" tolerates clock drift when validating ID tokens.
    |
    */

    'http' => [
        'timeout' => 10,
        'cache_ttl' => 3600,
        'cache_store' => env('CHECKIN_CACHE_STORE'),
        'leeway' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament
    |--------------------------------------------------------------------------
    |
    | Defaults for Checkin\Filament\CheckinPlugin. Each can be overridden
    | fluently on the plugin instance.
    |
    */

    'filament' => [
        'label' => null,
        'position' => 'after',
        'color' => 'gray',
        'icon' => 'heroicon-o-key',
        'divider' => true,
        'divider_label' => 'or',
    ],

];
