<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Pterodactyl\Contracts\Users\CompletesLogins;
use Pterodactyl\Models\User;
use Sso\Contracts\ReadsCallback;
use Sso\Contracts\SupportsPkce;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\Pkce;
use Sso\ProviderRegistry;
use Sso\Services\AccountSetup;
use Sso\Services\IdentityResolver;
use Sso\Services\Placeholders;
use Sso\SsoRoutes;
use Throwable;

final class AuthorizationController
{
    public const string FLOW = 'sso.flow';

    public const string CHECKPOINT = 'sso.checkpoint';

    private const string LOGIN = 'login';

    private const string LINK = 'link';

    /**
     * @var ProviderRegistry
     */
    protected ProviderRegistry $providers;

    /**
     * @var IdentityResolver
     */
    protected IdentityResolver $resolver;

    /**
     * @var CompletesLogins
     */
    protected CompletesLogins $logins;

    /**
     * @var AccountSetup
     */
    protected AccountSetup $setup;

    /**
     * @param ProviderRegistry
     * @param IdentityResolver
     * @param CompletesLogins
     * @param AccountSetup
     */
    public function __construct(ProviderRegistry $providers, IdentityResolver $resolver, CompletesLogins $logins, AccountSetup $setup)
    {
        $this->providers = $providers;
        $this->resolver = $resolver;
        $this->logins = $logins;
        $this->setup = $setup;
    }

    /**
     * @param Request
     * @param string
     * @return RedirectResponse
     */
    public function login(Request $request, string $provider): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->to('/');
        }

        return $this->start($request, $provider, self::LOGIN);
    }

    /**
     * @param Request
     * @param string
     * @return RedirectResponse
     */
    public function link(Request $request, string $provider): RedirectResponse
    {
        return $this->start($request, $provider, self::LINK);
    }

    /**
     * @param Request
     * @param string
     * @return RedirectResponse
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $flow = $request->session()->pull(self::FLOW);
        $intent = is_array($flow) && ($flow['intent'] ?? null) === self::LINK ? self::LINK : self::LOGIN;

        try {
            $identity = $this->identify($request, $provider, $flow);

            return $intent === self::LINK ? $this->completeLink($request, $identity) : $this->completeLogin($request, $identity);
        } catch (SsoException $exception) {
            return $this->failed($intent, $exception->reason);
        }
    }

    /**
     * @param Request
     * @param string
     * @param string
     * @return RedirectResponse
     */
    private function start(Request $request, string $id, string $intent): RedirectResponse
    {
        $provider = $this->providers->find($id);
        if ($provider === null || ! $provider->enabled()) {
            return $this->failed($intent, SsoException::UNAVAILABLE);
        }

        $state = Str::random(40);
        $verifier = $provider instanceof SupportsPkce && $provider->usesPkce() ? Pkce::verifier() : null;

        try {
            $url = $provider instanceof SupportsPkce
                ? $provider->authorizationUrl($state, SsoRoutes::callback($id), $verifier === null ? null : Pkce::challenge($verifier))
                : $provider->authorizationUrl($state, SsoRoutes::callback($id));
        } catch (SsoException $exception) {
            return $this->failed($intent, $exception->reason);
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->failed($intent, SsoException::PROVIDER);
        }

        $request->session()->put(self::FLOW, ['provider' => $id, 'state' => $state, 'intent' => $intent, 'code_verifier' => $verifier]);

        return redirect()->away($url);
    }

    /**
     * @param Request
     * @param string
     * @param mixed
     * @return ExternalIdentity
     */
    private function identify(Request $request, string $id, mixed $flow): ExternalIdentity
    {
        $expected = is_array($flow) && ($flow['provider'] ?? null) === $id ? ($flow['state'] ?? null) : null;
        $state = $request->query('state');
        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            throw new SsoException(SsoException::STATE);
        }

        $provider = $this->providers->find($id);
        if ($provider === null || ! $provider->enabled()) {
            throw new SsoException(SsoException::UNAVAILABLE);
        }

        $code = $provider instanceof ReadsCallback ? $provider->callbackCode($request) : $request->query('code');
        if ($request->query('error') !== null || ! is_string($code) || $code === '') {
            throw new SsoException(SsoException::DENIED);
        }

        $verifier = $flow['code_verifier'] ?? null;

        try {
            return $provider instanceof SupportsPkce
                ? $provider->identify($code, SsoRoutes::callback($id), is_string($verifier) ? $verifier : null)
                : $provider->identify($code, SsoRoutes::callback($id));
        } catch (SsoException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            report($throwable);

            throw new SsoException(SsoException::PROVIDER);
        }
    }

    /**
     * @param Request
     * @param ExternalIdentity
     * @return RedirectResponse
     */
    private function completeLogin(Request $request, ExternalIdentity $identity): RedirectResponse
    {
        $user = $this->resolver->userFor($identity);
        $pending = $this->setup->pending($user);
        if ($pending && $identity->emailVerified && $identity->email !== null && ! Placeholders::isReservedEmail($identity->email)) {
            $request->session()->put(AccountSetup::SESSION_EMAIL, $identity->email);
        }

        $result = $this->logins->complete($user);
        if ($result->complete) {
            return redirect()->to($pending ? SsoRoutes::SETUP : $result->intended);
        }

        $request->session()->put(self::CHECKPOINT, true);

        return redirect()->to('/auth/login?sso=checkpoint');
    }

    /**
     * @param Request
     * @param ExternalIdentity
     * @return RedirectResponse
     */
    private function completeLink(Request $request, ExternalIdentity $identity): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new SsoException(SsoException::STATE);
        }

        $this->resolver->link($user, $identity);

        return redirect()->to('/account/connections?'.http_build_query(['sso_linked' => $identity->provider]));
    }

    /**
     * @param string
     * @param string
     * @return RedirectResponse
     */
    private function failed(string $intent, string $reason): RedirectResponse
    {
        $path = $intent === self::LINK ? '/account/connections' : '/auth/login';

        return redirect()->to($path.'?'.http_build_query(['sso_error' => $reason]));
    }
}
