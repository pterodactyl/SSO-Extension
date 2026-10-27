<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Models\User;
use Sso\SsoSettings;
use Throwable;

final class DirectSsoController
{
    private const string CACHE_PREFIX = 'sso:direct:';

    /**
     * @var CompletesLogins
     */
    protected CompletesLogins $logins;

    /**
     * @var SsoSettings
     */
    protected SsoSettings $config;

    /**
     * @param CompletesLogins $logins
     * @param SsoSettings $config
     */
    public function __construct(CompletesLogins $logins, SsoSettings $config)
    {
        $this->logins = $logins;
        $this->config = $config;
    }

    /**
     * Handle incoming direct SSO webhook request from external billing software (WemX, Paymenter, WHMCS, etc.).
     *
     * Validates credentials and user parameters, then issues a single-use token and returns the redirect URL.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function webhook(Request $request): JsonResponse
    {
        if (! $this->config->directEnabled()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Direct SSO is currently disabled on this panel.',
            ], 403);
        }

        $secret = $this->config->directSecret();
        if ($secret === '') {
            return new JsonResponse([
                'success' => false,
                'message' => 'Please configure a SSO Secret.',
            ], 403);
        }

        $providedSecret = $request->input('sso_secret') ?? $request->bearerToken() ?? $request->header('X-SSO-Secret');
        if (! is_string($providedSecret) || ! hash_equals($secret, $providedSecret)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Please provide valid credentials.',
            ], 403);
        }

        $user = $this->resolveUser($request);
        if (! $user instanceof User) {
            return new JsonResponse([
                'success' => false,
                'message' => 'User could not be found.',
            ], 404);
        }

        if ($user->root_admin && ! $this->config->boolean(SsoSettings::DIRECT_ALLOW_ADMIN)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'You cannot automatically login to admin accounts.',
            ], 403);
        }

        $has2fa = (bool) ($user->use_totp ?? false);
        if (! $has2fa && $user->getAttribute('2fa') !== null) {
            $has2fa = (bool) $user->getAttribute('2fa');
        }

        if ($has2fa && ! $this->config->boolean(SsoSettings::DIRECT_ALLOW_2FA)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Logging into accounts with 2 Factor Authentication enabled is not supported.',
            ], 501);
        }

        $target = $request->input('redirect');
        if (! is_string($target) || ! str_starts_with($target, '/') || str_starts_with($target, '//') || str_starts_with($target, '/\\')) {
            $target = '/';
        }

        $token = Str::random(48);
        $lifetime = $this->config->directTokenLifetime();

        Cache::put(self::CACHE_PREFIX.$token, [
            'user_id' => $user->id,
            'redirect' => $target,
        ], now()->addSeconds($lifetime));

        $isWemxRoute = $request->is('sso-wemx*') || $request->routeIs('sso-wemx*');
        $redirectUrl = $isWemxRoute && Route::has('sso-wemx.login')
            ? route('sso-wemx.login', ['token' => $token])
            : (Route::has('sso.direct.login') ? route('sso.direct.login', ['token' => $token]) : url('/extensions/sso/direct/'.$token));

        return new JsonResponse([
            'success' => true,
            'redirect' => $redirectUrl,
        ], 200);
    }

    /**
     * Handle the user's browser redirect containing the one-time token.
     * Authenticates the user session and forwards to their destination.
     *
     * @param Request $request
     * @param string $token
     * @return RedirectResponse
     */
    public function handle(Request $request, string $token): RedirectResponse
    {
        $payload = Cache::pull(self::CACHE_PREFIX.$token);
        if (! is_array($payload) || ! isset($payload['user_id'])) {
            return redirect()->to('/auth/login?sso_error=expired');
        }

        $user = User::query()->find($payload['user_id']);
        if (! $user instanceof User) {
            return redirect()->to('/auth/login?sso_error=unlinked');
        }

        if ($user->root_admin && ! $this->config->boolean(SsoSettings::DIRECT_ALLOW_ADMIN)) {
            return redirect()->to('/auth/login?sso_error=admin_denied');
        }

        try {
            $result = $this->logins->complete($user);

            if ($result->complete) {
                $target = is_string($payload['redirect'] ?? null) && $payload['redirect'] !== '/'
                    ? $payload['redirect']
                    : $result->intended;

                return redirect()->to($target);
            }

            $request->session()->put(AuthorizationController::CHECKPOINT, true);

            return redirect()->to('/auth/login?sso=checkpoint');
        } catch (Throwable $throwable) {
            report($throwable);

            return redirect()->to('/auth/login?sso_error=provider');
        }
    }

    /**
     * Resolve the target user from the incoming request attributes.
     *
     * @param Request $request
     * @return User|null
     */
    private function resolveUser(Request $request): ?User
    {
        if ($request->filled('user_id')) {
            return User::query()->find($request->input('user_id'));
        }

        if ($request->filled('email')) {
            return User::query()->where('email', $request->input('email'))->first();
        }

        if ($request->filled('username')) {
            return User::query()->where('username', $request->input('username'))->first();
        }

        return null;
    }
}
