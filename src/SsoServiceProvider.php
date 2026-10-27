<?php

declare(strict_types=1);

namespace Sso;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;
use Sso\Commands\GenerateDirectSecretCommand;
use Sso\Commands\WemxGenerateCommand;
use Sso\Http\Controllers\DirectSsoController;

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
            ExtensionSettingDefinition::make(SsoSettings::DIRECT_ENABLED, SsoSettings::DIRECT_ENABLED, false, ['boolean'])
                ->label('Enable Direct SSO')
                ->tab('Direct SSO')
                ->help('Allow external billing platforms (WemX, Paymenter, WHMCS, etc.) and applications to directly sign users in using secure single-use tokens.')
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make(SsoSettings::DIRECT_SECRET, SsoSettings::DIRECT_SECRET, '', ['nullable', 'string', 'max:255'])
                ->label('Direct SSO Secret')
                ->tab('Direct SSO')
                ->help('Shared secret key for authenticating token requests from billing software. Can also be set via WEMX_SSO_SECRET or DIRECT_SSO_SECRET in .env.')
                ->secret(),
            ExtensionSettingDefinition::make(SsoSettings::DIRECT_ALLOW_ADMIN, SsoSettings::DIRECT_ALLOW_ADMIN, false, ['boolean'])
                ->label('Allow Admin Accounts')
                ->tab('Direct SSO')
                ->help('Allow administrator accounts to be signed in via direct SSO. Keep disabled for security.')
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make(SsoSettings::DIRECT_ALLOW_2FA, SsoSettings::DIRECT_ALLOW_2FA, false, ['boolean'])
                ->label('Allow Accounts With 2FA')
                ->tab('Direct SSO')
                ->help('When enabled, accounts with Two-Factor Authentication will be forwarded to the 2FA checkpoint code verification. When disabled, SSO requests for 2FA-enabled accounts are rejected (standard WemX behavior).')
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make(SsoSettings::DIRECT_TOKEN_LIFETIME, SsoSettings::DIRECT_TOKEN_LIFETIME, '60', ['nullable', 'numeric', 'min:10', 'max:3600'])
                ->label('Token Lifetime (seconds)')
                ->tab('Direct SSO')
                ->help('Number of seconds a generated one-time SSO login token is valid for before expiring (default: 60).'),
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

        $this->commands([
            GenerateDirectSecretCommand::class,
            WemxGenerateCommand::class,
        ]);

        Route::middleware(['web'])->group(function () {
            Route::match(['get', 'post'], '/sso-wemx', [DirectSsoController::class, 'webhook'])->name('sso-wemx');
            Route::get('/sso-wemx/{token}', [DirectSsoController::class, 'handle'])->where('token', '[A-Za-z0-9_-]+')->name('sso-wemx.login');
        });
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
