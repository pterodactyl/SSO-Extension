<?php

declare(strict_types=1);

namespace Sso;

use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;
use RuntimeException;

final class SsoSettings
{
    public const string LINK_BY_EMAIL = 'link_by_email';

    public const string DIRECT_ENABLED = 'direct_sso_enabled';

    public const string DIRECT_SECRET = 'direct_sso_secret';

    public const string DIRECT_ALLOW_ADMIN = 'direct_sso_allow_admin';

    public const string DIRECT_ALLOW_2FA = 'direct_sso_allow_2fa';

    public const string DIRECT_TOKEN_LIFETIME = 'direct_sso_token_lifetime';

    /**
     * @var ExtensionSettingsRegistry
     */
    protected ExtensionSettingsRegistry $registry;

    /**
     * @param ExtensionSettingsRegistry
     */
    public function __construct(ExtensionSettingsRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param string
     * @return bool
     */
    public function boolean(string $key): bool
    {
        return $this->definition()->get($key) === true;
    }

    /**
     * @param string
     * @return string
     */
    public function string(string $key): string
    {
        $value = $this->definition()->get($key);

        return is_string($value) ? mb_trim($value) : '';
    }

    /**
     * Get the configured direct SSO secret, checking settings first then environment variables.
     *
     * @return string
     */
    public function directSecret(): string
    {
        $secret = $this->string(self::DIRECT_SECRET);
        if ($secret !== '') {
            return $secret;
        }

        $envSecret = env('WEMX_SSO_SECRET', env('DIRECT_SSO_SECRET'));

        return is_string($envSecret) ? mb_trim($envSecret) : '';
    }

    /**
     * Check if direct SSO is enabled either explicitly or via a configured secret.
     *
     * @return bool
     */
    public function directEnabled(): bool
    {
        return $this->boolean(self::DIRECT_ENABLED) || $this->directSecret() !== '';
    }

    /**
     * Get the token lifetime in seconds for direct SSO one-time tokens.
     *
     * @return int
     */
    public function directTokenLifetime(): int
    {
        $raw = (int) $this->string(self::DIRECT_TOKEN_LIFETIME);

        return $raw > 0 ? $raw : 60;
    }

    /**
     * @return ExtensionSettingsDefinition
     */
    private function definition(): ExtensionSettingsDefinition
    {
        return $this->registry->get('sso') ?? throw new RuntimeException('The sso extension settings are not registered.');
    }
}
