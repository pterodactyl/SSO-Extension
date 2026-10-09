<?php

declare(strict_types=1);

namespace Sso\Tests\SsoTest;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
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
use Sso\Models\Identity;

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
    configure(['discord_enabled' => true, 'discord_client_id' => 'client-id', 'discord_client_secret' => 'client-secret']);
});

afterEach(function (): void {
    Identity::query()->delete();
    User::query()->whereIn('id', $this->users)->delete();
    $this->app->make(ExtensionRepository::class)->settings('sso')->forgetByPrefix('');
    Extension::query()->where('identifier', 'sso')->delete();
});

test('only enabled and configured providers are offered on the login page', function (): void {
    $this->getJson('/extensions/sso/providers')->assertOk()->assertExactJson(['data' => [
        ['id' => 'discord', 'name' => 'Discord', 'login_url' => '/extensions/sso/discord/redirect'],
    ]]);

    configure(['discord_client_id' => '']);
    $this->getJson('/extensions/sso/providers')->assertOk()->assertExactJson(['data' => []]);
    $this->get('/extensions/sso/discord/redirect')->assertRedirect('/auth/login?sso_error=unavailable');
});

test('starting a login sends the browser to discord with a state bound to the session', function (): void {
    $response = $this->get('/extensions/sso/discord/redirect');

    $location = $response->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('https://discord.com/oauth2/authorize?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray([
        'response_type' => 'code',
        'client_id' => 'client-id',
        'redirect_uri' => url('/extensions/sso/discord/callback'),
        'scope' => 'identify email',
        'prompt' => 'none',
    ]);
    expect($query['state'])->toBe(session('sso.flow.state'));
    expect(session('sso.flow'))->toMatchArray(['provider' => 'discord', 'intent' => 'login']);
});

test('a callback whose state does not match the session is refused', function (): void {
    fakeDiscord();
    $this->get('/extensions/sso/discord/redirect');

    $this->get('/extensions/sso/discord/callback?code=abc&state=forged')->assertRedirect('/auth/login?sso_error=state');
    $this->get('/extensions/sso/discord/callback?code=abc&state=forged')->assertRedirect('/auth/login?sso_error=state');
    Http::assertNothingSent();
    $this->assertGuest();
});

test('a linked discord account signs its user in and refreshes the stored profile', function (): void {
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912', 'name' => 'Old name']);
    fakeDiscord();

    callback($this)->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    $identity = Identity::query()->sole();
    expect($identity->name)->toBe('Nelly');
    expect($identity->avatar_url)->toBe('https://cdn.discordapp.com/avatars/80351110224678912/8342729096ea3675442027381ff50dfe.png');
    expect($identity->last_login_at)->not->toBeNull();
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://discord.com/api/oauth2/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'abc'
        && $request['client_secret'] === 'client-secret'
        && $request['redirect_uri'] === url('/extensions/sso/discord/callback'));
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://discord.com/api/users/@me' && $request->hasHeader('Authorization', 'Bearer discord-token'));
});

test('an unlinked discord account is refused unless email linking is enabled and the email is verified', function (): void {
    $user = user(['email' => 'nelly@discord.test']);
    fakeDiscord();

    callback($this)->assertRedirect('/auth/login?sso_error=unlinked');
    $this->assertGuest();

    configure(['link_by_email' => true]);
    fakeDiscord(['verified' => false]);
    callback($this)->assertRedirect('/auth/login?sso_error=unlinked');
    $this->assertGuest();

    fakeDiscord();
    callback($this)->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    expect(Identity::query()->sole()->only(['user_id', 'provider', 'provider_user_id', 'email']))->toBe([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '80351110224678912',
        'email' => 'nelly@discord.test',
    ]);
});

test('accounts with two factor authentication are handed to the native checkpoint', function (): void {
    $user = user(['use_totp' => true, 'totp_secret' => encrypt(str_repeat('a', 16))]);
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912']);
    fakeDiscord();

    callback($this)->assertRedirect('/auth/login?sso=checkpoint');

    $this->assertGuest();
    $token = $this->getJson('/extensions/sso/checkpoint')->assertOk()->json('data.confirmation_token');
    expect($token)->toBe($this->app->make(CompletesLogins::class)->pendingCheckpoint()?->token);
});

