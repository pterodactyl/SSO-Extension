<?php

declare(strict_types=1);

namespace Sso\Tests\SsoTest;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
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
use Sso\Models\OidcProvider;

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

    if (! Schema::hasTable('sso_oidc_providers')) {
        (require dirname(__DIR__).'/database/migrations/2026_10_11_000000_create_sso_oidc_providers_table.php')->up();
    }

    $this->users = [];
    configure(['discord_enabled' => true, 'discord_client_id' => 'client-id', 'discord_client_secret' => 'client-secret', 'oidc_enabled' => true]);
});

afterEach(function (): void {
    Identity::query()->delete();
    OidcProvider::query()->delete();
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
function callback(IntegrationTestCase $test, string $intent = 'login', string $provider = 'discord'): TestResponse
{
    $test->get('/extensions/sso/'.$provider.($intent === 'link' ? '/link' : '/redirect'))->assertRedirect();

    return $test->get('/extensions/sso/'.$provider.'/callback?code=abc&state='.session('sso.flow.state'));
}

/**
 * @param array
 * @return OidcProvider
 */
function oidc(array $attributes = []): OidcProvider
{
    return OidcProvider::query()->create([
        'slug' => 'corp',
        'label' => 'Corp SSO',
        'issuer' => 'https://id.example.test/realm',
        'client_id' => 'oidc-client',
        'client_secret' => 'oidc-secret',
        'scopes' => 'openid email profile',
        'groups_claim' => 'groups',
        'admin_groups' => [],
        'member_groups' => [],
        'create_users' => false,
        'enabled' => true,
        ...$attributes,
    ]);
}

/**
 * @param array
 * @param array
 */
function fakeOidc(array $user = [], array $discovery = []): void
{
    Http::swap(new Factory);
    Http::fake([
        'id.example.test/realm/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://id.example.test/realm',
            'authorization_endpoint' => 'https://id.example.test/authorize',
            'token_endpoint' => 'https://id.example.test/token',
            'userinfo_endpoint' => 'https://id.example.test/userinfo',
            ...$discovery,
        ]),
        'id.example.test/token' => Http::response(['access_token' => 'oidc-token', 'token_type' => 'Bearer', 'id_token' => 'ignored']),
        'id.example.test/userinfo' => Http::response([
            'sub' => 'subject-1',
            'name' => 'Jane Doe',
            'preferred_username' => 'jane',
            'email' => 'jane@corp.test',
            'email_verified' => true,
            'picture' => 'https://id.example.test/jane.png',
            ...$user,
        ]),
    ]);
}

test('a configured openid connect server is offered under its own label and id', function (): void {
    oidc();
    oidc(['slug' => 'hidden', 'label' => 'Hidden', 'enabled' => false]);

    $this->getJson('/extensions/sso/providers')->assertOk()->assertJsonFragment(
        ['id' => 'oidc-corp', 'name' => 'Corp SSO', 'login_url' => '/extensions/sso/oidc-corp/redirect']
    )->assertJsonMissing(['id' => 'oidc-hidden']);
});

test('several openid connect servers work side by side', function (): void {
    oidc();
    oidc(['slug' => 'partners', 'label' => 'Partners', 'issuer' => 'https://id.example.test/partners']);

    $ids = collect($this->getJson('/extensions/sso/providers')->assertOk()->json('data'))->pluck('id');
    expect($ids->all())->toContain('oidc-corp', 'oidc-partners');
});

test('openid connect sends the browser to the endpoint found by discovery', function (): void {
    oidc();
    fakeOidc();

    $location = $this->get('/extensions/sso/oidc-corp/redirect')->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://id.example.test/authorize?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query)->toMatchArray([
        'response_type' => 'code',
        'client_id' => 'oidc-client',
        'redirect_uri' => url('/extensions/sso/oidc-corp/callback'),
        'scope' => 'openid email profile',
    ]);
    expect($query)->not->toHaveKey('prompt');
});

test('openid connect scopes always include openid', function (): void {
    oidc(['scopes' => 'email groups']);
    fakeOidc();

    $location = $this->get('/extensions/sso/oidc-corp/redirect')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('openid email groups');
});

test('an unreachable or inconsistent issuer returns to the login page with a provider error', function (): void {
    oidc();
    Http::swap(new Factory);
    Http::fake(['id.example.test/*' => Http::response('down', 503)]);

    $this->get('/extensions/sso/oidc-corp/redirect')->assertRedirect('/auth/login?sso_error=provider');
    expect(session()->has('sso.flow'))->toBeFalse();

    fakeOidc(discovery: ['issuer' => 'https://evil.example.test']);
    $this->get('/extensions/sso/oidc-corp/redirect')->assertRedirect('/auth/login?sso_error=provider');

    fakeOidc(discovery: ['token_endpoint' => 'http://id.example.test/token']);
    $this->get('/extensions/sso/oidc-corp/redirect')->assertRedirect('/auth/login?sso_error=provider');
});

