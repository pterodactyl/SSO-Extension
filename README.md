# Single Sign-On

This is an extension only compatible with Pterodactyl 2.0.

Lets users sign in to the panel with an external account. Built as a module that can be expanded to add support for other third-parties.

**Included Providers:**
- Discord
- GitHub
- Google
- GitLab
- Microsoft
- Steam

## Install

Download [sso.pteroext](https://github.com/pterodactyl/SSO-Extension/releases/latest/download/sso.pteroext)
from the latest release, then on the panel host, either run:

```sh
php artisan p:extension:install /path/to/sso.zip --enable
```

Or install the extension using the admin panel under Admin > Extensions.

You must then reload the panel, then you can find the configuration under Admin > Extensions > Single Sign-On.

## Accounts for a Discord or Steam ID

Admins can create a user from just a Discord or Steam account, without an email, username or password.

1. Enable the provider under Admin > Extensions > Single Sign-On.
2. Under Admin > Users > New user, enter the user's Discord ID (17 to 20 digits; turn on Developer Mode in Discord, right-click the user and choose Copy User ID) or SteamID64 (17 digits starting with 7656119) in the Single Sign-On section.
3. Turn on "Set up on first sign-in". The form fills the email, username and name with placeholders (`discord-<id>`, `<id>@discord.invalid`) and the panel gives the account a random password. Nothing is ever emailed to a `.invalid` address, so no welcome email is sent.

The edit form shows the same fields: changing an ID links the new account instead (the stored name and avatar of the old one are cleared), and clearing it unlinks the provider. An ID that is already linked to another user is refused.

On their first sign-in with that account, the user lands on "Finish your account" and chooses a username, email, name and optionally a password. The email is filled in when the provider reports a verified one. Until they finish, every other page sends them back there and the API answers `409` with the code `SsoSetupRequired`; signing out still works. Users can relink or disconnect providers from Account > Connections as before, except that a provider cannot be disconnected while the account is waiting for setup, or while it is the only way in to an account that still has a placeholder email.

The Discord "Bot token" setting is optional. With a token, the admin form shows the Discord name and avatar for the entered ID, so a mistyped ID is noticed before anyone signs in with it. Create a bot for the application under Bot in the Discord developer portal; it needs no permissions and does not have to join a server. The token stays on the panel. Without one, IDs are only checked for their format. Steam does the same with its optional Web API key.

A provider offers these fields by implementing `Sso\Contracts\AcceptsAccountIds`, and the lookup by implementing `Sso\Contracts\LooksUpAccounts`. Only use it for providers whose account IDs are stable and public.

## Adding a provider

Each provider lives in its own directory under `providers/`. The extension discovers them; there is no list to edit.

```
providers/GitHub/
  GitHubProvider.php   # class Sso\Providers\GitHub\GitHubProvider
  brand.tsx            # optional: button colours and logo
```

Most providers use OAuth 2. For those, extend `Sso\OAuth2Provider` and fill in the endpoints, scopes and mapping:

```php
namespace Sso\Providers\GitHub;

final class GitHubProvider extends OAuth2Provider
{
    public function id(): string { return 'github'; }
    public function name(): string { return 'GitHub'; }
    protected function authorizeEndpoint(): string { return 'https://github.com/login/oauth/authorize'; }
    protected function tokenEndpoint(): string { return 'https://github.com/login/oauth/access_token'; }
    protected function userEndpoint(): string { return 'https://api.github.com/user'; }
    protected function scopes(): array { return ['read:user', 'user:email']; }
    protected function mapIdentity(array $user): ExternalIdentity { /* ... */ }
}
```

The directory name, the namespace segment and the class prefix must match (`providers/GitHub` → `Sso\Providers\GitHub\GitHubProvider`). The enable, client ID and client secret settings, the routes, the login button and the Connections row all come from that class.

If the user endpoint does not return everything `mapIdentity()` needs, override `enrich()`. It runs after the profile is fetched and before `mapIdentity()`. It receives the access token and the profile array, and returns the array that gets passed to `mapIdentity()`. By default it returns the profile unchanged.

GitHub uses it to fetch a verified email, because the email on the profile is optional and not verified:

```php
protected function enrich(string $token, array $user): array
{
    try {
        $emails = Http::acceptJson()->timeout(10)->withToken($token)->get('https://api.github.com/user/emails')->throw()->json();
    } catch (Throwable) {
        return $user;
    }

    foreach (is_array($emails) ? $emails : [] as $entry) {
        if (is_array($entry) && ($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true && is_string($entry['email'] ?? null)) {
            return [...$user, 'sso_email' => $entry['email']];
        }
    }

    return $user;
}
```

Any exception thrown in `enrich()` fails the sign-in. If the extra data is optional, catch the exception and return `$user` as it is, like GitHub does.

OAuth 2 providers use PKCE (S256) by default: the authorization request carries a `code_challenge`, and the token request carries the matching `code_verifier`. If a provider rejects these parameters, override `usesPkce()` to return `false`.

A provider that does not extend `Sso\OAuth2Provider` implements `Sso\Contracts\IdentityProvider` directly. If it uses OAuth 2 and supports PKCE, implement `Sso\Contracts\SupportsPkce` instead, and the extension will generate the verifier and pass it in.

If the callback does not carry an OAuth 2 `code` query parameter, implement `Sso\Contracts\ReadsCallback` as well. Its `callbackCode()` receives the callback request after the state has been checked, and returns the string that is passed to `identify()` as `$code`, or `null` when the user cancelled. Steam uses it to pass on its OpenID 2.0 assertion, see `providers/Steam/SteamProvider.php`.

## Steam

Steam only offers OpenID 2.0, so it needs no client ID or secret: enabling it is enough. The optional Web API key (from https://steamcommunity.com/dev/apikey) is used to fetch the Steam display name and avatar; without one, the Steam ID is shown instead.

Steam does not share an email address, so "Link accounts by verified email" never applies to it. Users have to connect Steam from Account > Connections before they can sign in with it.

Without a `brand.tsx`, the provider gets a neutral button. With one, it exports its colours and logo, keyed by the provider's `id` (see `providers/Discord/brand.tsx`). Rebuild the frontend (`npm run build`) after adding or changing a brand.