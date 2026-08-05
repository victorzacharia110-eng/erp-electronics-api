<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OidcService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class SsoController extends Controller
{
    public function __construct(private readonly OidcService $oidc)
    {
    }

    public function status(): JsonResponse
    {
        return response()->json([
            'enabled' => config('sso.enabled'),
            'provider' => config('sso.enabled') ? config('sso.provider') : null,
        ]);
    }

    public function redirect(): RedirectResponse
    {
        if (!config('sso.enabled')) {
            return redirect()->away($this->frontendLogin('disabled'));
        }

        $state = Str::random(40);
        $nonce = Str::random(40);

        Cache::put('sso:state:' . $state, $nonce, now()->addMinutes(10));

        $url = $this->oidc->authorizationUrl($state, $nonce);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $code = $request->query('code');
        $error = $request->query('error');

        if ($error) {
            return redirect()->away($this->frontendLogin('denied'));
        }

        if (!$state || !$code) {
            return redirect()->away($this->frontendLogin('invalid_request'));
        }

        $nonce = Cache::pull('sso:state:' . $state);
        if (!$nonce) {
            return redirect()->away($this->frontendLogin('invalid_state'));
        }

        try {
            $tokens = $this->oidc->exchangeAuthorizationCode($code);

            if (empty($tokens['id_token'])) {
                throw new RuntimeException('Token response contains no id_token.');
            }

            $claims = $this->oidc->verifyIdToken($tokens['id_token'], $nonce);
        } catch (RuntimeException $e) {
            logger()->warning('SSO login failed: ' . $e->getMessage());

            return redirect()->away($this->frontendLogin('authentication_failed'));
        }

        $user = $this->resolveUser($claims);
        if (!$user) {
            return redirect()->away($this->frontendLogin('no_account'));
        }

        $allowedRoles = (array) config('sso.allowed_roles');
        if (!in_array($user->role, $allowedRoles, true) || $user->isSuperadmin()) {
            return redirect()->away($this->frontendLogin('role_not_allowed'));
        }

        if (!$user->is_active) {
            return redirect()->away($this->frontendLogin('deactivated'));
        }

        if ($user->isLocked()) {
            return redirect()->away($this->frontendLogin('locked'));
        }

        $user->update([
            'sso_provider' => config('sso.provider'),
            'sso_subject' => $claims['sub'] ?? $claims['oid'] ?? null,
            'password_changed_at' => now(),
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        // Token is delivered via URL fragment so it is never sent to the
        // server or included in Referer headers.
        $url = $this->frontendBase() . '/sso/callback#token=' . urlencode($token);

        return redirect()->away($url);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function resolveUser(array $claims): ?User
    {
        $subject = $claims['sub'] ?? $claims['oid'] ?? null;
        $email = strtolower(trim($claims['email'] ?? $claims['preferred_username'] ?? ''));

        if (!$email) {
            return null;
        }

        $user = null;
        if ($subject) {
            $user = User::where('sso_provider', config('sso.provider'))
                ->where('sso_subject', $subject)
                ->first();
        }

        if (!$user) {
            $user = User::where('email', $email)->first();
        }

        if (!$user) {
            return null;
        }

        // Prevent account takeover: an account already linked to a different
        // SSO subject must not be re-linked via an email match.
        if ($user->sso_subject && $subject && $user->sso_subject !== $subject) {
            return null;
        }

        return $user;
    }

    private function frontendBase(): string
    {
        return rtrim((string) config('sso.frontend_redirect'), '/');
    }

    private function frontendLogin(string $reason): string
    {
        $base = $this->frontendBase();
        if (!$base) {
            return route('login');
        }

        return $base . '/login?sso_error=' . urlencode($reason);
    }
}