test('a linked openid connect account signs in and is identified by sub', function (): void {
    oidc();
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'oidc-corp', 'provider_user_id' => 'subject-1']);
    fakeOidc();

    callback($this, provider: 'oidc-corp')->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(Identity::query()->sole()->only(['name', 'email', 'avatar_url']))->toBe([
        'name' => 'Jane Doe',
        'email' => 'jane@corp.test',
        'avatar_url' => 'https://id.example.test/jane.png',
    ]);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://id.example.test/token'
        && $request['client_secret'] === 'oidc-secret'
        && $request['code'] === 'abc');
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://id.example.test/userinfo' && $request->hasHeader('Authorization', 'Bearer oidc-token'));
});

test('an openid connect email is not trusted unless the provider says it is verified', function (): void {
    oidc();
    user(['email' => 'jane@corp.test']);
    configure(['link_by_email' => true]);
    fakeOidc(['email_verified' => 'true']);

    callback($this, provider: 'oidc-corp')->assertRedirect('/auth/login?sso_error=unlinked');
    $this->assertGuest();
});

test('groups decide the role at every sign in', function (): void {
    oidc(['admin_groups' => ['panel-admins'], 'member_groups' => ['panel-users']]);
    $user = user(['root_admin' => false]);
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'oidc-corp', 'provider_user_id' => 'subject-1']);

    fakeOidc(['groups' => ['everyone', 'panel-admins']]);
    callback($this, provider: 'oidc-corp')->assertRedirect('/');
    expect($user->refresh()->root_admin)->toBeTrue();

    auth()->logout();
    fakeOidc(['groups' => ['panel-users']]);
    callback($this, provider: 'oidc-corp')->assertRedirect('/');
    expect($user->refresh()->root_admin)->toBeFalse();
});

test('a user in none of the configured groups is refused', function (): void {
    oidc(['admin_groups' => ['panel-admins'], 'member_groups' => ['panel-users']]);
    $user = user(['root_admin' => true]);
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'oidc-corp', 'provider_user_id' => 'subject-1']);
    fakeOidc(['groups' => ['interns']]);

    callback($this, provider: 'oidc-corp')->assertRedirect('/auth/login?sso_error=forbidden');

    $this->assertGuest();
    expect($user->refresh()->root_admin)->toBeTrue();
});

test('without an admin group everyone else is a member, and a claim can be nested', function (): void {
    oidc(['admin_groups' => ['ops'], 'groups_claim' => 'realm_access.roles']);
    $user = user(['root_admin' => true]);
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'oidc-corp', 'provider_user_id' => 'subject-1']);
    fakeOidc(['realm_access' => ['roles' => ['dev']]]);

    callback($this, provider: 'oidc-corp')->assertRedirect('/');

    expect($user->refresh()->root_admin)->toBeFalse();
});

test('roles are left alone when no groups are configured', function (): void {
    oidc();
    $user = user(['root_admin' => true]);
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'oidc-corp', 'provider_user_id' => 'subject-1']);
    fakeOidc(['groups' => ['whatever']]);

    callback($this, provider: 'oidc-corp')->assertRedirect('/');

    expect($user->refresh()->root_admin)->toBeTrue();
});

test('an unknown openid connect user gets an account only when the server allows it', function (): void {
    oidc();
    fakeOidc();
    callback($this, provider: 'oidc-corp')->assertRedirect('/auth/login?sso_error=unlinked');
    expect(User::query()->where('email', 'jane@corp.test')->exists())->toBeFalse();

    OidcProvider::query()->update(['create_users' => true, 'admin_groups' => ['panel-admins']]);
    fakeOidc(['groups' => ['panel-admins'], 'preferred_username' => 'J.Doe']);
    callback($this, provider: 'oidc-corp')->assertRedirect('/');

    $created = User::query()->where('email', 'jane@corp.test')->sole();
    $this->users[] = $created->id;
    $this->assertAuthenticatedAs($created);
    expect($created->only(['username', 'name_first', 'name_last', 'root_admin']))->toBe([
        'username' => 'j.doe',
        'name_first' => 'Jane',
        'name_last' => 'Doe',
        'root_admin' => true,
    ]);
    expect(Identity::query()->sole()->only(['user_id', 'provider', 'provider_user_id']))->toBe([
        'user_id' => $created->id,
        'provider' => 'oidc-corp',
        'provider_user_id' => 'subject-1',
    ]);
});

test('account creation needs an email and never merges into an existing account', function (): void {
    oidc(['create_users' => true]);
    fakeOidc(['email' => null]);
    callback($this, provider: 'oidc-corp')->assertRedirect('/auth/login?sso_error=unlinked');

    $existing = user(['email' => 'jane@corp.test']);
    fakeOidc();
    callback($this, provider: 'oidc-corp')->assertRedirect('/auth/login?sso_error=taken');

    $this->assertGuest();
    expect(Identity::query()->count())->toBe(0);
    expect(User::query()->where('email', 'jane@corp.test')->sole()->id)->toBe($existing->id);
});