test('the checkpoint token is only revealed for a sign-in this extension started', function (): void {
    $this->app->make(CompletesLogins::class)->complete(user(['use_totp' => true, 'totp_secret' => encrypt(str_repeat('a', 16))]));

    $this->getJson('/extensions/sso/checkpoint')->assertNotFound();
});

test('provider failures and cancelled consent return to the login page with a reason', function (): void {
    Http::fake(['discord.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    callback($this)->assertRedirect('/auth/login?sso_error=provider');

    $this->get('/extensions/sso/discord/redirect');
    $this->get('/extensions/sso/discord/callback?error=access_denied&state='.session('sso.flow.state'))->assertRedirect('/auth/login?sso_error=denied');
    $this->assertGuest();
});

test('a signed in user can link a discord account that nobody else has linked', function (): void {
    $user = user();
    $other = user();
    fakeDiscord();

    $this->actingAs($user);
    callback($this, 'link')->assertRedirect('/account/connections?sso_linked=discord');
    expect(Identity::query()->sole()->user_id)->toBe($user->id);

    $this->actingAs($other);
    callback($this, 'link')->assertRedirect('/account/connections?sso_error=taken');
    expect(Identity::query()->count())->toBe(1);
});

test('guests cannot start linking an account', function (): void {
    $this->withHeader('Accept', 'text/html')->get('/extensions/sso/discord/link')->assertRedirect('/auth/login');
    $this->getJson('/extensions/sso/discord/link')->assertUnauthorized();
    expect(session()->has('sso.flow'))->toBeFalse();
});

test('users can list and remove their connections', function (): void {
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '1', 'name' => 'Nelly', 'email' => 'nelly@discord.test']);
    Identity::query()->create(['user_id' => user()->id, 'provider' => 'discord', 'provider_user_id' => '2']);

    $this->actingAs($user)->getJson('/api/client/extensions/sso/connections')->assertOk()->assertJsonPath('data.0', [
        'id' => 'discord',
        'name' => 'Discord',
        'enabled' => true,
        'link_url' => '/extensions/sso/discord/link',
        'account' => [
            'name' => 'Nelly',
            'email' => 'nelly@discord.test',
            'avatar_url' => null,
            'linked_at' => Identity::query()->where('user_id', $user->id)->sole()->created_at->toAtomString(),
        ],
    ]);

    $this->actingAs($user)->deleteJson('/api/client/extensions/sso/connections/discord')->assertNoContent();
    expect(Identity::query()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(Identity::query()->count())->toBe(1);
});

test('PKCE S256 matches the RFC 7636 example', function (): void {
    expect(\Sso\Pkce::challenge(
        'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
    ))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

/**
 * @param array
 */
function configure(array $values): void
{
    app(ExtensionSettingsRegistry::class)->get('sso')?->update($values);
}

/**
 * @param array
 * @return User
 */
function user(array $attributes = []): User
{
    return (function () use ($attributes): User {
        $user = User::factory()->create($attributes);
        $this->users[] = $user->id;

        return $user;
    })->call(pterodactylTestCase());
}

/**
 * @param array
 */
function fakeDiscord(array $user = []): void
{
    Http::swap(new Factory);
    Http::fake([
        'discord.com/api/oauth2/token' => Http::response(['access_token' => 'discord-token', 'token_type' => 'Bearer']),
        'discord.com/api/users/@me' => Http::response([
            'id' => '80351110224678912',
            'username' => 'nelly',
            'global_name' => 'Nelly',
            'avatar' => '8342729096ea3675442027381ff50dfe',
            'email' => 'nelly@discord.test',
            'verified' => true,
            ...$user,
        ]),
    ]);
}

/**
 * @param IntegrationTestCase
 * @param string
 * @return TestResponse
 */
function callback(IntegrationTestCase $test, string $intent = 'login'): TestResponse
{
    $test->get($intent === 'link' ? '/extensions/sso/discord/link' : '/extensions/sso/discord/redirect')->assertRedirect();

    return $test->get('/extensions/sso/discord/callback?code=abc&state='.session('sso.flow.state'));
}
