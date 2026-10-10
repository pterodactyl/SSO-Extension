<?php

declare(strict_types=1);

namespace Sso;

use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;
use RuntimeException;

final class SsoSettings
{
    public const string LINK_BY_EMAIL = 'link_by_email';

    public const string OIDC_ENABLED = 'oidc_enabled';

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
     * @return ExtensionSettingsDefinition
     */
    private function definition(): ExtensionSettingsDefinition
    {
        return $this->registry->get('sso') ?? throw new RuntimeException('The sso extension settings are not registered.');
    }
}
