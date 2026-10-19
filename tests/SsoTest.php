<?php

declare(strict_types=1);

namespace Sso\Tests\SsoTest;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Events\ActivityLogged;
use Pterodactyl\Events\Auth\DirectLogin;
use Pterodactyl\Models\Extension;
use Pterodactyl\Models\User;
use Pterodactyl\Notifications\AccountCreated;
use Pterodactyl\Services\Extensions\ExtensionManager;
use Pterodactyl\Services\Extensions\ExtensionProviderLoader;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use RuntimeException;
use Sso\Contracts\IdentityProvider;
use Sso\Data\ExternalIdentity;
use Sso\Http\Controllers\ConnectionController;
use Sso\Models\Identity;
use Sso\Models\PendingSetup;
use Sso\Pkce;
use Sso\ProviderRegistry;
use Symfony\Component\HttpFoundation\HeaderUtils;

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
    foreach (['sso_identities' => '2026_10_05_000000_create_sso_identities_table', 'sso_pending_setups' => '2026_10_19_000000_create_sso_pending_setups_table'] as $table => $migration) {
        if (! Schema::hasTable($table)) {
            (require dirname(__DIR__).'/database/migrations/'.$migration.'.php')->up();
        }
    }

    $this->users = [];
    $this->lastUserId = (int) User::query()->max('id');
    configure(['discord_enabled' => true, 'discord_client_id' => 'client-id', 'discord_client_secret' => 'client-secret']);
});

afterEach(function (): void {
    Identity::query()->delete();
    PendingSetup::query()->delete();
    User::query()->whereIn('id', $this->users)->orWhere('id', '>', $this->lastUserId)->delete();
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
        'code_challenge_method' => 'S256',
    ]);
    expect($query['state'])->toBe(session('sso.flow.state'));
    expect(Pkce::isValidVerifier(session('sso.flow.code_verifier')))->toBeTrue();
    expect($query['code_challenge'])->toBe(Pkce::challenge(session('sso.flow.code_verifier')));
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
    expect(Pkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'))->toBe('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
});

test('the token request carries the PKCE verifier stored in the session', function (): void {
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912']);
    fakeDiscord();

    $this->get('/extensions/sso/discord/redirect');
    $verifier = session('sso.flow.code_verifier');
    $this->get('/extensions/sso/discord/callback?code=abc&state='.session('sso.flow.state'))->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://discord.com/api/oauth2/token' && $request['code_verifier'] === $verifier);
});

test('a login started before PKCE was enabled completes without a verifier', function (): void {
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912']);
    fakeDiscord();

    $this->get('/extensions/sso/discord/redirect');
    session()->forget('sso.flow.code_verifier');
    $this->get('/extensions/sso/discord/callback?code=abc&state='.session('sso.flow.state'))->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://discord.com/api/oauth2/token' && ! isset($request['code_verifier']));
});

test('a malformed PKCE verifier in the session is refused before contacting the provider', function (): void {
    fakeDiscord();

    $this->get('/extensions/sso/discord/redirect');
    session()->put('sso.flow.code_verifier', 'too-short');
    $this->get('/extensions/sso/discord/callback?code=abc&state='.session('sso.flow.state'))->assertRedirect('/auth/login?sso_error=state');

    Http::assertNothingSent();
    $this->assertGuest();
});

test('a provider that cannot build its authorization url returns to the login page without starting a flow', function (): void {
    app(ProviderRegistry::class)->register(BrokenProvider::class);

    $this->get('/extensions/sso/broken/redirect')->assertRedirect('/auth/login?sso_error=provider');

    expect(session()->has('sso.flow'))->toBeFalse();
});

test('starting a steam login sends the browser to steam with the state in the return url', function (): void {
    configure(['steam_enabled' => true]);

    $location = $this->get('/extensions/sso/steam/redirect')->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://steamcommunity.com/openid/login?');
    $query = HeaderUtils::parseQuery((string) parse_url($location, PHP_URL_QUERY));
    expect($query)->toMatchArray([
        'openid.ns' => 'http://specs.openid.net/auth/2.0',
        'openid.mode' => 'checkid_setup',
        'openid.return_to' => url('/extensions/sso/steam/callback').'?state='.session('sso.flow.state'),
        'openid.realm' => url('/').'/',
        'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
        'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
    ]);
    expect(session('sso.flow'))->toMatchArray(['provider' => 'steam', 'intent' => 'login', 'code_verifier' => null]);
});

