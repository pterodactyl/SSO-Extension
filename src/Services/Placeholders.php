<?php

declare(strict_types=1);

namespace Sso\Services;

use Illuminate\Support\Str;
use Pterodactyl\Models\User;

/**
 * Details for accounts an admin creates for a provider account, until the user replaces them
 * on their first sign-in. Emails use the reserved .invalid top-level domain (RFC 2606), so
 * they can never be delivered.
 */
final class Placeholders
{
    public const string RESERVED_TLD = 'invalid';

    public const string LAST_NAME = 'User';

    /**
     * @param string|null
     * @return bool
     */
    public static function isReservedEmail(?string $email): bool
    {
        if (! is_string($email) || ! str_contains($email, '@')) {
            return false;
        }

        $domain = mb_strtolower(mb_rtrim(mb_trim(Str::afterLast($email, '@')), '.'));

        return $domain === self::RESERVED_TLD || str_ends_with($domain, '.'.self::RESERVED_TLD);
    }

    /**
     * @param string
     * @param string
     * @return string
     */
    public static function email(string $provider, string $id): string
    {
        return $id.'@'.$provider.'.'.self::RESERVED_TLD;
    }

    /**
     * @param string
     * @param string
     * @return string
     */
    public static function username(string $provider, string $id): string
    {
        return $provider.'-'.$id;
    }

    /**
     * Whether the username is one uniqueUsername() picks for this account.
     *
     * @param string
     * @param string
     * @param string
     * @return bool
     */
    public static function isUsernameFor(string $username, string $provider, string $id): bool
    {
        return preg_match('/^'.preg_quote(self::username($provider, $id), '/').'(-[0-9]+)?$/D', mb_strtolower($username)) === 1;
    }

    /**
     * @param string
     * @param string
     * @return string
     */
    public static function uniqueEmail(string $provider, string $id): string
    {
        $email = self::email($provider, $id);
        for ($n = 2; User::query()->where('email', $email)->exists(); $n++) {
            $email = self::email($provider, $id.'+'.$n);
        }

        return $email;
    }

    /**
     * @param string
     * @param string
     * @return string
     */
    public static function uniqueUsername(string $provider, string $id): string
    {
        $username = self::username($provider, $id);
        for ($n = 2; User::query()->where('username', $username)->exists(); $n++) {
            $username = self::username($provider, $id).'-'.$n;
        }

        return $username;
    }

    /**
     * @return string
     */
    public static function password(): string
    {
        return Str::password(64);
    }
}
