<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Sso\Contracts\AcceptsAccountIds;
use Sso\Contracts\LooksUpAccounts;
use Sso\Data\ExternalIdentity;
use Sso\Services\AccountIds;
use Throwable;

/**
 * Lets the admin user forms show which provider account an ID belongs to, so a mistyped ID
 * is noticed before anyone signs in with it. Provider credentials stay on the panel.
 */
final class AccountController
{
    private const int FOUND_SECONDS = 600;

    private const int MISSING_SECONDS = 60;

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
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'data' => array_map(fn (AcceptsAccountIds $provider): array => [
                'id' => $provider->id(),
                'name' => $provider->name(),
                'field' => AccountIds::field($provider),
                'pattern' => $provider->accountIdPattern(),
                'hint' => $provider->accountIdHint(),
                'lookup' => $provider instanceof LooksUpAccounts && $provider->canLookUp(),
            ], $this->accounts->providers()),
        ]);
    }

    /**
     * @param string
     * @param string
     * @return JsonResponse
     */
    public function show(string $provider, string $id): JsonResponse
    {
        $accounts = $this->accounts->find($provider);
        if (! $accounts instanceof LooksUpAccounts || ! $accounts->canLookUp()) {
            return $this->error('NotFound', 404, 'Accounts of this provider cannot be looked up.');
        }

        if (! AccountIds::matches($accounts, $id)) {
            return $this->error('ValidationException', 422, 'That is not a valid '.$accounts->name().' ID. '.$accounts->accountIdHint());
        }

        $key = 'extensions.sso.accounts.'.$provider.'.'.$id;
        $account = Cache::get($key);
        if (! is_array($account)) {
            try {
                $identity = $accounts->lookUp($id);
            } catch (Throwable $throwable) {
                report($throwable);

                return $this->error('ServiceUnavailable', 503, $accounts->name().' could not be reached to check this ID.');
            }

            $account = $identity instanceof ExternalIdentity ? ['id' => $identity->id, 'name' => $identity->name, 'avatar_url' => $identity->avatarUrl] : [];
            Cache::put($key, $account, $account === [] ? self::MISSING_SECONDS : self::FOUND_SECONDS);
        }

        if ($account === []) {
            return $this->error('NotFound', 404, 'No '.$accounts->name().' account has this ID.');
        }

        return new JsonResponse(['data' => $account]);
    }

    /**
     * @param string
     * @param int
     * @param string
     * @return JsonResponse
     */
    private function error(string $code, int $status, string $detail): JsonResponse
    {
        return new JsonResponse(['errors' => [['code' => $code, 'status' => (string) $status, 'detail' => $detail]]], $status);
    }
}
