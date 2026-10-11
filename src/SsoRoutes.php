<?php

declare(strict_types=1);

namespace Sso;

final class SsoRoutes
{
    public const string PREFIX = '/extensions/sso';

    public const string OIDC_ADMIN = '/panel/oidc-providers';

    /**
     * @param string
     * @return string
     */
    public static function login(string $provider): string
    {
        return self::PREFIX.'/'.$provider.'/redirect';
    }

    /**
     * @param string
     * @return string
     */
    public static function link(string $provider): string
    {
        return self::PREFIX.'/'.$provider.'/link';
    }

    /**
     * @param string
     * @return string
     */
    public static function callback(string $provider): string
    {
        return url(self::PREFIX.'/'.$provider.'/callback');
    }
}
