# Single Sign-On

This is an extension only compatible with Pterodactyl 2.0.

Lets users sign in to the panel with an external account. Built as a module that can be expanded to add support for other third-parties.

**Included Providers:**
- Discord
- GitHub
- Google
- GitLab
- Microsoft
- OpenID Connect (any number of servers, added from Admin > OpenID Connect)

## Install

Download [sso.pteroext](https://github.com/pterodactyl/SSO-Extension/releases/latest/download/sso.pteroext)
from the latest release, then on the panel host, either run:

```sh
php artisan p:extension:install /path/to/sso.zip --enable
```

Or install the extension using the admin panel under Admin > Extensions.

You must then reload the panel, then you can find the configuration under Admin > Extensions > Single Sign-On.

## OpenID Connect

Turn on **Enable OpenID Connect** in the extension settings, then add servers under Admin > OpenID Connect. Each one has an issuer URL (discovery is read from `/.well-known/openid-configuration`), a client ID and secret, and is given the redirect URL shown in the list.

- **Groups to roles:** list administrator and member groups, and the claim that holds the groups (`groups` by default; use dots for nested claims, such as `realm_access.roles`). Roles are applied at every sign-in. When any group is listed, a user in none of them is refused; with no member group, everyone else is a regular user. With no groups listed the role is never changed.
- **Create accounts:** an unknown user gets a panel account when this is enabled. An email is required, and an email already used on the panel is refused rather than merged. Only OpenID Connect servers can do this.

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

A provider that does not use OAuth 2 implements `Sso\Contracts\IdentityProvider` directly.

Without a `brand.tsx`, the provider gets a neutral button. With one, it exports its colours and logo, keyed by the provider's `id` (see `providers/Discord/brand.tsx`). Rebuild the frontend (`npm run build`) after adding or changing a brand.