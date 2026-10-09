<?php

declare(strict_types=1);

namespace Sso\Contracts;

use Sso\Data\ExternalIdentity;

interface IdentityProvider
{
    /**
     * @return string
     */
    public function id(): string;

    /**
     * @return string
     */
    public function name(): string;

    /**
     * @return array
     */
    public function settings(): array;

    /**
     * @return bool
     */
    public function enabled(): bool;

    /**
     * @return bool
     */
    public function usesPkce(): bool;

    /**
     * @param string
     * @param string
     * @param string|null
     * @return string
     */
    public function authorizationUrl(string $state, string $redirectUri, ?string $codeChallenge = null,): string;

    /**
     * @param string
     * @param string
     * @param string|null
     * @return ExternalIdentity
     */
    public function identify(string $code, string $redirectUri, ?string $codeVerifier = null,): ExternalIdentity;
}
