import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    Alert,
    Input,
    Label,
    Spinner,
    Switch,
    useFieldContext,
    type AdminUserFormValues,
    type ExtensionField,
    type FormExtension,
} from '@pterodactyl/sdk';
import { brandOf } from './brand';
import {
    accountProvidersQueryOptions,
    accountQueryOptions,
    matchesPattern,
    type SsoAccountProvider,
} from './api';

export type AccountFieldValues = Record<string, string | boolean | null>;

type PlaceholderKey = keyof Pick<AdminUserFormValues, 'email' | 'username' | 'nameFirst' | 'nameLast'>;

interface CoreForm {
    getFieldValue(name: PlaceholderKey): unknown;
    setFieldValue(name: PlaceholderKey, value: string): void;
}

const PLACEHOLDER_KEYS: readonly PlaceholderKey[] = ['email', 'username', 'nameFirst', 'nameLast'];

/**
 * @param {T} value
 * @param {number} delay
 * @returns {T}
 */
function useDebounced<T>(value: T, delay: number): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timeout = setTimeout(() => setDebounced(value), delay);
        return () => clearTimeout(timeout);
    }, [value, delay]);

    return debounced;
}

/**
 * The panel's own user form, which these fields render inside when creating a user.
 *
 * @returns {CoreForm | null}
 */
function useCoreForm(): CoreForm | null {
    try {
        return useFieldContext<unknown>().form as unknown as CoreForm;
    } catch {
        return null;
    }
}

/**
 * Fills the panel's required email, username and name fields with placeholders while the user
 * is set up on first sign-in, and clears them again when that is turned off. Values the admin
 * typed are left alone. The panel replaces taken placeholders when it saves.
 *
 * @param {{ provider: SsoAccountProvider; id: string } | null} account
 */
function PlaceholderDetails({ account }: { account: { provider: SsoAccountProvider; id: string } | null }) {
    const form = useCoreForm();
    const filled = useRef<Partial<Record<PlaceholderKey, string>>>({});
    const provider = account?.provider;
    const id = account?.id;

    useEffect(() => {
        if (!form) return;

        const next: Partial<Record<PlaceholderKey, string>> =
            provider && id
                ? {
                      email: `${id}@${provider.id}.invalid`,
                      username: `${provider.id}-${id}`,
                      nameFirst: provider.name,
                      nameLast: 'User',
                  }
                : {};

        for (const key of PLACEHOLDER_KEYS) {
            const current = form.getFieldValue(key);
            if (current !== '' && current !== filled.current[key]) continue;
            const value = next[key] ?? '';
            if (current !== value) form.setFieldValue(key, value);
        }

        filled.current = next;
    }, [form, provider, id]);

    return null;
}

/**
 * @param {{ provider: SsoAccountProvider; id: string }} props
 * @returns {JSX.Element | null}
 */
function AccountPreview({ provider, id }: { provider: SsoAccountProvider; id: string }) {
    const debounced = useDebounced(id, 400);
    const account = useQuery({ ...accountQueryOptions(provider.id, debounced), enabled: debounced === id });
    const brand = brandOf(provider.id);

    if (debounced !== id || account.isPending) {
        return (
            <p className={'sso:mt-2 sso:flex sso:items-center sso:gap-2 sso:text-sm sso:text-muted-foreground'}>
                <Spinner size={'small'} />
                Looking up this {provider.name} account…
            </p>
        );
    }

    if (account.isError) {
        return (
            <p className={'sso:mt-2 sso:text-sm sso:text-muted-foreground'}>
                {provider.name} could not be reached to check this ID.
            </p>
        );
    }

    if (account.data === null) {
        return <p className={'input-help error'}>No {provider.name} account has this ID.</p>;
    }

    return (
        <div
            className={
                'sso:mt-2 sso:flex sso:items-center sso:gap-3 sso:rounded-sm sso:border sso:border-border sso:bg-card sso:p-2'
            }
        >
            {account.data.avatar_url ? (
                <img src={account.data.avatar_url} alt={''} className={'sso:size-8 sso:shrink-0 sso:rounded-full'} />
            ) : (
                <span
                    className={`sso:flex sso:size-8 sso:shrink-0 sso:items-center sso:justify-center sso:rounded-full sso:border ${brand.className}`}
                >
                    {brand.icon}
                </span>
            )}
            <div className={'sso:min-w-0'}>
                <p className={'sso:truncate sso:text-sm sso:font-medium sso:text-foreground'}>{account.data.name}</p>
                <p className={'sso:truncate sso:text-xs sso:text-muted-foreground'}>
                    {provider.name} account {account.data.id}
                </p>
            </div>
        </div>
    );
}