test('a verified steam assertion signs in the linked user and stores the steam profile', function (): void {
    configure(['steam_enabled' => true, 'steam_api_key' => 'steam-key']);
    $user = user();
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'steam', 'provider_user_id' => '76561197960435530']);
    fakeSteam();

    steamCallback($this)->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(Identity::query()->sole()->only(['name', 'email', 'avatar_url']))->toBe([
        'name' => 'Robin',
        'email' => null,
        'avatar_url' => 'https://avatars.steamstatic.com/robin_full.jpg',
    ]);
    Http::assertSent(function (HttpRequest $request): bool {
        $body = HeaderUtils::parseQuery($request->body());

        return $request->url() === 'https://steamcommunity.com/openid/login'
            && $body['openid.mode'] === 'check_authentication'
            && $body['openid.sig'] === 'c2lnbmF0dXJl'
            && $body['openid.claimed_id'] === 'https://steamcommunity.com/openid/id/76561197960435530';
    });
    Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/')
        && $request['key'] === 'steam-key'
        && $request['steamids'] === '76561197960435530');
});

test('a steam account can be linked without a web api key', function (): void {
    configure(['steam_enabled' => true]);
    $user = user();
    fakeSteam();

    $this->actingAs($user);
    steamCallback($this, intent: 'link')->assertRedirect('/account/connections?sso_linked=steam');

    expect(Identity::query()->sole()->only(['user_id', 'provider_user_id', 'name']))->toBe([
        'user_id' => $user->id,
        'provider_user_id' => '76561197960435530',
        'name' => '76561197960435530',
    ]);
    Http::assertSentCount(1);
});

test('an unlinked steam account is refused because steam shares no email', function (): void {
    configure(['steam_enabled' => true, 'link_by_email' => true]);
    fakeSteam();

    steamCallback($this)->assertRedirect('/auth/login?sso_error=unlinked');

    $this->assertGuest();
});

test('a steam assertion that steam does not confirm is refused', function (): void {
    configure(['steam_enabled' => true]);
    Identity::query()->create(['user_id' => user()->id, 'provider' => 'steam', 'provider_user_id' => '76561197960435530']);
    fakeSteam(false);

    steamCallback($this)->assertRedirect('/auth/login?sso_error=provider');

    $this->assertGuest();
});

test('a cancelled steam login returns to the login page', function (): void {
    configure(['steam_enabled' => true]);
    fakeSteam();

    $this->get('/extensions/sso/steam/redirect');
    $state = session('sso.flow.state');
    $this->get('/extensions/sso/steam/callback?'.http_build_query(['state' => $state, 'openid.ns' => 'http://specs.openid.net/auth/2.0', 'openid.mode' => 'cancel']))
        ->assertRedirect('/auth/login?sso_error=denied');

    Http::assertNothingSent();
});

test('a steam assertion issued for another login attempt is refused', function (): void {
    configure(['steam_enabled' => true]);
    fakeSteam();

    steamCallback($this, ['openid.return_to' => url('/extensions/sso/steam/callback').'?state=stolen'])->assertRedirect('/auth/login?sso_error=state');

    Http::assertNothingSent();
});

test('a steam assertion that is tampered with is refused before asking steam', function (array $overrides): void {
    configure(['steam_enabled' => true]);
    fakeSteam();

    steamCallback($this, $overrides)->assertRedirect('/auth/login?sso_error=provider');

    Http::assertNothingSent();
    $this->assertGuest();
})->with([
    'unsigned claimed id' => [['openid.signed' => 'signed,op_endpoint,identity,return_to,response_nonce,assoc_handle']],
    'foreign endpoint' => [['openid.op_endpoint' => 'https://steam.example/openid/login']],
    'foreign return url' => [['openid.return_to' => 'https://panel.example/extensions/sso/steam/callback?state=STATE']],
    'non steam identity' => [['openid.claimed_id' => 'https://openid.example/id/76561197960435530', 'openid.identity' => 'https://openid.example/id/76561197960435530']],
    'mismatched identity' => [['openid.identity' => 'https://steamcommunity.com/openid/id/76561197960435531']],
]);

