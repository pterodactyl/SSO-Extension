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
                ->tab($this->name())
                ->field('toggle')
                ->normalizeUsing(fn(mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make($this->key('client_id'), $this->key('client_id'), '', ['nullable', 'string', 'max:255'])
                ->label('Client ID')
                ->tab($this->name())
                ->help('Set the redirect URL of the ' . $this->name() . ' application to ' . SsoRoutes::callback($this->id())),
            ExtensionSettingDefinition::make($this->key('client_secret'), $this->key('client_secret'), '', ['nullable', 'string', 'max:255'])
                ->label('Client secret')
                ->tab($this->name())
                ->secret(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function enabled(): bool
    {
        return $this->config->boolean($this->key('enabled'))
            && $this->config->string($this->key('client_id')) !== ''
            && $this->config->string($this->key('client_secret')) !== '';
    }

    /**
     * @inheritdoc
     */
    public function usesPkce(): bool {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function authorizationUrl(string $state, string $redirectUri, ?string $codeChallenge = null): string
    {
        $parameters = [
            ...$this->authorizationParameters(),
            'response_type' => 'code',
            'client_id' => $this->config->string($this->key('client_id')),
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ];

        if ($this->usesPkce()) {
            if (
                $codeChallenge === null
                || preg_match('/^[A-Za-z0-9_-]{43}$/D', $codeChallenge) !== 1
            ) {
                throw new SsoException(SsoException::STATE);
            }

            $parameters['code_challenge'] = $codeChallenge;
            $parameters['code_challenge_method'] = 'S256';
        }

        return $this->authorizeEndpoint() . '?' . http_build_query(
            $parameters,
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    /**
     * @inheritdoc
     */
    public function identify(
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): ExternalIdentity {
        $parameters = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->config->string($this->key('client_id')),
            'client_secret' => $this->config->string($this->key('client_secret')),
        ];

        if ($this->usesPkce()) {
            if (
                $codeVerifier === null
                || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $codeVerifier) !== 1
            ) {
                throw new SsoException(SsoException::STATE);
            }

            $parameters['code_verifier'] = $codeVerifier;
        }

        $token = Http::asForm()
            ->acceptJson()
            ->timeout(10)
            ->post($this->tokenEndpoint(), $parameters)
            ->throw()
            ->json('access_token');

        if (!is_string($token) || $token === '') {
            throw new SsoException(SsoException::PROVIDER);
        }

        $user = Http::acceptJson()
            ->timeout(10)
            ->withToken($token)
            ->get($this->userEndpoint())
            ->throw()
            ->json();

        if (!is_array($user)) {
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
        if (!is_string($id) || $id === '') {
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
     * @param string
     * @return string
     */
    protected function key(string $name): string
    {
        return $this->id() . '_' . $name;
    }
}
