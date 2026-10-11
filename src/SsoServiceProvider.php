<?php

declare(strict_types=1);

namespace Sso;

use Illuminate\Support\Facades\File;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

final class SsoServiceProvider extends ExtensionProvider
{
    /**
     * @inheritdoc
     */
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);

        $registry = $this->app->make(ProviderRegistry::class);
        foreach ($this->identityProviders() as $provider) {
            $registry->register($provider);
        }

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ...$registry->settings(),
            ExtensionSettingDefinition::make(SsoSettings::LINK_BY_EMAIL, SsoSettings::LINK_BY_EMAIL, false, ['boolean'])
                ->label('Link accounts by verified email')
                ->help('Sign in a user whose panel email matches the verified email of an external account that is not linked yet, and link it. Only enable this for providers that verify email addresses.')
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make(SsoSettings::OIDC_ENABLED, SsoSettings::OIDC_ENABLED, false, ['boolean'])
                ->label('Enable OpenID Connect')
                ->help('Turns on every OpenID Connect server and shows the Admin > OpenID Connect page ('.SsoRoutes::OIDC_ADMIN.'), where servers are added. They are not configured in this menu because there can be any number of them. Reload the panel after changing this.')
                ->field('toggle')
                ->frontend()
                ->frontendType('boolean')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
        ]));
    }

    /**
     * @inheritdoc
     */
    public function boot(): void
    {
        $this->loadExtensionMigrations();
        $this->registerWebRoutes($this->extensionPath('routes', 'web.php'));
        $this->registerAuthenticatedWebRoutes($this->extensionPath('routes', 'account.php'));
        $this->registerApiRoutes();
    }

    /**
     * @return array
     */
    private function identityProviders(): array
    {
        $names = array_map('basename', File::directories($this->extensionPath('providers')));
        sort($names);

        return array_map(fn (string $name): string => 'Sso\\Providers\\'.$name.'\\'.$name.'Provider', $names);
    }
}
