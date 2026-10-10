<?php

declare(strict_types=1);

namespace Sso\Oidc;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Override;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\Models\OidcProvider;
use Sso\OAuth2Provider;
use Sso\SsoSettings;

/**
 * One configured OpenID Connect server. Instances are built from a database
 * record, so there can be any number of them; they are not discovered from
 * the providers directory.
 */
final class OpenIdConnectProvider extends OAuth2Provider
{
    /**
     * @var OidcProvider
     */
    protected OidcProvider $record;

    /**
     * @param SsoSettings
     * @param OidcProvider
     */
    public function __construct(SsoSettings $config, OidcProvider $record)
    {
        parent::__construct($config);
        $this->record = $record;
    }

    /**
     * @inheritdoc
     */
    #[Override]
    public function settings(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    #[Override]
    public function enabled(): bool
    {
        return $this->config->boolean(SsoSettings::OIDC_ENABLED)
            && $this->record->enabled
            && $this->issuer() !== ''
            && $this->clientId() !== ''
            && $this->clientSecret() !== '';
    }

    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return $this->record->providerId();
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return $this->record->label;
    }

    /**
     * @inheritdoc
     */
    #[Override]
    protected function clientId(): string
    {
        return mb_trim($this->record->client_id);
    }

    /**
     * @inheritdoc
     */
    #[Override]
    protected function clientSecret(): string
    {
        return mb_trim($this->record->client_secret);
    }

    /**
     * @inheritdoc
     */
    protected function authorizeEndpoint(): string
    {
        return $this->endpoint('authorization_endpoint');
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return $this->endpoint('token_endpoint');
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return $this->endpoint('userinfo_endpoint');
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        $scopes = preg_split('/\s+/', $this->record->scopes, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(['openid', ...($scopes === false ? [] : $scopes)]));
    }

    /**
     * Roles come from the groups claim. When no group lists are configured the
     * role is left alone. Otherwise a group in the admin list makes an admin, a
     * group in the member list (or any user, if that list is empty) makes a
     * member, and anyone else is refused.
     *
     * @inheritdoc
     */
    protected function mapIdentity(array $user): ExternalIdentity
    {
        $id = $this->stringId($user['sub'] ?? null);
        $name = $user['name'] ?? null;
        $username = $user['preferred_username'] ?? null;
        $email = $user['email'] ?? null;
        $picture = $user['picture'] ?? null;

        $label = match (true) {
            is_string($name) && $name !== '' => $name,
            is_string($username) && $username !== '' => $username,
            default => $id,
        };

        $avatar = is_string($picture) && str_starts_with($picture, 'https://') ? $picture : null;
        $admin = $this->admin($user);

        Log::debug('sso oidc: userinfo mapped', [
            'provider' => $this->id(),
            'sub' => $id,
            'claims' => array_keys($user),
            'name_from' => match (true) {
                is_string($name) && $name !== '' => 'name',
                is_string($username) && $username !== '' => 'preferred_username',
                default => 'sub',
            },
            'has_email' => is_string($email) && $email !== '',
            'email_verified' => $user['email_verified'] ?? null,
            'picture' => $picture,
            'picture_kept' => $avatar !== null,
            'admin' => $admin,
            'create_users' => $this->record->create_users,
        ]);

        return new ExternalIdentity(
            $this->id(),
            $id,
            $label,
            is_string($email) && $email !== '' ? $email : null,
            ($user['email_verified'] ?? false) === true,
            $avatar,
            $admin,
            $this->record->create_users,
            is_string($username) && $username !== '' ? $username : null,
        );
    }

    /**
     * @param array
     * @return bool|null
     */
    private function admin(array $user): ?bool
    {
        $adminGroups = $this->record->admin_groups;
        $memberGroups = $this->record->member_groups;
        if ($adminGroups === [] && $memberGroups === []) {
            return null;
        }

        $groups = Arr::get($user, $this->record->groups_claim);
        $groups = is_string($groups) ? [$groups] : array_values(array_filter(is_array($groups) ? $groups : [], is_string(...)));

        $admin = match (true) {
            array_intersect($adminGroups, $groups) !== [] => true,
            $memberGroups === [] || array_intersect($memberGroups, $groups) !== [] => false,
            default => null,
        };

        Log::debug('sso oidc: groups evaluated', [
            'provider' => $this->id(),
            'claim' => $this->record->groups_claim,
            'groups' => $groups,
            'admin_groups' => $adminGroups,
            'member_groups' => $memberGroups,
            'result' => $admin === null ? 'forbidden' : ($admin ? 'admin' : 'member'),
        ]);

        return $admin ?? throw new SsoException(SsoException::FORBIDDEN);
    }

    /**
     * @param string
     * @return string
     */
    private function endpoint(string $name): string
    {
        $url = $this->discovery()[$name] ?? null;
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $url;
    }

    /**
     * @return string
     */
    private function issuer(): string
    {
        return rtrim(mb_trim($this->record->issuer), '/');
    }

    /**
     * Fetch the provider metadata, cached for an hour. Only a valid document is
     * cached, so a wrong issuer does not stay broken after being corrected.
     *
     * @return array
     */
    private function discovery(): array
    {
        $issuer = $this->issuer();
        $cacheKey = 'sso:oidc:discovery:'.sha1($issuer);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $document = Http::acceptJson()->timeout(10)->get($issuer.'/.well-known/openid-configuration')->throw()->json();
        $documentIssuer = is_array($document) && is_string($document['issuer'] ?? null) ? rtrim($document['issuer'], '/') : null;
        if ($documentIssuer !== $issuer) {
            throw new SsoException(SsoException::PROVIDER);
        }

        Cache::put($cacheKey, $document, now()->addHour());

        return $document;
    }
}
