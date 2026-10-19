<?php

declare(strict_types=1);

namespace Sso\Services;

use Pterodactyl\Models\User;
use Sso\Contracts\AcceptsAccountIds;
use Sso\Contracts\IdentityProvider;
use Sso\Models\Identity;
use Sso\ProviderRegistry;

/**
 * The provider account IDs admins set on the user forms.
 */
final class AccountIds
{
    public const string SETUP = 'setup_on_first_login';

    /**
     * @var ProviderRegistry
     */
    protected ProviderRegistry $providers;

    /**
     * @param ProviderRegistry
     */
    public function __construct(ProviderRegistry $providers)
    {
        $this->providers = $providers;
    }

    /**
     * The enabled providers whose account IDs admins can set.
     *
     * @return array
     */
    public function providers(): array
    {
        return array_values(array_filter($this->providers->enabled(), fn (IdentityProvider $provider): bool => $provider instanceof AcceptsAccountIds));
    }

    /**
     * @param string
     * @return AcceptsAccountIds|null
     */
    public function find(string $id): ?AcceptsAccountIds
    {
        foreach ($this->providers() as $provider) {
            if ($provider->id() === $id) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @param AcceptsAccountIds
     * @return string
     */
    public static function field(AcceptsAccountIds $provider): string
    {
        return $provider->id().'_id';
    }

    /**
     * @param AcceptsAccountIds
     * @param mixed
     * @return bool
     */
    public static function matches(AcceptsAccountIds $provider, mixed $id): bool
    {
        return is_string($id) && preg_match('/'.addcslashes($provider->accountIdPattern(), '/').'/D', $id) === 1;
    }

    /**
     * Links the account with this ID to the user, replacing the one linked before, or unlinks
     * the provider when the ID is null. A replaced account's stored profile is cleared, since it
     * belonged to the old account.
     *
     * @param User
     * @param string
     * @param string|null
     */
    public function assign(User $user, string $provider, ?string $id): void
    {
        $identity = Identity::query()->where('user_id', $user->id)->where('provider', $provider)->first();
        if ($id === null) {
            $identity?->delete();

            return;
        }

        if ($identity === null) {
            Identity::query()->create(['user_id' => $user->id, 'provider' => $provider, 'provider_user_id' => $id]);

            return;
        }

        if ($identity->provider_user_id !== $id) {
            $identity->fill(['provider_user_id' => $id, 'name' => null, 'email' => null, 'avatar_url' => null, 'last_login_at' => null])->save();
        }
    }
}
