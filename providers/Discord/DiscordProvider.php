<?php

declare(strict_types=1);

namespace Sso\Providers\Discord;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Sso\Contracts\LooksUpAccounts;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\OAuth2Provider;

final class DiscordProvider extends OAuth2Provider implements LooksUpAccounts
{
    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'discord';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'Discord';
    }

    /**
     * @inheritdoc
     */
    public function settings(): array
    {
        return [
            ...parent::settings(),
            ExtensionSettingDefinition::make($this->key('bot_token'), $this->key('bot_token'), '', ['nullable', 'string', 'max:255'])
                ->label('Bot token')
                ->tab($this->name())
                ->help('Optional. Lets the admin user forms show the Discord account a user ID belongs to. Create a bot for the application under Bot in the Discord developer portal and paste its token; it needs no permissions and does not have to join a server.')
                ->secret(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function accountIdPattern(): string
    {
        return '^[0-9]{17,20}$';
    }

    /**
     * @inheritdoc
     */
    public function accountIdHint(): string
    {
        return 'A Discord user ID is 17 to 20 digits. Turn on Developer Mode in Discord, then right-click the user and choose Copy User ID.';
    }

    /**
     * @inheritdoc
     */
    public function canLookUp(): bool
    {
        return $this->config->string($this->key('bot_token')) !== '';
    }

    /**
     * @inheritdoc
     */
    public function lookUp(string $id): ?ExternalIdentity
    {
        $response = Http::acceptJson()->timeout(10)
            ->withHeaders(['Authorization' => 'Bot '.$this->config->string($this->key('bot_token'))])
            ->get('https://discord.com/api/v10/users/'.rawurlencode($id));
        if ($response->notFound()) {
            return null;
        }

        $user = $response->throw()->json();
        if (! is_array($user)) {
            throw new SsoException(SsoException::PROVIDER);
        }

        return $this->mapIdentity($user);
    }

    /**
     * @inheritdoc
     */
    protected function authorizeEndpoint(): string
    {
        return 'https://discord.com/oauth2/authorize';
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return 'https://discord.com/api/oauth2/token';
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return 'https://discord.com/api/users/@me';
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        return ['identify', 'email'];
    }

    /**
     * @inheritdoc
     */
    protected function authorizationParameters(): array
    {
        return ['prompt' => 'none'];
    }

    /**
     * @inheritdoc
     */
    protected function mapIdentity(array $user): ExternalIdentity
    {
        $id = $user['id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new SsoException(SsoException::PROVIDER);
        }

        $username = is_string($user['username'] ?? null) ? $user['username'] : $id;
        $globalName = $user['global_name'] ?? null;
        $avatar = $user['avatar'] ?? null;
        $email = $user['email'] ?? null;

        return new ExternalIdentity(
            $this->id(),
            $id,
            is_string($globalName) && $globalName !== '' ? $globalName : $username,
            is_string($email) && $email !== '' ? $email : null,
            ($user['verified'] ?? false) === true,
            is_string($avatar) && $avatar !== '' ? sprintf('https://cdn.discordapp.com/avatars/%s/%s.png', $id, $avatar) : null,
        );
    }
}
