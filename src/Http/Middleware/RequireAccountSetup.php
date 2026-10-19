<?php

declare(strict_types=1);

namespace Sso\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Facades\Extensions;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Models\User;
use Sso\Services\AccountSetup;
use Sso\SsoRoutes;
use Sso\SsoSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an account that has to finish its setup on the setup screen: pages redirect to it and
 * everything else is refused, apart from signing in and out and the setup itself.
 */
final class RequireAccountSetup
{
    public const string CODE = 'SsoSetupRequired';

    private const array WEB = [
        'auth/*',
        'locales/*',
        'sanctum/*',
        'extension-files/*',
        'extensions/sso/providers',
        'extensions/sso/checkpoint',
        'extensions/sso/*/redirect',
        'extensions/sso/*/callback',
        'account/setup',
    ];

    private const array API = [
        'api/client/extensions/sso/setup',
    ];

    private const array TWO_FACTOR = [
        'account',
        'api/client/account',
        'api/client/account/two-factor',
        'api/client/account/two-factor/*',
    ];

    /**
     * @var AccountSetup
     */
    protected AccountSetup $setup;

    /**
     * @param AccountSetup
     */
    public function __construct(AccountSetup $setup)
    {
        $this->setup = $setup;
    }

    /**
     * @param Request
     * @param Closure
     * @return Response
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (! $user instanceof User || ! Extensions::isAvailable(SsoSettings::EXTENSION) || $this->allowed($request, $user) || ! $this->setup->pending($user)) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson() || ! $request->isMethodSafe()) {
            return new JsonResponse(['errors' => [[
                'code' => self::CODE,
                'status' => '409',
                'detail' => 'Finish setting up your account before you continue.',
            ]]], 409);
        }

        return redirect()->to(SsoRoutes::SETUP);
    }

    /**
     * @param Request
     * @param User
     * @return bool
     */
    private function allowed(Request $request, User $user): bool
    {
        if ($request->is(...self::WEB, ...self::API)) {
            return true;
        }

        // The panel's two-factor requirement comes first: it sends the user to their account
        // page to enable it, so that page has to stay reachable.
        return $this->needsTwoFactor($user) && $request->is(...self::TWO_FACTOR);
    }

    /**
     * @param User
     * @return bool
     */
    private function needsTwoFactor(User $user): bool
    {
        $level = config('pterodactyl.auth.2fa_required');

        return ! $user->use_totp && ($level === RequireTwoFactorAuthentication::LEVEL_ALL || ($level === RequireTwoFactorAuthentication::LEVEL_ADMIN && $user->root_admin));
    }
}
