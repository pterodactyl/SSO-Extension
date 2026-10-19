<?php

declare(strict_types=1);

namespace Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pterodactyl\Facades\Extensions;
use Sso\Contracts\AcceptsAccountIds;
use Sso\Services\AccountIds;
use Sso\Services\Placeholders;
use Sso\SsoSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * When an admin creates a user who sets up their account on first sign-in, the email,
 * username, name and password the panel requires may be left blank, or keep the placeholders
 * the admin form filled in. They get unique placeholders here, before the panel validates the
 * request.
 */
final class FillPlaceholderDetails
{
    public const string ROUTE = 'api.admin.users.store';

    /**
     * @var AccountIds
     */
    protected AccountIds $accounts;

    /**
     * @param AccountIds
     */
    public function __construct(AccountIds $accounts)
    {
        $this->accounts = $accounts;
    }

    /**
     * @param Request
     * @param Closure
     * @return Response
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! Extensions::isAvailable(SsoSettings::EXTENSION) || $request->route()?->getName() !== self::ROUTE) {
            return $next($request);
        }

        $values = $request->input('extensions.'.SsoSettings::EXTENSION);
        if (! is_array($values) || filter_var($values[AccountIds::SETUP] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
            return $next($request);
        }

        foreach ($this->accounts->providers() as $provider) {
            $id = $values[AccountIds::field($provider)] ?? null;
            if (AccountIds::matches($provider, $id)) {
                $request->merge($this->placeholders($request, $provider, $id));

                break;
            }
        }

        return $next($request);
    }

    /**
     * @param Request
     * @param AcceptsAccountIds
     * @param string
     * @return array
     */
    private function placeholders(Request $request, AcceptsAccountIds $provider, string $id): array
    {
        $email = $request->input('email');
        $username = $request->input('username');
        $fill = [];

        if (! is_string($email) || mb_trim($email) === '' || Placeholders::isReservedEmail($email)) {
            $fill['email'] = Placeholders::uniqueEmail($provider->id(), $id);
        }

        if (! is_string($username) || mb_trim($username) === '' || Placeholders::isUsernameFor($username, $provider->id(), $id)) {
            $fill['username'] = Placeholders::uniqueUsername($provider->id(), $id);
        }

        if ($this->blank($request->input('name_first'))) {
            $fill['name_first'] = $provider->name();
        }

        if ($this->blank($request->input('name_last'))) {
            $fill['name_last'] = Placeholders::LAST_NAME;
        }

        if ($this->blank($request->input('password'))) {
            $fill['password'] = Placeholders::password();
        }

        return $fill;
    }

    /**
     * @param mixed
     * @return bool
     */
    private function blank(mixed $value): bool
    {
        return ! is_string($value) || mb_trim($value) === '';
    }
}
