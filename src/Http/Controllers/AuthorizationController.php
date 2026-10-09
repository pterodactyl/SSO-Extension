<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Models\User;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\Pkce;
use Sso\ProviderRegistry;
use Sso\Services\IdentityResolver;
use Sso\SsoRoutes;
use Throwable;

final class AuthorizationController
{
    public const string FLOW = 'sso.flow';
    public const string CHECKPOINT = 'sso.checkpoint';

    private const string LOGIN = 'login';
    private const string LINK = 'link';

    protected ProviderRegistry $providers;
    protected IdentityResolver $resolver;
    protected CompletesLogins $logins;

    public function __construct(
        ProviderRegistry $providers,
        IdentityResolver $resolver,
        CompletesLogins $logins,
    ) {
        $this->providers = $providers;
        $this->resolver = $resolver;
        $this->logins = $logins;
    }

    public function login(
        Request $request,
        string $provider,
    ): RedirectResponse {
        if ($request->user() !== null) {
            return redirect()->to('/');
        }

        return $this->start($request, $provider, self::LOGIN);
    }

    public function link(
        Request $request,
        string $provider,
    ): RedirectResponse {
        return $this->start($request, $provider, self::LINK);
    }

    public function callback(
        Request $request,
        string $provider,
    ): RedirectResponse {
        $flow = $request->session()->pull(self::FLOW);

        $intent = is_array($flow)
            && ($flow['intent'] ?? null) === self::LINK
                ? self::LINK
                : self::LOGIN;

        try {
            $identity = $this->identify($request, $provider, $flow);

            return $intent === self::LINK
                ? $this->completeLink($request, $identity)
                : $this->completeLogin($request, $identity);
        } catch (SsoException $exception) {
            return $this->failed($intent, $exception->reason);
        }
    }

    private function start(
        Request $request,
        string $id,
        string $intent,
    ): RedirectResponse {
        $provider = $this->providers->find($id);

        if ($provider === null || ! $provider->enabled()) {
            return $this->failed($intent, SsoException::UNAVAILABLE);
        }

        $state = Str::random(40);

        $codeVerifier = $provider->usesPkce()
            ? Pkce::verifier()
            : null;

        $request->session()->put(self::FLOW, [
            'provider' => $id,
            'state' => $state,
            'intent' => $intent,
            'code_verifier' => $codeVerifier,
        ]);

        $codeChallenge = $codeVerifier !== null
            ? Pkce::challenge($codeVerifier)
            : null;

        return redirect()->away(
            $provider->authorizationUrl(
                $state,
                SsoRoutes::callback($id),
                $codeChallenge,
            ),
        );
    }

    private function identify(
        Request $request,
        string $id,
        mixed $flow,
    ): ExternalIdentity {
        $expected = is_array($flow)
            && ($flow['provider'] ?? null) === $id
                ? ($flow['state'] ?? null)
                : null;

        $state = $request->query('state');

        if (
            ! is_string($expected)
            || ! is_string($state)
            || ! hash_equals($expected, $state)
        ) {
            throw new SsoException(SsoException::STATE);
        }

        $code = $request->query('code');

        if (
            $request->query('error') !== null
            || ! is_string($code)
            || $code === ''
        ) {
            throw new SsoException(SsoException::DENIED);
        }

        $provider = $this->providers->find($id);

        if ($provider === null || ! $provider->enabled()) {
            throw new SsoException(SsoException::UNAVAILABLE);
        }

        $codeVerifier = is_array($flow)
            ? ($flow['code_verifier'] ?? null)
            : null;

        if (
            $provider->usesPkce()
            && (
                ! is_string($codeVerifier)
                || preg_match(
                    '/^[A-Za-z0-9._~-]{43,128}$/D',
                    $codeVerifier,
                ) !== 1
            )
        ) {
            throw new SsoException(SsoException::STATE);
        }

        try {
            return $provider->identify(
                $code,
                SsoRoutes::callback($id),
                is_string($codeVerifier) ? $codeVerifier : null,
            );
        } catch (SsoException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            report($throwable);

            throw new SsoException(SsoException::PROVIDER);
        }
    }

    private function completeLogin(
        Request $request,
        ExternalIdentity $identity,
    ): RedirectResponse {
        $result = $this->logins->complete(
            $this->resolver->userFor($identity),
        );

        if ($result->complete) {
            return redirect()->to($result->intended);
        }

        $request->session()->put(self::CHECKPOINT, true);

        return redirect()->to('/auth/login?sso=checkpoint');
    }

    private function completeLink(
        Request $request,
        ExternalIdentity $identity,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new SsoException(SsoException::STATE);
        }

        $this->resolver->link($user, $identity);

        return redirect()->to(
            '/account/connections?'.http_build_query([
                'sso_linked' => $identity->provider,
            ]),
        );
    }

    private function failed(
        string $intent,
        string $reason,
    ): RedirectResponse {
        $path = $intent === self::LINK
            ? '/account/connections'
            : '/auth/login';

        return redirect()->to(
            $path.'?'.http_build_query([
                'sso_error' => $reason,
            ]),
        );
    }
}