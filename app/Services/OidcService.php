<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OidcService
{
    private const DISCOVERY_CACHE_TTL = 3600;

    private const JWKS_CACHE_TTL = 3600;

    public function discovery(): array
    {
        return Cache::remember('oidc.discovery', self::DISCOVERY_CACHE_TTL, function () {
            $url = $this->authority() . '/.well-known/openid-configuration';

            $response = Http::timeout(10)->acceptJson()->get($url);

            if ($response->failed()) {
                throw new RuntimeException("Failed to fetch the OIDC discovery document from {$url} (HTTP {$response->status()}).");
            }

            $document = $response->json();

            $required = ['authorization_endpoint', 'token_endpoint', 'issuer', 'jwks_uri'];
            foreach ($required as $key) {
                if (empty($document[$key])) {
                    throw new RuntimeException("OIDC discovery document from {$url} is missing the required \"{$key}\" field.");
                }
            }

            return $document;
        });
    }

    public function authority(): string
    {
        $authority = config('sso.authority');

        if (!$authority && config('sso.provider') === 'azure') {
            $tenant = config('sso.tenant_id');
            if (!$tenant) {
                throw new RuntimeException('SSO_TENANT_ID is required for the Azure provider.');
            }

            return "https://login.microsoftonline.com/{$tenant}/v2.0";
        }

        if (!$authority) {
            throw new RuntimeException('SSO_AUTHORITY is required for the configured provider.');
        }

        return rtrim($authority, '/');
    }

    public function authorizationUrl(string $state, string $nonce): string
    {
        $discovery = $this->discovery();

        $params = [
            'client_id' => config('sso.client_id'),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => config('sso.scopes'),
            'state' => $state,
            'nonce' => $nonce,
            'response_mode' => 'query',
            'prompt' => 'select_account',
        ];

        return $discovery['authorization_endpoint'] . '?' . http_build_query($params);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $discovery = $this->discovery();

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->acceptJson()
                ->post($discovery['token_endpoint'], [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri(),
                    'client_id' => config('sso.client_id'),
                    'client_secret' => config('sso.client_secret'),
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Could not reach the identity provider token endpoint.');
        }

        if ($response->failed()) {
            Log::warning('OIDC token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('The identity provider rejected the authorization code.');
        }

        return $response->json();
    }

    /**
     * Decode and verify an ID token (RS256 signature via the IdP JWKS).
     *
     * @return array<string, mixed> validated claims
     */
    public function verifyIdToken(string $idToken, string $nonce): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw new RuntimeException('ID token is malformed.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);

        if (!is_array($payload) || !isset($header['alg'], $header['kid'])) {
            throw new RuntimeException('ID token has invalid header or claims.');
        }

        $key = $this->findJwk($header['kid']);
        if (!$key) {
            throw new RuntimeException('No matching signing key found in the JWKS.');
        }

        $publicKey = $this->jwkToPem($key);
        $signature = $this->base64UrlDecode($signatureB64);

        $algorithms = [
            'RS256' => OPENSSL_ALGO_SHA256,
            'RS384' => OPENSSL_ALGO_SHA384,
            'RS512' => OPENSSL_ALGO_SHA512,
        ];

        $algorithm = $algorithms[$header['alg']] ?? null;
        if (!$algorithm) {
            throw new RuntimeException('Unsupported ID token signing algorithm.');
        }

        $verified = openssl_verify(
            $headerB64 . '.' . $payloadB64,
            $signature,
            $publicKey,
            $algorithm,
        );

        if ($verified !== 1) {
            throw new RuntimeException('ID token signature verification failed.');
        }

        $discovery = $this->discovery();

        if (($payload['iss'] ?? null) !== $discovery['issuer']) {
            throw new RuntimeException('ID token issuer does not match.');
        }

        $audience = (array) ($payload['aud'] ?? []);
        if (!in_array(config('sso.client_id'), $audience, true)) {
            throw new RuntimeException('ID token audience does not match.');
        }

        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            throw new RuntimeException('ID token has expired.');
        }

        if (($payload['nonce'] ?? null) !== $nonce) {
            throw new RuntimeException('ID token nonce does not match.');
        }

        return $payload;
    }

    public function redirectUri(): string
    {
        return config('sso.redirect_uri') ?: url('/api/auth/sso/callback');
    }

    private function jwks(): array
    {
        return Cache::remember('oidc.jwks', self::JWKS_CACHE_TTL, function () {
            $discovery = $this->discovery();

            if (empty($discovery['jwks_uri'])) {
                throw new RuntimeException('Discovery document has no jwks_uri.');
            }

            $response = Http::timeout(10)->acceptJson()->get($discovery['jwks_uri']);

            if ($response->failed()) {
                throw new RuntimeException('Failed to fetch the JWKS.');
            }

            return $response->json('keys', []);
        });
    }

    private function findJwk(string $kid): ?array
    {
        foreach ($this->jwks() as $key) {
            if (($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }

        return null;
    }

    private function jwkToPem(array $jwk): string
    {
        $modulus = $this->integer($this->base64UrlDecode($jwk['n']));
        $exponent = $this->integer($this->base64UrlDecode($jwk['e']));

        // AlgorithmIdentifier for rsaEncryption (OID 1.2.840.113549.1.1.1 + NULL)
        $algorithm = $this->sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $rsaKey = $this->sequence($modulus . $exponent);

        // BIT STRING wraps the RSAPublicKey (leading 0x00 unused-bits octet)
        $bitString = "\x03" . $this->length(strlen($rsaKey) + 1) . "\x00" . $rsaKey;

        $spki = $this->sequence($algorithm . $bitString);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes !== '' && (ord($bytes[0]) & 0x80)) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->length(strlen($bytes)) . $bytes;
    }

    private function sequence(string $content): string
    {
        return "\x30" . $this->length(strlen($content)) . $content;
    }

    private function length(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }

        if ($len < 0x100) {
            return "\x81" . chr($len);
        }

        if ($len < 0x10000) {
            return "\x82" . chr($len >> 8) . chr($len);
        }

        return "\x83" . chr($len >> 16) . chr($len >> 8) . chr($len);
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