test('an admin can create a user with only a discord id who sets up their account on first sign-in', function (): void {
    $response = $this->actingAs(user(['root_admin' => true]))->postJson('/api/admin/users', [
        'email' => '',
        'username' => '',
        'name_first' => '',
        'name_last' => '',
        'extensions' => ['sso' => ['discord_id' => '80351110224678912', 'setup_on_first_login' => true]],
    ]);

    $response->assertCreated()->assertJsonPath('attributes.extensions.sso', [
        'discord_id' => '80351110224678912',
        'setup_on_first_login' => true,
    ]);
    $user = User::query()->findOrFail($response->json('attributes.id'));
    expect($user->only(['email', 'username', 'name_first', 'name_last']))->toBe([
        'email' => '80351110224678912@discord.invalid',
        'username' => 'discord-80351110224678912',
        'name_first' => 'Discord',
        'name_last' => 'User',
    ]);
    expect(mb_strlen($user->password))->toBeGreaterThan(0);
    expect(Identity::query()->sole()->only(['user_id', 'provider', 'provider_user_id']))->toBe([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '80351110224678912',
    ]);
    expect(PendingSetup::query()->whereKey($user->id)->exists())->toBeTrue();
    expect(sentMail())->toBe([]);
});

test('the placeholders the admin form fills in are replaced when they are already taken', function (): void {
    user(['username' => 'discord-80351110224678912', 'email' => '80351110224678912@discord.invalid']);

    $response = $this->actingAs(user(['root_admin' => true]))->postJson('/api/admin/users', [
        'email' => '80351110224678912@discord.invalid',
        'username' => 'discord-80351110224678912',
        'name_first' => 'Nelly',
        'name_last' => 'Example',
        'extensions' => ['sso' => ['discord_id' => '80351110224678912', 'setup_on_first_login' => true]],
    ])->assertCreated();

    expect(User::query()->findOrFail($response->json('attributes.id'))->only(['email', 'username', 'name_first', 'name_last']))->toBe([
        'email' => '80351110224678912+2@discord.invalid',
        'username' => 'discord-80351110224678912-2',
        'name_first' => 'Nelly',
        'name_last' => 'Example',
    ]);
});

test('an admin can create a user with only a steam id', function (): void {
    configure(['steam_enabled' => true]);

    $response = $this->actingAs(user(['root_admin' => true]))->postJson('/api/admin/users', [
        'extensions' => ['sso' => ['steam_id' => '76561197960435530', 'setup_on_first_login' => true]],
    ])->assertCreated();

    expect(User::query()->findOrFail($response->json('attributes.id'))->only(['email', 'username', 'name_first']))->toBe([
        'email' => '76561197960435530@steam.invalid',
        'username' => 'steam-76561197960435530',
        'name_first' => 'Steam',
    ]);
    expect(Identity::query()->sole()->only(['provider', 'provider_user_id']))->toBe(['provider' => 'steam', 'provider_user_id' => '76561197960435530']);
});

test('a user created with an email and an account id keeps the panel welcome email', function (): void {
    $this->actingAs(user(['root_admin' => true]))->postJson('/api/admin/users', [
        'email' => 'nelly@example.test',
        'username' => 'nelly',
        'name_first' => 'Nelly',
        'name_last' => 'Example',
        'extensions' => ['sso' => ['discord_id' => '80351110224678912', 'setup_on_first_login' => false]],
    ])->assertCreated();

    expect(sentMail())->toBe([['nelly@example.test']]);
    expect(PendingSetup::query()->exists())->toBeFalse();
    expect(Identity::query()->sole()->provider_user_id)->toBe('80351110224678912');
});

