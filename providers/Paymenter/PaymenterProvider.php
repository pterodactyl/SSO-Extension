<?php

declare(strict_types=1);

namespace Sso\Providers\Paymenter;

use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Sso\Data\ExternalIdentity;
use Sso\OAuth2Provider;

final class PaymenterProvider extends OAuth2Provider
{
    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'paymenter';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        return 'Paymenter';
    }

    /**
     * Paymenter is self-hosted, so the address of the store is a setting of its own.
     *
     * @inheritdoc
     */
    public function settings(): array
    {
        return [
            ...parent::settings(),
            ExtensionSettingDefinition::make($this->key('url'), $this->key('url'), '', ['nullable', 'string', 'max:255', 'url:http,https', 'regex:#^(https://|http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/|$))#i'])
                ->label('Paymenter URL')
                ->tab($this->name())
                ->help('The address of your Paymenter, such as https://billing.example.com (http:// only for localhost). Create the client in Paymenter under Admin, OAuth Clients.')
                ->normalizeUsing(fn (mixed $value): string => is_string($value) ? rtrim(mb_trim($value), '/') : ''),
        ];
    }

    /**
     * @inheritdoc
     */
    public function enabled(): bool
    {
        return parent::enabled() && $this->url() !== '';
    }

    /**
     * @inheritdoc
     */
    protected function authorizeEndpoint(): string
    {
        return $this->url().'/oauth/authorize';
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return $this->url().'/api/oauth/token';
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return $this->url().'/api/me';
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        return ['profile'];
    }

    /**
     * Paymenter's authorization page every time. Without it, a customer who approved the panel before and then signs
     * in on Paymenter's login page is stuck there: Paymenter 1.5.9 opens the next page with a JavaScript fetch, which
     * the browser does not let follow the redirect back to the panel.
     *
     * @inheritdoc
     */
    protected function authorizationParameters(): array
    {
        return ['prompt' => 'consent'];
    }

    /**
     * GET /api/me returns Paymenter's user: id, first_name, last_name, email and email_verified_at, which Paymenter
     * sets when the address is verified and clears when it changes.
     *
     * @inheritdoc
     */
    protected function mapIdentity(array $user): ExternalIdentity
    {
        $email = $user['email'] ?? null;
        $email = is_string($email) && $email !== '' ? $email : null;
        $name = mb_trim(implode(' ', array_filter([$user['first_name'] ?? null, $user['last_name'] ?? null], 'is_string')));
        $verifiedAt = $user['email_verified_at'] ?? null;

        return new ExternalIdentity(
            $this->id(),
            $this->stringId($user['id'] ?? null),
            $name !== '' ? $name : ($email ?? $this->stringId($user['id'] ?? null)),
            $email,
            $email !== null && is_string($verifiedAt) && $verifiedAt !== '',
            null,
        );
    }

    /**
     * @return string
     */
    private function url(): string
    {
        return $this->config->string($this->key('url'));
    }
}
