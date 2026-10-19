<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Actions\Users\CreateUser;
use Pterodactyl\Contracts\Users\UpdatesUserPasswords;
use Pterodactyl\Contracts\Users\UpdatesUsers;
use Pterodactyl\Models\User;
use Sso\Models\Identity;
use Sso\ProviderRegistry;
use Sso\Services\AccountSetup;
use Sso\Services\Placeholders;

/**
 * The "Finish your account" screen of an account an admin created for a provider account.
 * It only ever changes the signed-in user.
 */
final class SetupController
{
    /**
     * @var AccountSetup
     */
    protected AccountSetup $setup;

    /**
     * @var ProviderRegistry
     */
    protected ProviderRegistry $providers;

    /**
     * @var UpdatesUsers
     */
    protected UpdatesUsers $users;

    /**
     * @var UpdatesUserPasswords
     */
    protected UpdatesUserPasswords $passwords;

    /**
     * @param AccountSetup
     * @param ProviderRegistry
     * @param UpdatesUsers
     * @param UpdatesUserPasswords
     */
    public function __construct(AccountSetup $setup, ProviderRegistry $providers, UpdatesUsers $users, UpdatesUserPasswords $passwords)
    {
        $this->setup = $setup;
        $this->providers = $providers;
        $this->users = $users;
        $this->passwords = $passwords;
    }

    /**
     * @param Request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $pending = $this->setup->pending($user);
        $identities = $pending ? Identity::query()->where('user_id', $user->id)->get() : collect();
        $placeholderName = $user->name_last === Placeholders::LAST_NAME
            && $identities->contains(fn (Identity $identity): bool => $this->providers->find($identity->provider)?->name() === $user->name_first);
        $suggested = $request->hasSession() ? $request->session()->get(AccountSetup::SESSION_EMAIL) : null;

        return new JsonResponse(['data' => [
            'pending' => $pending,
            'username' => $identities->contains(fn (Identity $identity): bool => Placeholders::isUsernameFor($user->username, $identity->provider, $identity->provider_user_id)) ? '' : $user->username,
            'email' => Placeholders::isReservedEmail($user->email) ? (is_string($suggested) ? $suggested : '') : $user->email,
            'name_first' => $placeholderName ? '' : $user->name_first,
            'name_last' => $placeholderName ? '' : $user->name_last,
        ]]);
    }

    /**
     * @param Request
     * @return Response|JsonResponse
     */
    public function store(Request $request): Response|JsonResponse
    {
        $user = $this->user($request);
        if (! $this->setup->pending($user)) {
            return new JsonResponse(['errors' => [['code' => 'Conflict', 'status' => '409', 'detail' => 'Your account is already set up.']]], 409);
        }

        $rules = CreateUser::rules($user);
        $validated = $request->validate([
            'username' => $rules['username'],
            'email' => [...$rules['email'], $this->deliverable(...)],
            'name_first' => $rules['name_first'],
            'name_last' => $rules['name_last'],
            'password' => ['nullable', 'confirmed', ...CreateUser::passwordRules()],
        ]);

        DB::transaction(function () use ($user, $validated): void {
            $this->users->update($user, [
                'username' => $validated['username'],
                'email' => $validated['email'],
                'name_first' => $validated['name_first'],
                'name_last' => $validated['name_last'],
            ]);

            if (is_string($validated['password'] ?? null) && $validated['password'] !== '') {
                $this->passwords->update($user, $validated['password']);
            }

            $this->setup->clear($user);
        });

        if ($request->hasSession()) {
            $request->session()->forget(AccountSetup::SESSION_EMAIL);
        }

        return new Response(status: 204);
    }

    /**
     * @param string
     * @param mixed
     * @param Closure
     */
    private function deliverable(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Placeholders::isReservedEmail($value)) {
            $fail('Enter an email address you can receive mail at.');
        }
    }

    /**
     * @param Request
     * @return User
     */
    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
