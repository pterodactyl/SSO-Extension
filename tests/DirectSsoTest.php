<?php

declare(strict_types=1);

namespace Sso\Tests\DirectSsoTest;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Events\ActivityLogged;
use Pterodactyl\Events\Auth\DirectLogin;
use Pterodactyl\Models\Extension;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionManager;
use Pterodactyl\Services\Extensions\ExtensionProviderLoader;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;
use Pterodactyl\Tests\Integration\IntegrationTestCase;

use function pterodactylTestCase;

uses(IntegrationTestCase::class);

beforeEach(function (): void {
    config([
        'extensions.enabled' => true,
        'extensions.directory' => dirname(__DIR__, 2),
        'session.driver' => 'array',
    ]);
    Event::fake([DirectLogin::class, ActivityLogged::class]);
    Extension::query()->where('identifier', 'sso')->delete();
    Extension::query()->create(['identifier' => 'sso', 'version' => '1.1.0', 'enabled' => true]);
    $this->app->forgetInstance(ExtensionRepository::class);
    $this->app->forgetInstance(ExtensionProviderLoader::class);
    $this->app->forgetInstance(ExtensionManager::class);
    $this->app->make(ExtensionProviderLoader::class)->registerProviders($this->app->make(ExtensionRepository::class)->enabled());
    Route::getRoutes()->refreshNameLookups();
    if (! Schema::hasTable('sso_identities')) {
        (require dirname(__DIR__).'/database/migrations/2026_10_05_000000_create_sso_identities_table.php')->up();
    }

    $this->users = [];
    configure([
        'direct_sso_enabled' => true,
        'direct_sso_secret' => 'test-direct-secret',
        'direct_sso_allow_admin' => false,
        'direct_sso_allow_2fa' => false,
    ]);
});

afterEach(function (): void {
    User::query()->whereIn('id', $this->users)->delete();
    $this->app->make(ExtensionRepository::class)->settings('sso')->forgetByPrefix('');
    Extension::query()->where('identifier', 'sso')->delete();
});

test('direct sso returns 403 when disabled', function (): void {
    configure(['direct_sso_enabled' => false, 'direct_sso_secret' => '']);

    $this->getJson('/sso-wemx?sso_secret=test-direct-secret&user_id=1')
        ->assertStatus(403)
        ->assertJson(['success' => false, 'message' => 'Direct SSO is currently disabled on this panel.']);

    $this->getJson('/extensions/sso/direct?sso_secret=test-direct-secret&user_id=1')
        ->assertStatus(403);
});

test('direct sso rejects requests with invalid secret', function (): void {
    $this->getJson('/sso-wemx?sso_secret=wrong-secret&user_id=1')
        ->assertStatus(403)
        ->assertJson(['success' => false, 'message' => 'Please provide valid credentials.']);

    $this->postJson('/extensions/sso/direct', ['sso_secret' => 'wrong', 'user_id' => 1])
        ->assertStatus(403);
});

test('direct sso rejects unknown user', function (): void {
    $this->getJson('/sso-wemx?sso_secret=test-direct-secret&user_id=999999')
        ->assertStatus(404)
        ->assertJson(['success' => false, 'message' => 'User could not be found.']);
});

test('direct sso blocks admin logins by default', function (): void {
    $admin = directUser(['root_admin' => true]);

    $this->getJson('/sso-wemx?sso_secret=test-direct-secret&user_id='.$admin->id)
        ->assertStatus(403)
        ->assertJson(['success' => false, 'message' => 'You cannot automatically login to admin accounts.']);
});

test('direct sso allows admin logins when direct_sso_allow_admin is enabled', function (): void {
    configure(['direct_sso_allow_admin' => true]);
    $admin = directUser(['root_admin' => true]);

    $response = $this->getJson('/sso-wemx?sso_secret=test-direct-secret&user_id='.$admin->id);
    $response->assertOk()
        ->assertJson(['success' => true]);

    $redirectUrl = $response->json('redirect');
    expect($redirectUrl)->not->toBeEmpty();
});

test('direct sso rejects 2FA accounts when direct_sso_allow_2fa is false', function (): void {
    $user = directUser(['use_totp' => true, 'totp_secret' => encrypt(str_repeat('a', 16))]);

    $this->getJson('/sso-wemx?sso_secret=test-direct-secret&user_id='.$user->id)
        ->assertStatus(501)
        ->assertJson(['success' => false, 'message' => 'Logging into accounts with 2 Factor Authentication enabled is not supported.']);
});

test('direct sso supports 2FA checkpoint when direct_sso_allow_2fa is true', function (): void {
    configure(['direct_sso_allow_2fa' => true]);
    $user = directUser(['use_totp' => true, 'totp_secret' => encrypt(str_repeat('a', 16))]);

    $res = $this->getJson('/sso-wemx?sso_secret=test-direct-secret&user_id='.$user->id)->assertOk();
    $redirectUrl = $res->json('redirect');

    $parsedPath = parse_url($redirectUrl, PHP_URL_PATH);
    $this->get($parsedPath)->assertRedirect('/auth/login?sso=checkpoint');
    $this->assertGuest();
});

test('successful direct sso webhook issues token and logs in the user via redirect', function (): void {
    $user = directUser();

    $response = $this->getJson('/sso-wemx?sso_secret=test-direct-secret&user_id='.$user->id);
    $response->assertOk()
        ->assertJson(['success' => true]);

    $redirectUrl = $response->json('redirect');
    $parsedPath = parse_url($redirectUrl, PHP_URL_PATH);

    $loginResponse = $this->get($parsedPath);
    $loginResponse->assertRedirect('/');
    $this->assertAuthenticatedAs($user);

    // Token must be single use
    $this->get($parsedPath)->assertRedirect('/auth/login?sso_error=expired');
});

test('direct sso supports finding user by email or username', function (): void {
    $user = directUser(['email' => 'client@billing.test', 'username' => 'billingclient']);

    $resEmail = $this->postJson('/extensions/sso/direct', [
        'sso_secret' => 'test-direct-secret',
        'email' => 'client@billing.test',
    ])->assertOk();
    expect($resEmail->json('success'))->toBeTrue();

    $resUsername = $this->postJson('/extensions/sso/direct', [
        'sso_secret' => 'test-direct-secret',
        'username' => 'billingclient',
    ])->assertOk();
    expect($resUsername->json('success'))->toBeTrue();
});

test('direct sso respects custom redirect parameter safely', function (): void {
    $user = directUser();

    $response = $this->getJson('/sso-wemx?sso_secret=test-direct-secret&user_id='.$user->id.'&redirect=/server/abcdef12');
    $response->assertOk();
    $parsedPath = parse_url($response->json('redirect'), PHP_URL_PATH);

    $this->get($parsedPath)->assertRedirect('/server/abcdef12');
    $this->assertAuthenticatedAs($user);
});

/**
 * Helper to configure settings.
 */
function configure(array $values): void
{
    app(ExtensionSettingsRegistry::class)->get('sso')?->update($values);
}

/**
 * Helper to create a user.
 */
function directUser(array $attributes = []): User
{
    return (function () use ($attributes): User {
        $user = User::factory()->create($attributes);
        $this->users[] = $user->id;

        return $user;
    })->call(pterodactylTestCase());
}
