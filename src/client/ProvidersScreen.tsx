import { SubmitEvent, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    ContentBox,
    PageContentBlock,
    ServerError,
    Spinner,
    httpErrorToHuman,
    toast,
} from '@pterodactyl/sdk';
import {
    deleteOidcProvider,
    oidcProvidersQueryOptions,
    saveOidcProvider,
    type OidcProviderInput,
    type OidcProviderRecord,
} from './api';

const inputClass =
    'sso:w-full sso:rounded-sm sso:border sso:border-border sso:bg-card sso:px-3 sso:py-2 sso:text-sm sso:text-foreground';

/**
 * @param {string} value
 * @returns {string[]}
 */
function lines(value: string): string[] {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');
}

/**
 * @param {{ record: OidcProviderRecord | null, callbackBase: string, onDone: () => void }} props
 * @returns {JSX.Element}
 */
function ProviderForm({
    record,
    callbackBase,
    onDone,
}: {
    record: OidcProviderRecord | null;
    callbackBase: string;
    onDone: () => void;
}) {
    const queryClient = useQueryClient();
    const [slug, setSlug] = useState(record?.slug ?? '');
    const [label, setLabel] = useState(record?.label ?? '');
    const [issuer, setIssuer] = useState(record?.issuer ?? '');
    const [clientId, setClientId] = useState(record?.client_id ?? '');
    const [clientSecret, setClientSecret] = useState('');
    const [scopes, setScopes] = useState(record?.scopes ?? 'openid email profile');
    const [groupsClaim, setGroupsClaim] = useState(record?.groups_claim ?? 'groups');
    const [adminGroups, setAdminGroups] = useState((record?.admin_groups ?? []).join('\n'));
    const [memberGroups, setMemberGroups] = useState((record?.member_groups ?? []).join('\n'));
    const [createUsers, setCreateUsers] = useState(record?.create_users ?? false);
    const [enabled, setEnabled] = useState(record?.enabled ?? true);

    const save = useMutation({
        mutationFn: () => {
            const input: OidcProviderInput = {
                label,
                issuer,
                client_id: clientId,
                scopes,
                groups_claim: groupsClaim,
                admin_groups: lines(adminGroups),
                member_groups: lines(memberGroups),
                create_users: createUsers,
                enabled,
            };
            if (record === null) input.slug = slug;
            if (clientSecret !== '') input.client_secret = clientSecret;

            return saveOidcProvider(record?.id ?? null, input);
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: oidcProvidersQueryOptions().queryKey });
            toast.success('Provider saved.');
            onDone();
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        save.mutate();
    };

    return (
        <form onSubmit={submit} className={'sso:flex sso:flex-col sso:gap-4 sso:p-4'}>
            <Alert type={'info'}>
                Set this redirect URL (callback) in your identity provider:{' '}
                <code className={'sso:break-all sso:font-mono'}>{`${callbackBase}/oidc-${slug || '<identifier>'}/callback`}</code>
            </Alert>
            {record === null && (
                <label className={'sso:flex sso:flex-col sso:gap-1 sso:text-sm'}>
                    Identifier
                    <input className={inputClass} value={slug} onChange={(e) => setSlug(e.target.value)} required />
                    <span className={'sso:text-muted-foreground'}>
                        Lowercase letters, numbers and dashes. Becomes oidc-{slug || '…'} and cannot be changed.
                    </span>
                </label>
            )}
            <label className={'sso:flex sso:flex-col sso:gap-1 sso:text-sm'}>
                Label
                <input className={inputClass} value={label} onChange={(e) => setLabel(e.target.value)} required />
                <span className={'sso:text-muted-foreground'}>Shown on the sign-in button and in Connections.</span>
            </label>
            <label className={'sso:flex sso:flex-col sso:gap-1 sso:text-sm'}>
                Issuer URL
                <input
                    className={inputClass}
                    type={'url'}
                    value={issuer}
                    onChange={(e) => setIssuer(e.target.value)}
                    placeholder={'https://id.example.com/realms/main'}
                    required
                />
                <span className={'sso:text-muted-foreground'}>
                    Without /.well-known/openid-configuration; it is added automatically.
                </span>
            </label>
            <label className={'sso:flex sso:flex-col sso:gap-1 sso:text-sm'}>
                Client ID
                <input className={inputClass} value={clientId} onChange={(e) => setClientId(e.target.value)} required />
            </label>
            <label className={'sso:flex sso:flex-col sso:gap-1 sso:text-sm'}>
                Client secret
                <input
                    className={inputClass}
                    type={'password'}
                    autoComplete={'new-password'}
                    value={clientSecret}
                    onChange={(e) => setClientSecret(e.target.value)}
                    placeholder={record?.has_secret ? 'Leave empty to keep the current secret' : ''}
                    required={record === null}
                />
            </label>
            <label className={'sso:flex sso:flex-col sso:gap-1 sso:text-sm'}>
                Scopes
                <input className={inputClass} value={scopes} onChange={(e) => setScopes(e.target.value)} />
                <span className={'sso:text-muted-foreground'}>
                    Space separated. Add the scope your provider needs to return groups, if any.
                </span>
            </label>
            <label className={'sso:flex sso:flex-col sso:gap-1 sso:text-sm'}>
                Groups claim
                <input className={inputClass} value={groupsClaim} onChange={(e) => setGroupsClaim(e.target.value)} />
                <span className={'sso:text-muted-foreground'}>
                    Claim of the userinfo response that lists groups. Use dots for nested claims.
                </span>
            </label>
            <label className={'sso:flex sso:flex-col sso:gap-1 sso:text-sm'}>
                Administrator groups
                <textarea
                    className={inputClass}
                    rows={3}
                    value={adminGroups}
                    onChange={(e) => setAdminGroups(e.target.value)}
                />
                <span className={'sso:text-muted-foreground'}>One per line. Members become root administrators.</span>
            </label>
            <label className={'sso:flex sso:flex-col sso:gap-1 sso:text-sm'}>
                Member groups
                <textarea
                    className={inputClass}
                    rows={3}
                    value={memberGroups}
                    onChange={(e) => setMemberGroups(e.target.value)}
                />
                <span className={'sso:text-muted-foreground'}>
                    One per line. Members are regular users. When any group is listed above or here, users in none of
                    them are refused; an empty member list lets everyone else in as a regular user. Roles are applied at
                    every sign-in.
                </span>
            </label>
            <label className={'sso:flex sso:items-center sso:gap-2 sso:text-sm'}>
                <input type={'checkbox'} checked={createUsers} onChange={(e) => setCreateUsers(e.target.checked)} />
                Create a panel account for unknown users (an email is required)
            </label>
            <label className={'sso:flex sso:items-center sso:gap-2 sso:text-sm'}>
                <input type={'checkbox'} checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
                Enabled
            </label>
            <div className={'sso:flex sso:gap-2'}>
                <Button type={'submit'} disabled={save.isPending}>
                    Save
                </Button>
                <Button type={'button'} isSecondary onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/**
 * @returns {JSX.Element}
 */
export default function ProvidersScreen() {
    const queryClient = useQueryClient();
    const providers = useQuery(oidcProvidersQueryOptions());
    const [editing, setEditing] = useState<OidcProviderRecord | 'new' | null>(null);
    const remove = useMutation({
        mutationFn: (id: number) => deleteOidcProvider(id),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: oidcProvidersQueryOptions().queryKey });
            toast.success('Provider deleted.');
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    if (providers.error) {
        return <ServerError message={httpErrorToHuman(providers.error)} onRetry={() => providers.refetch()} />;
    }

    return (
        <PageContentBlock title={'OpenID Connect'}>
            <div className={'sso:flex sso:flex-col sso:gap-4'}>
                {editing !== null ? (
                    <ContentBox title={editing === 'new' ? 'New provider' : `Edit ${editing.label}`}>
                        <ProviderForm
                            record={editing === 'new' ? null : editing}
                            callbackBase={providers.data?.callbackBase ?? ''}
                            onDone={() => setEditing(null)}
                        />
                    </ContentBox>
                ) : (
                    <ContentBox title={'OpenID Connect providers'}>
                        {providers.isLoading ? (
                            <div className={'sso:flex sso:justify-center sso:p-4'}>
                                <Spinner size={'small'} />
                            </div>
                        ) : (
                            <>
                                {(providers.data?.providers ?? []).length === 0 && (
                                    <Alert type={'info'}>No OpenID Connect provider has been added yet.</Alert>
                                )}
                                <ul className={'sso:divide-y sso:divide-border'}>
                                    {(providers.data?.providers ?? []).map((record) => (
                                        <li key={record.id} className={'sso:flex sso:items-center sso:gap-4 sso:px-4 sso:py-3'}>
                                            <div className={'sso:min-w-0 sso:flex-1'}>
                                                <p className={'sso:font-medium sso:text-foreground'}>
                                                    {record.label}
                                                    {!record.enabled && ' (disabled)'}
                                                </p>
                                                <p className={'sso:truncate sso:text-sm sso:text-muted-foreground'}>
                                                    Redirect URL: {record.callback_url}
                                                </p>
                                            </div>
                                            <Button size={'small'} isSecondary onClick={() => setEditing(record)}>
                                                Edit
                                            </Button>
                                            <Button
                                                size={'small'}
                                                color={'red'}
                                                isSecondary
                                                disabled={remove.isPending}
                                                onClick={() => remove.mutate(record.id)}
                                            >
                                                Delete
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                                <div className={'sso:p-4'}>
                                    <Button onClick={() => setEditing('new')}>Add provider</Button>
                                </div>
                            </>
                        )}
                    </ContentBox>
                )}
            </div>
        </PageContentBlock>
    );
}