test('account ids must match the format of their provider', function (array $values, string $field, string $message): void {
    configure(['steam_enabled' => true]);

    $this->actingAs(user(['root_admin' => true]))->postJson('/api/admin/users', ['extensions' => ['sso' => $values]])
        ->assertUnprocessable()
        ->assertJsonFragment(['detail' => $message, 'source_field' => 'extensions.sso.'.$field]);

    expect(User::query()->where('id', '>', $this->lastUserId)->count())->toBe(1);
    expect(Identity::query()->exists())->toBeFalse();
})->with([
    'letters' => [['discord_id' => 'nelly', 'setup_on_first_login' => true], 'discord_id', 'That is not a valid Discord ID. A Discord user ID is 17 to 20 digits. Turn on Developer Mode in Discord, then right-click the user and choose Copy User ID.'],
    'too short' => [['discord_id' => '1234567890', 'setup_on_first_login' => true], 'discord_id', 'That is not a valid Discord ID. A Discord user ID is 17 to 20 digits. Turn on Developer Mode in Discord, then right-click the user and choose Copy User ID.'],
    'not a steamid64' => [['steam_id' => '12345678901234567', 'setup_on_first_login' => true], 'steam_id', 'That is not a valid Steam ID. A SteamID64 is 17 digits and starts with 7656119. It is the number in the address of a Steam profile without a custom URL.'],
    'no id' => [['discord_id' => null, 'setup_on_first_login' => true], 'setup_on_first_login', 'Enter at least one account ID, so the user can sign in to set up their account.'],
]);

test('an account id that belongs to another user is refused', function (): void {
    $owner = user();
    Identity::query()->create(['user_id' => $owner->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912']);
    $admin = user(['root_admin' => true]);

    $this->actingAs($admin)->postJson('/api/admin/users', ['extensions' => ['sso' => ['discord_id' => '80351110224678912', 'setup_on_first_login' => true]]])
        ->assertUnprocessable()
        ->assertJsonFragment(['detail' => 'This Discord account is already linked to another user.']);

    $this->actingAs($admin)->putJson('/api/admin/users/'.$owner->id, edit($owner, ['discord_id' => '80351110224678912', 'setup_on_first_login' => false]))->assertOk();
    expect(Identity::query()->sole()->user_id)->toBe($owner->id);
});

test('editing a user changes or clears their account id', function (): void {
    $user = user();
    Identity::query()->create([
        'user_id' => $user->id,
        'provider' => 'discord',
        'provider_user_id' => '80351110224678912',
        'name' => 'Nelly',
        'email' => 'nelly@discord.test',
        'avatar_url' => 'https://cdn.discordapp.com/avatars/80351110224678912/a.png',
        'last_login_at' => now(),
    ]);
    $admin = user(['root_admin' => true]);

    $this->actingAs($admin)->getJson('/api/admin/users/'.$user->id)->assertOk()->assertJsonPath('attributes.extensions.sso', [
        'discord_id' => '80351110224678912',
        'setup_on_first_login' => false,
    ]);

    $this->actingAs($admin)->putJson('/api/admin/users/'.$user->id, edit($user, ['discord_id' => '123456789012345678', 'setup_on_first_login' => false]))->assertOk();
    expect(Identity::query()->sole()->only(['user_id', 'provider_user_id', 'name', 'email', 'avatar_url', 'last_login_at']))->toBe([
        'user_id' => $user->id,
        'provider_user_id' => '123456789012345678',
        'name' => null,
        'email' => null,
        'avatar_url' => null,
        'last_login_at' => null,
    ]);

    $this->actingAs($admin)->putJson('/api/admin/users/'.$user->id, edit($user, ['discord_id' => null, 'setup_on_first_login' => false]))->assertOk();
    expect(Identity::query()->exists())->toBeFalse();
});

test('an account waiting for setup keeps an account id', function (): void {
    $user = user(['email' => '80351110224678912@discord.invalid']);
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912']);
    PendingSetup::query()->create(['user_id' => $user->id]);

    $this->actingAs(user(['root_admin' => true]))->putJson('/api/admin/users/'.$user->id, edit($user, ['discord_id' => null, 'setup_on_first_login' => true]))
        ->assertUnprocessable()
        ->assertJsonFragment(['source_field' => 'extensions.sso.setup_on_first_login']);

    expect(Identity::query()->sole()->user_id)->toBe($user->id);
});

test('a discord account an admin assigned cannot be linked by another user', function (): void {
    $reserved = pendingUser();
    fakeDiscord();

    $this->actingAs(user());
    callback($this, 'link')->assertRedirect('/account/connections?sso_error=taken');

    expect(Identity::query()->sole()->user_id)->toBe($reserved->id);
});

test('the first sign-in of an account waiting for setup lands on the setup screen and the rest of the panel waits', function (): void {
    $user = pendingUser();
    fakeDiscord();

    callback($this)->assertRedirect('/account/setup');

    $this->assertAuthenticatedAs($user);
    expect(session('sso.setup_email'))->toBe('nelly@discord.test');
    $this->withHeader('Accept', 'text/html')->get('/')->assertRedirect('/account/setup');
    $this->withHeader('Accept', 'text/html')->get('/server/abcdef12')->assertRedirect('/account/setup');
    $this->withHeader('Accept', 'text/html')->get('/account/setup')->assertOk();
    $this->getJson('/api/client')->assertStatus(409)->assertJsonPath('errors.0.code', 'SsoSetupRequired');
    $this->getJson('/api/client/account')->assertStatus(409);
    $this->getJson('/api/client/extensions/sso/connections')->assertStatus(409);
    $this->deleteJson('/api/client/extensions/sso/connections/discord')->assertStatus(409);
    $this->getJson('/api/admin/users')->assertStatus(409);
    $this->withHeader('Referer', url('/account/setup'))->getJson('/api/client/extensions/sso/setup')->assertOk()->assertExactJson(['data' => [
        'pending' => true,
        'username' => '',
        'email' => 'nelly@discord.test',
        'name_first' => '',
        'name_last' => '',
    ]]);
    expect(Identity::query()->sole()->name)->toBe('Nelly');
});

test('an account waiting for setup cannot be reached with an api key', function (): void {
    $user = pendingUser();
    Sanctum::actingAs($user);

    $this->getJson('/api/client')->assertStatus(409);
    $this->getJson('/api/client/account/api-keys')->assertStatus(409);
});

test('an account waiting for setup can sign out', function (): void {
    $this->actingAs(pendingUser())->post('/auth/logout')->assertStatus(204);

    $this->assertGuest();
});

test('finishing the setup saves the details the user chose and opens the panel', function (): void {
    $user = pendingUser();
    $taken = user(['email' => 'taken@example.test']);
    $this->actingAs($user);
    $details = ['username' => 'nelly', 'email' => 'nelly@example.test', 'name_first' => 'Nelly', 'name_last' => 'Example', 'password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery'];

    $this->postJson('/api/client/extensions/sso/setup', [...$details, 'email' => $taken->email])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'email');
    $this->postJson('/api/client/extensions/sso/setup', [...$details, 'email' => 'nelly@discord.invalid'])->assertUnprocessable()->assertJsonPath('errors.0.detail', 'Enter an email address you can receive mail at.');
    $this->postJson('/api/client/extensions/sso/setup', [...$details, 'username' => '-nelly'])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'username');
    $this->postJson('/api/client/extensions/sso/setup', [...$details, 'password_confirmation' => 'something else'])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'password');
    $this->postJson('/api/client/extensions/sso/setup', [...$details, 'root_admin' => true])->assertNoContent();

    $user->refresh();
    expect($user->only(['username', 'email', 'name_first', 'name_last', 'root_admin']))->toBe(['username' => 'nelly', 'email' => 'nelly@example.test', 'name_first' => 'Nelly', 'name_last' => 'Example', 'root_admin' => false]);
    expect(Hash::check('correct horse battery', $user->password))->toBeTrue();
    expect(PendingSetup::query()->exists())->toBeFalse();
    $this->assertAuthenticatedAs($user);
    $this->getJson('/api/client/account')->assertOk();
    $this->postJson('/api/client/extensions/sso/setup', $details)->assertStatus(409);
});

