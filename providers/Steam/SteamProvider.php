<?php

declare(strict_types=1);

namespace Sso\Providers\Steam;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Sso\Contracts\LooksUpAccounts;
use Sso\Contracts\ReadsCallback;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\SsoSettings;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

final class SteamProvider implements LooksUpAccounts, ReadsCallback
{
    private const string ENDPOINT = 'https://steamcommunity.com/openid/login';

    private const string OPENID_NS = 'http://specs.openid.net/auth/2.0';

    private const string IDENTIFIER_SELECT = 'http://specs.openid.net/auth/2.0/identifier_select';

    private const array SIGNED = ['op_endpoint', 'claimed_id', 'identity', 'return_to', 'response_nonce'];

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
    public function id(): string
    {
        return 'steam';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'Steam';
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
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make($this->key('api_key'), $this->key('api_key'), '', ['nullable', 'string', 'max:255'])
                ->label('Web API key')
                ->tab($this->name())
                ->help('Optional. Used to show the Steam display name and avatar on Connections. Create one at https://steamcommunity.com/dev/apikey. Steam does not share email addresses, so users must connect Steam from Account > Connections before they can sign in with it.')
                ->secret(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function enabled(): bool
    {
        return $this->config->boolean($this->key('enabled'));
    }

    /**
     * @inheritdoc
     */
    public function accountIdPattern(): string
    {
        return '^7656119[0-9]{10}$';
    }

    /**
     * @inheritdoc
     */
    public function accountIdHint(): string
    {
        return 'A SteamID64 is 17 digits and starts with 7656119. It is the number in the address of a Steam profile without a custom URL.';
    }

    /**
     * @inheritdoc
     */
    public function canLookUp(): bool
    {
        return $this->config->string($this->key('api_key')) !== '';
    }

    /**
     * @inheritdoc
     */
    public function lookUp(string $id): ?ExternalIdentity
    {
        $player = $this->fetchPlayer($id);

        return $player === [] ? null : $this->identity($id, $player);
    }

    /**
     * @inheritdoc
     */
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::ENDPOINT.'?'.http_build_query([
            'openid.ns' => self::OPENID_NS,
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $redirectUri.'?'.http_build_query(['state' => $state]),
            'openid.realm' => $this->realm($redirectUri),
            'openid.identity' => self::IDENTIFIER_SELECT,
            'openid.claimed_id' => self::IDENTIFIER_SELECT,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @inheritdoc
     */
    public function callbackCode(Request $request): ?string
    {
        $query = $request->server->get('QUERY_STRING');
        $params = $this->assertion(HeaderUtils::parseQuery(is_string($query) ? $query : ''));
        if ($this->param($params, 'openid.mode') !== 'id_res') {
            return null;
        }

        $returnTo = HeaderUtils::parseQuery((string) parse_url($this->param($params, 'openid.return_to'), PHP_URL_QUERY));
        $state = $request->query('state');
        if (! is_string($state) || ($returnTo['state'] ?? null) !== $state) {
            throw new SsoException(SsoException::STATE);
        }

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @inheritdoc
     */
    public function identify(string $code, string $redirectUri): ExternalIdentity
    {
        $params = $this->assertion(HeaderUtils::parseQuery($code));
        $claimedId = $this->param($params, 'openid.claimed_id');
        $signed = explode(',', $this->param($params, 'openid.signed'));

        if ($this->param($params, 'openid.mode') !== 'id_res'
            || $this->param($params, 'openid.ns') !== self::OPENID_NS
            || $this->param($params, 'openid.op_endpoint') !== self::ENDPOINT
            || ! str_starts_with($this->param($params, 'openid.return_to'), $redirectUri.'?')
            || $this->param($params, 'openid.identity') !== $claimedId
            || array_diff(self::SIGNED, $signed) !== []
            || preg_match('#^https://steamcommunity\.com/openid/id/(\d{17})$#D', $claimedId, $matches) !== 1) {
            throw new SsoException(SsoException::PROVIDER);
        }

        $verification = Http::timeout(10)
            ->withBody(http_build_query([...$params, 'openid.mode' => 'check_authentication'], '', '&', PHP_QUERY_RFC3986), 'application/x-www-form-urlencoded')
            ->post(self::ENDPOINT)
            ->throw()
            ->body();
        if (preg_match('/^is_valid:true$/m', $verification) !== 1) {
            throw new SsoException(SsoException::PROVIDER);
        }

        $steamId = $matches[1];

        return $this->identity($steamId, $this->player($steamId));
    }

    /**
     * @param string
     * @param array
     * @return ExternalIdentity
     */
    private function identity(string $steamId, array $player): ExternalIdentity
    {
        $name = $player['personaname'] ?? null;
        $avatar = $player['avatarfull'] ?? null;

        return new ExternalIdentity(
            $this->id(),
            $steamId,
            is_string($name) && $name !== '' ? $name : $steamId,
            null,
            false,
            is_string($avatar) && $avatar !== '' ? $avatar : null,
        );
    }

    /**
     * @param array
     * @return array
     */
    private function assertion(array $query): array
    {
        return array_filter(
            $query,
            fn (mixed $value, int|string $key): bool => is_string($key) && str_starts_with($key, 'openid.') && is_string($value),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param array
     * @param string
     * @return string
     */
    private function param(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param string
     * @return array
     */
    private function player(string $steamId): array
    {
        if (! $this->canLookUp()) {
            return [];
        }

        try {
            return $this->fetchPlayer($steamId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param string
     * @return array
     */
    private function fetchPlayer(string $steamId): array
    {
        $players = Http::acceptJson()->timeout(10)->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/', [
            'key' => $this->config->string($this->key('api_key')),
            'steamids' => $steamId,
        ])->throw()->json('response.players');

        foreach (is_array($players) ? $players : [] as $player) {
            if (is_array($player) && ($player['steamid'] ?? null) === $steamId) {
                return $player;
            }
        }

        return [];
    }

    /**
     * @param string
     * @return string
     */
    private function realm(string $redirectUri): string
    {
        $scheme = parse_url($redirectUri, PHP_URL_SCHEME);
        $host = parse_url($redirectUri, PHP_URL_HOST);
        $port = parse_url($redirectUri, PHP_URL_PORT);
        if (! is_string($scheme) || ! is_string($host)) {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $scheme.'://'.$host.(is_int($port) ? ':'.$port : '').'/';
    }

    /**
     * @param string
     * @return string
     */
    private function key(string $name): string
    {
        return $this->id().'_'.$name;
    }
}
