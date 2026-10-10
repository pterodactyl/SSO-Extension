<?php

declare(strict_types=1);

namespace Sso;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Sso\Contracts\IdentityProvider;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;

abstract class OAuth2Provider implements IdentityProvider
{
    /**
     * @var SsoSettings
     */
    protected SsoSettings $config;

    /**
     * @param SsoSettings
     */
    public function __construct(SsoSettings $config)
    {
        $this->config = $config;
    }

    /**
     * @inheritdoc
     */
    public function settings(): array
    {
        return [
            ExtensionSettingDefinition::make($this->key('enabled'), $this->key('enabled'), false, ['boolean'])
                ->label('Enabled')
                ->tab($this->tab())
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make($this->key('client_id'), $this->key('client_id'), '', ['nullable', 'string', 'max:255'])
                ->label('Client ID')
                ->tab($this->tab())
                ->help('Set the redirect URL of the '.$this->tab().' application to '.SsoRoutes::callback($this->id())),
            ExtensionSettingDefinition::make($this->key('client_secret'), $this->key('client_secret'), '', ['nullable', 'string', 'max:255'])
                ->label('Client secret')
                ->tab($this->tab())
                ->secret(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function enabled(): bool
    {
        return $this->config->boolean($this->key('enabled'))
            && $this->clientId() !== ''
            && $this->clientSecret() !== '';
    }

    /**
     * @inheritdoc
     */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return $this->authorizeEndpoint().'?'.http_build_query([
            ...$this->authorizationParameters(),
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @inheritdoc
     */
    public function identify(string $code, string $redirectUri): ExternalIdentity
    {
        $token = Http::asForm()->acceptJson()->timeout(10)->post($this->tokenEndpoint(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ])->throw()->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new SsoException(SsoException::PROVIDER);
        }

        $user = Http::acceptJson()->timeout(10)->withToken($token)->get($this->userEndpoint())->throw()->json();

        if (! is_array($user)) {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $this->mapIdentity($this->enrich($token, $user));
    }

    /**
     * Add anything the profile endpoint does not return, such as a verified email
     * that lives on a separate endpoint. Runs before mapIdentity().
     *
     * @param string
     * @param array
     * @return array
     */
    protected function enrich(string $token, array $user): array
    {
        return $user;
    }

    /**
     * @param mixed
     * @return string
     */
    protected function stringId(mixed $value): string
    {
        $id = is_int($value) ? (string) $value : $value;
        if (! is_string($id) || $id === '') {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $id;
    }

    /**
     * @return string
     */
    abstract protected function authorizeEndpoint(): string;

    /**
     * @return string
     */
    abstract protected function tokenEndpoint(): string;

    /**
     * @return string
     */
    abstract protected function userEndpoint(): string;

    /**
     * @return array
     */
    abstract protected function scopes(): array;

    /**
     * @param array
     * @return ExternalIdentity
     */
    abstract protected function mapIdentity(array $user): ExternalIdentity;

    /**
     * @return array
     */
    protected function authorizationParameters(): array
    {
        return [];
    }

    /**
     * Settings tab title. Allow inheriting classes to override the value.
     *
     * @return string
     */
    protected function tab(): string
    {
        return $this->name();
    }

    /**
     * @return string
     */
    protected function clientId(): string
    {
        return $this->config->string($this->key('client_id'));
    }

    /**
     * @return string
     */
    protected function clientSecret(): string
    {
        return $this->config->string($this->key('client_secret'));
    }

    /**
     * @param string
     * @return string
     */
    protected function key(string $name): string
    {
        return $this->id().'_'.$name;
    }
}
