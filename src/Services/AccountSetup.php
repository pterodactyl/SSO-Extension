<?php

declare(strict_types=1);

namespace Sso\Services;

use Pterodactyl\Models\User;
use Sso\Models\PendingSetup;

/**
 * Accounts that must choose their own details on their first sign-in.
 */
final class AccountSetup
{
    public const string SESSION_EMAIL = 'sso.setup_email';

    /**
     * @param User
     * @return bool
     */
    public function pending(User $user): bool
    {
        return PendingSetup::query()->whereKey($user->id)->exists();
    }

    /**
     * @param User
     */
    public function require(User $user): void
    {
        PendingSetup::query()->firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * @param User
     */
    public function clear(User $user): void
    {
        PendingSetup::query()->whereKey($user->id)->delete();
    }
}