test('the setup may leave the password unset', function (): void {
    $user = pendingUser();
    $password = $user->password;

    $this->actingAs($user)->postJson('/api/client/extensions/sso/setup', ['username' => 'nelly', 'email' => 'nelly@example.test', 'name_first' => 'Nelly', 'name_last' => 'Example', 'password' => ''])->assertNoContent();

    expect($user->refresh()->password)->toBe($password);
    expect(PendingSetup::query()->exists())->toBeFalse();
});

test('the setup only ever changes the signed-in account', function (): void {
    $pending = pendingUser();
    $other = user(['username' => 'other']);

    $this->actingAs($other)->postJson('/api/client/extensions/sso/setup', ['username' => 'stolen', 'email' => 'stolen@example.test', 'name_first' => 'A', 'name_last' => 'B'])->assertStatus(409);
    $this->actingAs($other)->getJson('/api/client/extensions/sso/setup')->assertOk()->assertJsonPath('data.pending', false)->assertJsonPath('data.username', 'other');

    expect($other->refresh()->username)->toBe('other');
    expect($pending->refresh()->username)->toBe('discord-80351110224678912');
    expect(PendingSetup::query()->whereKey($pending->id)->exists())->toBeTrue();
});

test('a user cannot disconnect the last way into their account', function (): void {
    $user = user(['email' => '80351110224678912@discord.invalid']);
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912']);
    $this->actingAs($user);

    $this->deleteJson('/api/client/extensions/sso/connections/discord')->assertStatus(409)
        ->assertJsonPath('errors.0.detail', 'This is the only way to sign in to your account. Ask an administrator to set your email address before you disconnect it.');
    expect(Identity::query()->count())->toBe(1);

    Identity::query()->create(['user_id' => $user->id, 'provider' => 'steam', 'provider_user_id' => '76561197960435530']);
    $this->deleteJson('/api/client/extensions/sso/connections/discord')->assertNoContent();
    $this->deleteJson('/api/client/extensions/sso/connections/steam')->assertStatus(409);

    $user->forceFill(['email' => 'nelly@example.test'])->save();
    $this->deleteJson('/api/client/extensions/sso/connections/steam')->assertNoContent();
    expect(Identity::query()->exists())->toBeFalse();
});

