<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Single Sign-On (OIDC)
    |--------------------------------------------------------------------------
    |
    | When enabled, owners and employees can sign in through an external
    | identity provider (Microsoft Entra ID by default, or any OIDC provider)
    | instead of using the local email/password login.
    |
    */

    'enabled' => (bool) env('SSO_ENABLED', false),

    'provider' => env('SSO_PROVIDER', 'azure'),

    'tenant_id' => env('SSO_TENANT_ID'),

    // Base authority URL. For Azure this defaults to the v2.0 endpoint for
    // the tenant; for a generic provider set SSO_AUTHORITY explicitly.
    'authority' => env('SSO_AUTHORITY'),

    'client_id' => env('SSO_CLIENT_ID'),

    'client_secret' => env('SSO_CLIENT_SECRET'),

    // Backend callback route the IdP redirects to after authentication.
    'redirect_uri' => env('SSO_REDIRECT_URI'),

    // Frontend SPA location where the token is delivered after a successful login.
    'frontend_redirect' => env('SSO_FRONTEND_REDIRECT'),

    'scopes' => 'openid profile email',

    // Roles allowed to use SSO. Superadmin stays on local credentials.
    'allowed_roles' => ['owner', 'employee'],

];
