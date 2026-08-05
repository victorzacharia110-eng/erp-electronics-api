<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OidcService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use RefreshDatabase;

    private string $issuer = 'https://login.microsoftonline.com/test-tenant/v2.0';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sso.enabled', true);
        config()->set('sso.provider', 'azure');
        config()->set('sso.tenant_id', 'test-tenant');
        config()->set('sso.client_id', 'test-client');
        config()->set('sso.client_secret', 'test-secret');
        config()->set('sso.authority', $this->issuer);
        config()->set('sso.frontend_redirect', 'https://frontend.test');
    }

    private function fakeIdp(bool $withKeys = true): void
    {
        Http::fake([
            $this->issuer . '/.well-known/openid-configuration' => Http::response([
                'issuer' => $this->issuer,
                'authorization_endpoint' => $this->issuer . '/authorize',
                'token_endpoint' => $this->issuer . '/token',
                'jwks_uri' => $this->issuer . '/keys',
            ]),
            $this->issuer . '/keys' => Http::response($withKeys ? $this->jwkKeys() : ['keys' => []]),
            $this->issuer . '/token' => function ($request) {
                $code = $request['code'];

                $b64u = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
                $key = openssl_pkey_get_private($this->privateKey());
                $header = $b64u(json_encode(['alg' => 'RS256', 'kid' => 'test-key']));
                $payload = json_decode(decrypt($code), true);
                $claims = $b64u(json_encode($payload));
                openssl_sign("$header.$claims", $sig, $key, OPENSSL_ALGO_SHA256);

                return Http::response([
                    'access_token' => 'test-access-token',
                    'id_token' => "$header.$claims." . $b64u($sig),
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                ]);
            },
        ]);
    }

    private function code(array $claims): string
    {
        return encrypt(json_encode($claims));
    }

    private function jwkKeys(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey()));
        $b64u = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');

        return [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'test-key',
                'alg' => 'RS256',
                'n' => $b64u($details['rsa']['n']),
                'e' => $b64u($details['rsa']['e']),
            ]],
        ];
    }

    private function privateKey(): string
    {
        static $pem;
        if ($pem) {
            return $pem;
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    public function test_status_reports_enabled_when_configured(): void
    {
        $this->getJson('/api/auth/sso/status')
            ->assertOk()
            ->assertJson(['enabled' => true, 'provider' => 'azure']);
    }

    public function test_status_reports_disabled_when_not_configured(): void
    {
        config()->set('sso.enabled', false);

        $this->getJson('/api/auth/sso/status')
            ->assertOk()
            ->assertJson(['enabled' => false]);
    }

    public function test_redirect_builds_authorization_url_and_stores_state(): void
    {
        Http::fake([
            $this->issuer . '/.well-known/openid-configuration' => Http::response([
                'issuer' => $this->issuer,
                'authorization_endpoint' => $this->issuer . '/authorize',
                'token_endpoint' => $this->issuer . '/token',
                'jwks_uri' => $this->issuer . '/keys',
            ]),
        ]);

        $response = $this->get('/api/auth/sso/redirect');
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith($this->issuer . '/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $params);

        $this->assertSame('test-client', $params['client_id']);
        $this->assertSame('code', $params['response_type']);
        $this->assertArrayHasKey('state', $params);
        $this->assertArrayHasKey('nonce', $params);
        $this->assertNotNull(Cache::get('sso:state:' . $params['state']));
    }

    public function test_callback_logs_in_owner_and_redirects_to_frontend(): void
    {
        $this->fakeIdp();
        $user = User::factory()->create([
            'role' => 'owner',
            'email' => 'owner@company.com',
            'password' => bcrypt('anything'),
            'password_changed_at' => now(),
        ]);

        $state = Str::random(40);
        $nonce = Str::random(40);
        Cache::put('sso:state:' . $state, $nonce, now()->addMinutes(10));

        $code = $this->code([
            'sub' => 'sso-subject-1',
            'email' => 'owner@company.com',
            'nonce' => $nonce,
            'iss' => $this->issuer,
            'aud' => 'test-client',
            'exp' => time() + 300,
        ]);

        $response = $this->get("/api/auth/sso/callback?code={$code}&state={$state}");
        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://frontend.test/sso/callback#token=', $location);

        parse_str(parse_url($location, PHP_URL_FRAGMENT), $fragments);
        $token = $fragments['token'] ?? null;
        $this->assertNotNull($token);

        $user->refresh();
        $this->assertSame('sso-subject-1', $user->sso_subject);
        $this->assertSame('azure', $user->sso_provider);

        $this->withToken($token)
            ->getJson('/api/auth/profile')
            ->assertOk()
            ->assertJsonPath('email', 'owner@company.com');
    }

    public function test_callback_rejects_employee_if_not_in_allowed_roles(): void
    {
        $this->fakeIdp();
        User::factory()->create([
            'role' => 'customer',
            'email' => 'customer@shop.com',
            'password' => bcrypt('anything'),
        ]);

        $state = Str::random(40);
        $nonce = Str::random(40);
        Cache::put('sso:state:' . $state, $nonce, now()->addMinutes(10));

        $code = $this->code([
            'sub' => 'sso-subject-customer',
            'email' => 'customer@shop.com',
            'nonce' => $nonce,
            'iss' => $this->issuer,
            'aud' => 'test-client',
            'exp' => time() + 300,
        ]);

        $this->get("/api/auth/sso/callback?code={$code}&state={$state}")
            ->assertRedirect('https://frontend.test/login?sso_error=role_not_allowed');
    }

    public function test_callback_rejects_unknown_email(): void
    {
        $this->fakeIdp();

        $state = Str::random(40);
        $nonce = Str::random(40);
        Cache::put('sso:state:' . $state, $nonce, now()->addMinutes(10));

        $code = $this->code([
            'sub' => 'sso-subject-unknown',
            'email' => 'nobody@company.com',
            'nonce' => $nonce,
            'iss' => $this->issuer,
            'aud' => 'test-client',
            'exp' => time() + 300,
        ]);

        $this->get("/api/auth/sso/callback?code={$code}&state={$state}")
            ->assertRedirect('https://frontend.test/login?sso_error=no_account');
    }

    public function test_callback_rejects_mismatched_nonce(): void
    {
        $this->fakeIdp();
        User::factory()->create([
            'role' => 'owner',
            'email' => 'owner@company.com',
            'password' => bcrypt('anything'),
        ]);

        $state = Str::random(40);
        Cache::put('sso:state:' . $state, Str::random(40), now()->addMinutes(10));

        $code = $this->code([
            'sub' => 'sso-subject-1',
            'email' => 'owner@company.com',
            'nonce' => 'different-nonce',
            'iss' => $this->issuer,
            'aud' => 'test-client',
            'exp' => time() + 300,
        ]);

        $this->get("/api/auth/sso/callback?code={$code}&state={$state}")
            ->assertRedirect('https://frontend.test/login?sso_error=authentication_failed');
    }

    public function test_callback_rejects_when_disabled(): void
    {
        config()->set('sso.enabled', false);

        $this->get('/api/auth/sso/redirect')
            ->assertRedirect('https://frontend.test/login?sso_error=disabled');
    }

    public function test_callback_does_not_accept_tampered_id_token(): void
    {
        // Same flow as successful login but the id_token signature is corrupted
        // by a JWKS that does not match the signing key.
        Http::fake([
            $this->issuer . '/.well-known/openid-configuration' => Http::response([
                'issuer' => $this->issuer,
                'authorization_endpoint' => $this->issuer . '/authorize',
                'token_endpoint' => $this->issuer . '/token',
                'jwks_uri' => $this->issuer . '/keys',
            ]),
            $this->issuer . '/keys' => Http::response(['keys' => []]),
            $this->issuer . '/token' => function () {
                $b64u = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
                $key = openssl_pkey_get_private($this->privateKey());
                $header = $b64u(json_encode(['alg' => 'RS256', 'kid' => 'test-key']));
                $claims = $b64u(json_encode([
                    'sub' => 'sso-subject-1',
                    'email' => 'owner@company.com',
                    'nonce' => 'nonce',
                    'iss' => $this->issuer,
                    'aud' => 'test-client',
                    'exp' => time() + 300,
                ]));
                openssl_sign("$header.$claims", $sig, $key, OPENSSL_ALGO_SHA256);

                return Http::response([
                    'access_token' => 'test-access-token',
                    'id_token' => "$header.$claims." . $b64u($sig),
                ]);
            },
        ]);

        User::factory()->create([
            'role' => 'owner',
            'email' => 'owner@company.com',
            'password' => bcrypt('anything'),
        ]);

        $state = Str::random(40);
        $nonce = Str::random(40);
        Cache::put('sso:state:' . $state, $nonce, now()->addMinutes(10));

        $code = $this->code([
            'sub' => 'sso-subject-1',
            'email' => 'owner@company.com',
            'nonce' => $nonce,
            'iss' => $this->issuer,
            'aud' => 'test-client',
            'exp' => time() + 300,
        ]);

        $this->get("/api/auth/sso/callback?code={$code}&state={$state}")
            ->assertRedirect('https://frontend.test/login?sso_error=authentication_failed');
    }
}
