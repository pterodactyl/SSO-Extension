<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Models\User;
use Sso\Models\Identity;
use Sso\ProviderRegistry;
use Sso\Services\AccountSetup;
use Sso\Services\Placeholders;
use Sso\SsoRoutes;

final class ConnectionController
{
    /**
     * @var ProviderRegistry
     */
    protected ProviderRegistry $providers;

    /**
     * @var AccountSetup
     */
    protected AccountSetup $setup;

    /**
     * @param ProviderRegistry
     * @param AccountSetup
     */
    public function __construct(ProviderRegistry $providers, AccountSetup $setup)
    {
        $this->providers = $providers;
        $this->setup = $setup;
    }

    /**
     * @param Request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $linked = Identity::query()->where('user_id', $request->user()?->id)->get()->keyBy('provider');

        $data = [];
        foreach ($this->providers->all() as $provider) {
            $identity = $linked->get($provider->id());
            $enabled = $provider->enabled();
            if (! $enabled && $identity === null) {
                continue;
            }

            $data[] = [
                'id' => $provider->id(),
                'name' => $provider->name(),
                'enabled' => $enabled,
                'link_url' => SsoRoutes::link($provider->id()),
                'account' => $identity === null ? null : [
                    'name' => $identity->name,
                    'email' => $identity->email,
                    'avatar_url' => $identity->avatar_url,
                    'linked_at' => $identity->created_at?->toAtomString(),
                ],
            ];
        }

        return new JsonResponse(['data' => $data]);
    }

    /**
     * @param Request
     * @param string
     * @return Response|JsonResponse
     */
    public function destroy(Request $request, string $provider): Response|JsonResponse
    {
        $user = $request->user();
        $reason = $user instanceof User ? $this->lastWayIn($user, $provider) : null;
        if ($reason !== null) {
            return new JsonResponse(['errors' => [['code' => 'Conflict', 'status' => '409', 'detail' => $reason]]], 409);
        }

        Identity::query()->where('user_id', $user?->id)->where('provider', $provider)->delete();

        return new Response(status: 204);
    }

    /**
     * Why disconnecting the provider would lock the user out, if it would. An account that is
     * not set up yet, or still has a placeholder email it cannot reset a password with, signs
     * in through its connections alone.
     *
     * @param User
     * @param string
     * @return string|null
     */
    private function lastWayIn(User $user, string $provider): ?string
    {
        if ($this->setup->pending($user)) {
            return 'Finish setting up your account before you disconnect a sign-in method.';
        }

        if (! Placeholders::isReservedEmail($user->email)) {
            return null;
        }

        $identities = Identity::query()->where('user_id', $user->id)->pluck('provider');

        return $identities->contains($provider) && $identities->count() === 1
            ? 'This is the only way to sign in to your account. Ask an administrator to set your email address before you disconnect it.'
            : null;
    }
}
