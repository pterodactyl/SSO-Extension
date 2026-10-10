<?php

declare(strict_types=1);

namespace Sso\Services;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Contracts\Users\CreatesUsers;
use Pterodactyl\Models\User;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\Models\Identity;
use Sso\SsoSettings;

final class IdentityResolver
{
    /**
     * @var SsoSettings
     */
    protected SsoSettings $config;

    /**
     * @var CreatesUsers
     */
    protected CreatesUsers $users;

    /**
     * @param SsoSettings
     * @param CreatesUsers
     */
    public function __construct(SsoSettings $config, CreatesUsers $users)
    {
        $this->config = $config;
        $this->users = $users;
    }

    /**
     * @param ExternalIdentity
     * @return User
     */
    public function userFor(ExternalIdentity $identity): User
    {
        $linked = $this->find($identity);
        if ($linked instanceof Identity) {
            $linked->fill([...$identity->details(), 'last_login_at' => now()])->save();

            return $this->withRole($linked->user, $identity);
        }

        $user = $this->userByVerifiedEmail($identity);
        if ($user instanceof User) {
            if (Identity::query()->where('user_id', $user->id)->where('provider', $identity->provider)->exists()) {
                throw new SsoException(SsoException::UNLINKED);
            }

            $this->attach($user, $identity);

            return $this->withRole($user, $identity);
        }

        if ($identity->provision) {
            return $this->provision($identity);
        }

        throw new SsoException(SsoException::UNLINKED);
    }

    /**
     * @param User
     * @param ExternalIdentity
     * @return Identity
     */
    public function link(User $user, ExternalIdentity $identity): Identity
    {
        $owner = $this->find($identity);
        if ($owner instanceof Identity && $owner->user_id !== $user->id) {
            throw new SsoException(SsoException::TAKEN);
        }

        return Identity::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => $identity->provider],
            [...$identity->details(), 'provider_user_id' => $identity->id],
        );
    }

    /**
     * Create a panel account for an identity nobody is linked to. It needs an
     * email, and an email already used on the panel is never merged into.
     *
     * @param ExternalIdentity
     * @return User
     */
    private function provision(ExternalIdentity $identity): User
    {
        if ($identity->email === null) {
            throw new SsoException(SsoException::UNLINKED);
        }

        if (User::query()->where('email', $identity->email)->exists()) {
            throw new SsoException(SsoException::TAKEN);
        }

        $parts = preg_split('/\s+/', mb_trim($identity->name), 2, PREG_SPLIT_NO_EMPTY);
        $first = $parts[0] ?? $identity->id;

        return DB::transaction(function () use ($identity, $first, $parts): User {
            $user = $this->users->create([
                'email' => $identity->email,
                'username' => $this->username($identity),
                'name_first' => $first,
                'name_last' => $parts[1] ?? $first,
                'root_admin' => $identity->admin === true,
            ]);

            $this->attach($user, $identity);

            return $user;
        });
    }

    /**
     * @param User
     * @param ExternalIdentity
     * @return void
     */
    private function attach(User $user, ExternalIdentity $identity): void
    {
        Identity::query()->create([
            ...$identity->details(),
            'user_id' => $user->id,
            'provider' => $identity->provider,
            'provider_user_id' => $identity->id,
            'last_login_at' => now(),
        ]);
    }

    /**
     * @param User
     * @param ExternalIdentity
     * @return User
     */
    private function withRole(User $user, ExternalIdentity $identity): User
    {
        if ($identity->admin !== null && $user->root_admin !== $identity->admin) {
            $user->root_admin = $identity->admin;
            $user->save();
        }

        return $user;
    }

    /**
     * A free username: the provider's own login name when it has one, else the
     * start of the email. Cleaned to what the panel accepts, padded to its three
     * characters minimum and numbered when taken.
     *
     * @param ExternalIdentity
     * @return string
     */
    private function username(ExternalIdentity $identity): string
    {
        $base = $this->cleanUsername($identity->username ?? '');
        if ($base === '') {
            $base = $this->cleanUsername(explode('@', (string) $identity->email)[0]);
        }

        $base = mb_substr(str_pad($base, 3, '0'), 0, 180);

        $username = $base;
        for ($suffix = 1; User::query()->where('username', $username)->exists(); $suffix++) {
            $username = $base.$suffix;
        }

        return $username;
    }

    /**
     * @param string
     * @return string
     */
    private function cleanUsername(string $value): string
    {
        return mb_trim((string) preg_replace('/[^a-z0-9_.-]+/', '', mb_strtolower($value)), '._-');
    }

    /**
     * @param ExternalIdentity
     * @return Identity|null
     */
    private function find(ExternalIdentity $identity): ?Identity
    {
        return Identity::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->id)
            ->first();
    }

    /**
     * @param ExternalIdentity
     * @return User|null
     */
    private function userByVerifiedEmail(ExternalIdentity $identity): ?User
    {
        if (! $this->config->boolean(SsoSettings::LINK_BY_EMAIL) || ! $identity->emailVerified || $identity->email === null) {
            return null;
        }

        return User::query()->where('email', $identity->email)->first();
    }
}
