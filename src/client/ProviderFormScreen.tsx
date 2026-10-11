import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Alert,
    Button,
    ContentBox,
    PageContentBlock,
    PanelLink,
    ServerError,
    Spinner,
    httpErrorToHuman,
    toast,
    usePanelNavigate,
    type ScreenComponentProps,
} from '@pterodactyl/sdk';
import {
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

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        save.mutate();
    };

    return (
        <form onSubmit={submit} className={'sso:flex sso:flex-col sso:gap-4 sso:p-4'}>
            <Alert type={'info'}>
                Set this redirect URL (callback) in your identity provider:{' '}
                <code className={'sso:break-all sso:font-mono'}>{`${callbackBase}/oidc-${slug || '<identifier>'}/callback`}</code>
            </Alert>
            <label className={'sso:flex sso:flex-col sso:gap-1 sso:text-sm'}>
                Identifier
                <input
                    className={`${inputClass} sso:disabled:opacity-60`}
                    value={slug}
                    onChange={(e) => setSlug(e.target.value)}
                    disabled={record !== null}
                    required
                />
                <span className={'sso:text-muted-foreground'}>
                    {record === null
                        ? `Lowercase letters, numbers and dashes. Becomes oidc-${slug || '…'} and cannot be changed afterwards.`
                        : 'Fixed once created: linked accounts and the redirect URL depend on it.'}
                </span>
            </label>
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
 * Serves both the new and the edit routes: the edit route carries the record id.
 *
 * @param {ScreenComponentProps} props
 * @returns {JSX.Element}
 */
export default function ProviderFormScreen({ data }: ScreenComponentProps) {
    const navigate = usePanelNavigate();
    const providers = useQuery(oidcProvidersQueryOptions());
    const id = data.params?.record;
    const goBack = () => void navigate({ extension: 'sso', screen: 'providers' });

    if (providers.error) {
        return <ServerError message={httpErrorToHuman(providers.error)} onRetry={() => providers.refetch()} />;
    }

    if (providers.isLoading || !providers.data) {
        return (
            <PageContentBlock title={'OpenID Connect'}>
                <div className={'sso:flex sso:justify-center sso:p-4'}>
                    <Spinner size={'small'} />
                </div>
            </PageContentBlock>
        );
    }

    const record = id === undefined ? null : (providers.data.providers.find((item) => String(item.id) === id) ?? null);

    if (id !== undefined && record === null) {
        return (
            <PageContentBlock title={'OpenID Connect'}>
                <Alert type={'danger'}>That OpenID Connect provider does not exist.</Alert>
                <PanelLink destination={{ extension: 'sso', screen: 'providers' }}>Back to the list</PanelLink>
            </PageContentBlock>
        );
    }

    return (
        <PageContentBlock title={'OpenID Connect'}>
            <ContentBox title={record === null ? 'New provider' : `Edit ${record.label}`}>
                <ProviderForm
                    key={record?.id ?? 'new'}
                    record={record}
                    callbackBase={providers.data.callbackBase}
                    onDone={goBack}
                />
            </ContentBox>
        </PageContentBlock>
    );
}