/**
 * @param {{ provider: SsoAccountProvider; field: ExtensionField<string | boolean | null> }} props
 * @returns {JSX.Element}
 */
function AccountIdField({
    provider,
    field,
}: {
    provider: SsoAccountProvider;
    field: ExtensionField<string | boolean | null>;
}) {
    const value = typeof field.value === 'string' ? field.value : '';
    const invalid = value !== '' && !matchesPattern(provider.pattern, value);

    return (
        <div>
            <Label htmlFor={field.id}>{provider.name} ID</Label>
            <Input
                id={field.id}
                value={value}
                inputMode={'numeric'}
                autoComplete={'off'}
                spellCheck={false}
                $hasError={!!field.error || invalid}
                onValueChange={(next: string) => field.setValue(next.trim() === '' ? null : next.trim())}
            />
            {field.error ? (
                <p className={'input-help error'}>{field.error}</p>
            ) : invalid ? (
                <p className={'input-help error'}>That is not a valid {provider.name} ID.</p>
            ) : (
                <p className={'input-help'}>{provider.hint}</p>
            )}
            {provider.lookup && value !== '' && !invalid && <AccountPreview provider={provider} id={value} />}
        </div>
    );
}

/**
 * The provider account IDs on the admin user forms, and whether the user sets up their
 * account on first sign-in.
 */
const AccountFields: FormExtension<AccountFieldValues, 'admin.user'> = ({ mode, values, field }) => {
    const providers = useQuery(accountProvidersQueryOptions());
    const setup = field('setup_on_first_login');

    if (providers.isPending) {
        return (
            <div className={'sso:flex sso:justify-center sso:p-4'}>
                <Spinner size={'small'} />
            </div>
        );
    }

    if (providers.isError) {
        return <p className={'input-help error'}>The sign-in providers could not be loaded.</p>;
    }

    if (providers.data.length === 0) {
        return (
            <p className={'sso:text-sm sso:text-muted-foreground'}>
                Enable Discord or Steam sign-in to link accounts here.
            </p>
        );
    }

    const pending = setup.value === true;
    const first = providers.data
        .map((provider) => ({ provider, id: values[provider.field] }))
        .find(
            (account): account is { provider: SsoAccountProvider; id: string } =>
                typeof account.id === 'string' && matchesPattern(account.provider.pattern, account.id)
        );

    return (
        <div className={'sso:flex sso:flex-col sso:gap-6'}>
            {providers.data.map((provider) => (
                <AccountIdField key={provider.id} provider={provider} field={field(provider.field)} />
            ))}
            <div>
                <Switch
                    id={setup.id}
                    checked={pending}
                    onChange={(checked) => setup.setValue(checked)}
                    label={'Set up on first sign-in'}
                    description={
                        mode === 'create'
                            ? 'Create the account without an email, username or password. The user signs in with an account above and then chooses their own.'
                            : 'The user chooses their username, email and password the next time they sign in.'
                    }
                />
                {setup.error && <p className={'input-help error'}>{setup.error}</p>}
            </div>
            {mode === 'create' && pending && (
                <Alert type={'info'}>
                    The email, username and name above are placeholders until the user replaces them. Nothing is
                    emailed to a placeholder address.
                </Alert>
            )}
            {mode === 'edit' && pending && (
                <Alert type={'warning'}>This user has not finished setting up their account yet.</Alert>
            )}
            {mode === 'create' && <PlaceholderDetails account={pending ? (first ?? null) : null} />}
        </div>
    );
};

export default AccountFields;