test('an account waiting for setup cannot disconnect a sign-in method', function (): void {
    $user = pendingUser(['email' => 'nelly@example.test']);

    $controller = app(ConnectionController::class);
    $request = Request::create('/api/client/extensions/sso/connections/discord', 'DELETE');
    $request->setUserResolver(fn (): User => $user);

    expect($controller->destroy($request, 'discord')->getStatusCode())->toBe(409);
    expect(Identity::query()->count())->toBe(1);
});

test('nothing is mailed to a placeholder address', function (): void {
    $user = pendingUser();

    $user->notify(new AccountCreated($user));
    Password::sendResetLink(['email' => $user->email]);
    Mail::raw('Hello', fn (Message $message) => $message->to($user->email));
    Mail::raw('Hello', fn (Message $message) => $message->to($user->email)->cc('staff@example.test'));

    expect(sentMail())->toBe([['staff@example.test']]);
});

test('the admin form can show the discord account an id belongs to when a bot token is set', function (): void {
    $admin = user(['root_admin' => true]);
    Http::fake([
        'discord.com/api/v10/users/80351110224678912' => Http::response(['id' => '80351110224678912', 'username' => 'nelly', 'global_name' => 'Nelly', 'avatar' => 'abc']),
        'discord.com/api/v10/users/*' => Http::response(['message' => 'Unknown User', 'code' => 10013], 404),
    ]);

    $this->actingAs($admin)->getJson('/api/admin/extensions/sso/accounts')->assertOk()->assertJsonPath('data.0', [
        'id' => 'discord',
        'name' => 'Discord',
        'field' => 'discord_id',
        'pattern' => '^[0-9]{17,20}$',
        'hint' => 'A Discord user ID is 17 to 20 digits. Turn on Developer Mode in Discord, then right-click the user and choose Copy User ID.',
        'lookup' => false,
    ]);
    $this->actingAs($admin)->getJson('/api/admin/extensions/sso/accounts/discord/80351110224678912')->assertNotFound();
    Http::assertNothingSent();

    configure(['discord_bot_token' => 'bot-token']);
    $this->actingAs($admin)->getJson('/api/admin/extensions/sso/accounts')->assertOk()->assertJsonPath('data.0.lookup', true);
    $this->actingAs($admin)->getJson('/api/admin/extensions/sso/accounts/discord/80351110224678912')->assertOk()->assertExactJson(['data' => [
        'id' => '80351110224678912',
        'name' => 'Nelly',
        'avatar_url' => 'https://cdn.discordapp.com/avatars/80351110224678912/abc.png',
    ]]);
    $this->actingAs($admin)->getJson('/api/admin/extensions/sso/accounts/discord/80351110224678912')->assertOk();
    $this->actingAs($admin)->getJson('/api/admin/extensions/sso/accounts/discord/123456789012345678')->assertNotFound()->assertJsonPath('errors.0.detail', 'No Discord account has this ID.');
    $this->actingAs($admin)->getJson('/api/admin/extensions/sso/accounts/discord/nelly')->assertUnprocessable();

    Http::assertSentCount(2);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://discord.com/api/v10/users/80351110224678912' && $request->hasHeader('Authorization', 'Bot bot-token'));
    expect(json_encode($this->actingAs($admin)->getJson('/api/admin/extensions/sso/settings')->json()))->not->toContain('bot-token');
    $this->actingAs(user())->getJson('/api/admin/extensions/sso/accounts/discord/80351110224678912')->assertForbidden();
});

