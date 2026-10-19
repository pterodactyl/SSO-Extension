<?php

declare(strict_types=1);

namespace Sso;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use Sso\Fields\AccountFields;
use Sso\Http\Middleware\FillPlaceholderDetails;
use Sso\Http\Middleware\RequireAccountSetup;
use Sso\Listeners\SkipReservedAddresses;

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
        $this->registerFields(User::class, AccountFields::class);
        $this->registerAccountSetup();
    }

    /**
     * The panel has no extension API for these yet: the middleware and listeners are added
     * directly and check that the extension is available on every call.
     */
    private function registerAccountSetup(): void
    {
        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', RequireAccountSetup::class);
        $router->pushMiddlewareToGroup('api', RequireAccountSetup::class);
        $router->pushMiddlewareToGroup('admin-api', FillPlaceholderDetails::class);

        Event::listen(NotificationSending::class, [SkipReservedAddresses::class, 'notification']);
        Event::listen(MessageSending::class, [SkipReservedAddresses::class, 'message']);
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
