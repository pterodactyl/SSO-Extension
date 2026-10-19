<?php

declare(strict_types=1);

namespace Sso\Fields;

use Illuminate\Validation\Rule;
use Pterodactyl\Extensions\Fields;
use Pterodactyl\Models\User;
use Sso\Models\Identity;
use Sso\Rules\RequiresAccountId;
use Sso\Services\AccountIds;
use Sso\Services\AccountSetup;

/**
 * The provider account IDs and the first sign-in setup option on the admin user forms.
 */
final class AccountFields extends Fields
{
    /**
     * @var AccountIds
     */
    protected AccountIds $accounts;

    /**
     * @var AccountSetup
     */
    protected AccountSetup $setup;

    /**
     * @param AccountIds
     * @param AccountSetup
     */
    public function __construct(AccountIds $accounts, AccountSetup $setup)
    {
        $this->accounts = $accounts;
        $this->setup = $setup;
    }

    /**
     * @param User|null
     * @return array
     */
    public function rules(?User $user): array
    {
        $rules = [];
        foreach ($this->accounts->providers() as $provider) {
            $rules[AccountIds::field($provider)] = [
                'nullable',
                'string',
                'max:191',
                'regex:/'.addcslashes($provider->accountIdPattern(), '/').'/D',
                Rule::unique(Identity::class, 'provider_user_id')->where('provider', $provider->id())->ignore($user?->id, 'user_id'),
            ];
        }

        $rules[AccountIds::SETUP] = ['nullable', 'boolean', new RequiresAccountId(array_keys($rules))];

        return $rules;
    }

    /**
     * @param User
     * @return array
     */
    public function values(User $user): array
    {
        $linked = Identity::query()->where('user_id', $user->id)->pluck('provider_user_id', 'provider');

        $values = [];
        foreach ($this->accounts->providers() as $provider) {
            $values[AccountIds::field($provider)] = $linked->get($provider->id());
        }

        $values[AccountIds::SETUP] = $this->setup->pending($user);

        return $values;
    }

    /**
     * @param User
     * @param array
     */
    public function save(User $user, array $values): void
    {
        foreach ($this->accounts->providers() as $provider) {
            $field = AccountIds::field($provider);
            if (array_key_exists($field, $values)) {
                $this->accounts->assign($user, $provider->id(), is_string($values[$field]) && $values[$field] !== '' ? $values[$field] : null);
            }
        }

        if (! array_key_exists(AccountIds::SETUP, $values)) {
            return;
        }

        if (filter_var($values[AccountIds::SETUP], FILTER_VALIDATE_BOOLEAN)) {
            $this->setup->require($user);
        } else {
            $this->setup->clear($user);
        }
    }

    /**
     * @return array
     */
    public function attributes(): array
    {
        $attributes = [AccountIds::SETUP => 'set up on first sign-in'];
        foreach ($this->accounts->providers() as $provider) {
            $attributes[AccountIds::field($provider)] = $provider->name().' ID';
        }

        return $attributes;
    }

    /**
     * @return array
     */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->accounts->providers() as $provider) {
            $field = AccountIds::field($provider);
            $messages[$field.'.regex'] = 'That is not a valid '.$provider->name().' ID. '.$provider->accountIdHint();
            $messages[$field.'.unique'] = 'This '.$provider->name().' account is already linked to another user.';
        }

        return $messages;
    }
}