test('a discord lookup that fails says so without caching the failure', function (): void {
    configure(['discord_bot_token' => 'bot-token']);
    Http::fake(['discord.com/*' => Http::response(['message' => '401: Unauthorized'], 401)]);
    $admin = user(['root_admin' => true]);

    $this->actingAs($admin)->getJson('/api/admin/extensions/sso/accounts/discord/80351110224678912')->assertStatus(503);
    $this->actingAs($admin)->getJson('/api/admin/extensions/sso/accounts/discord/80351110224678912')->assertStatus(503);

    Http::assertSentCount(2);
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
 * @return User
 */
function pendingUser(array $attributes = []): User
{
    $user = user(['email' => '80351110224678912@discord.invalid', 'username' => 'discord-80351110224678912', 'name_first' => 'Discord', 'name_last' => 'User', ...$attributes]);
    Identity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '80351110224678912']);
    PendingSetup::query()->create(['user_id' => $user->id]);

    return $user;
}

/**
 * An admin user update that keeps the user's details and sends these extension values.
 *
 * @param User
 * @param array
 * @return array
 */
function edit(User $user, array $values): array
{
    return [...$user->only(['email', 'username', 'name_first', 'name_last']), 'extensions' => ['sso' => $values]];
}

/**
 * The recipients of each message the panel mailed.
 *
 * @return array
 */
function sentMail(): array
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->map(
        fn ($message): array => array_map(fn ($address): string => $address->getAddress(), $message->getEnvelope()->getRecipients()),
    )->values()->all();
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

/**
 * @param bool
 */
function fakeSteam(bool $valid = true): void
{
    Http::swap(new Factory);
    Http::fake([
        'steamcommunity.com/openid/login' => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:".($valid ? 'true' : 'false')."\n"),
        'api.steampowered.com/*' => Http::response(['response' => ['players' => [[
            'steamid' => '76561197960435530',
            'personaname' => 'Robin',
            'avatarfull' => 'https://avatars.steamstatic.com/robin_full.jpg',
        ]]]]),
    ]);
}

/**
 * @param IntegrationTestCase
 * @param array
 * @param string
 * @return TestResponse
 */
function steamCallback(IntegrationTestCase $test, array $overrides = [], string $intent = 'login'): TestResponse
{
    $test->get($intent === 'link' ? '/extensions/sso/steam/link' : '/extensions/sso/steam/redirect')->assertRedirect();
    $state = session('sso.flow.state');
    $claimedId = 'https://steamcommunity.com/openid/id/76561197960435530';
    $assertion = array_map(fn (string $value): string => str_replace('STATE', $state, $value), [
        'openid.ns' => 'http://specs.openid.net/auth/2.0',
        'openid.mode' => 'id_res',
        'openid.op_endpoint' => 'https://steamcommunity.com/openid/login',
        'openid.claimed_id' => $claimedId,
        'openid.identity' => $claimedId,
        'openid.return_to' => url('/extensions/sso/steam/callback').'?state='.$state,
        'openid.response_nonce' => '2026-10-19T12:00:00Zr4nd0m',
        'openid.assoc_handle' => '1234567890',
        'openid.signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'openid.sig' => 'c2lnbmF0dXJl',
        ...$overrides,
    ]);

    return $test->get('/extensions/sso/steam/callback?'.http_build_query(['state' => $state, ...$assertion], '', '&', PHP_QUERY_RFC3986));
}

final class BrokenProvider implements IdentityProvider
{
    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'broken';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'Broken';
    }

    /**
     * @inheritdoc
     */
    public function settings(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function enabled(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        throw new RuntimeException('Authorization endpoint is not configured.');
    }

    /**
     * @inheritdoc
     */
    public function identify(string $code, string $redirectUri): ExternalIdentity
    {
        throw new RuntimeException('Token endpoint is not configured.');
    }
}