test('a created account gets a free username', function (): void {
    oidc(['create_users' => true]);
    user(['username' => 'jane']);
    fakeOidc();

    callback($this, provider: 'oidc-corp')->assertRedirect('/');

    $created = User::query()->where('email', 'jane@corp.test')->sole();
    $this->users[] = $created->id;
    expect($created->username)->toBe('jane1');
});

test('only openid connect servers can create accounts', function (): void {
    oidc(['create_users' => true]);
    fakeDiscord();

    callback($this)->assertRedirect('/auth/login?sso_error=unlinked');

    expect(User::query()->where('email', 'nelly@discord.test')->exists())->toBeFalse();
});

test('administrators manage openid connect servers and the secret is never returned', function (): void {
    $admin = user(['root_admin' => true]);
    $payload = [
        'slug' => 'corp',
        'label' => 'Corp SSO',
        'issuer' => 'https://id.example.test/realm',
        'client_id' => 'oidc-client',
        'client_secret' => 'oidc-secret',
        'admin_groups' => ['panel-admins'],
        'create_users' => true,
    ];

    $this->actingAs($admin)->postJson('/api/admin/extensions/sso/oidc-providers', $payload)
        ->assertCreated()
        ->assertJsonPath('data.provider_id', 'oidc-corp')
        ->assertJsonPath('data.has_secret', true)
        ->assertJsonPath('data.callback_url', url('/extensions/sso/oidc-corp/callback'))
        ->assertJsonMissingPath('data.client_secret');

    $record = OidcProvider::query()->sole();
    expect($record->client_secret)->toBe('oidc-secret');
    expect(DB::table('sso_oidc_providers')->value('client_secret'))->not->toBe('oidc-secret');

    $this->actingAs($admin)->putJson('/api/admin/extensions/sso/oidc-providers/'.$record->id, [...$payload, 'client_secret' => '', 'label' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.label', 'Renamed');
    expect($record->refresh()->client_secret)->toBe('oidc-secret');

    $this->actingAs($admin)->postJson('/api/admin/extensions/sso/oidc-providers', $payload)->assertUnprocessable();
    $this->actingAs($admin)->postJson('/api/admin/extensions/sso/oidc-providers', [...$payload, 'slug' => 'Bad Slug'])->assertUnprocessable();
    $this->actingAs($admin)->postJson('/api/admin/extensions/sso/oidc-providers', [...$payload, 'slug' => 'two', 'issuer' => 'http://id.example.test'])->assertUnprocessable();
});

test('only administrators can manage openid connect servers', function (): void {
    oidc();

    $this->actingAs(user())->getJson('/api/admin/extensions/sso/oidc-providers')->assertForbidden();
    $this->getJson('/api/admin/extensions/sso/oidc-providers')->assertUnauthorized();
});

test('deleting an openid connect server also drops the accounts linked through it', function (): void {
    $admin = user(['root_admin' => true]);
    $record = oidc();
    Identity::query()->create(['user_id' => $admin->id, 'provider' => 'oidc-corp', 'provider_user_id' => 'subject-1']);
    Identity::query()->create(['user_id' => $admin->id, 'provider' => 'discord', 'provider_user_id' => '1']);

    $this->actingAs($admin)->deleteJson('/api/admin/extensions/sso/oidc-providers/'.$record->id)->assertNoContent();

    expect(OidcProvider::query()->count())->toBe(0);
    expect(Identity::query()->pluck('provider')->all())->toBe(['discord']);
});

test('openid connect servers are all off while the global switch is off', function (): void {
    oidc();
    fakeOidc();
    configure(['oidc_enabled' => false]);

    $this->getJson('/extensions/sso/providers')->assertOk()->assertJsonMissing(['id' => 'oidc-corp']);
    $this->get('/extensions/sso/oidc-corp/redirect')->assertRedirect('/auth/login?sso_error=unavailable');
});

test('a created account falls back to the email when the provider has no login name', function (): void {
    oidc(['create_users' => true]);
    fakeOidc(['preferred_username' => null, 'email' => 'jane.doe@corp.test']);

    callback($this, provider: 'oidc-corp')->assertRedirect('/');

    $created = User::query()->where('email', 'jane.doe@corp.test')->sole();
    $this->users[] = $created->id;
    expect($created->username)->toBe('jane.doe');
});

test('the openid connect picture is refreshed at every sign in, and cleared when the provider drops it', function (): void {
    oidc();
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'oidc-corp', 'provider_user_id' => 'subject-1', 'avatar_url' => 'https://id.example.test/old.png']);

    fakeOidc(['picture' => 'https://id.example.test/new.png']);
    callback($this, provider: 'oidc-corp')->assertRedirect('/');
    expect(Identity::query()->sole()->avatar_url)->toBe('https://id.example.test/new.png');

    auth()->logout();
    fakeOidc(['picture' => null]);
    callback($this, provider: 'oidc-corp')->assertRedirect('/');
    expect(Identity::query()->sole()->avatar_url)->toBeNull();
});
