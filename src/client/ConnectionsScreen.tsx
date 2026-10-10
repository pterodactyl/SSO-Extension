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
    useExtensionAction,
    type ScreenComponentProps,
} from '@pterodactyl/sdk';
import { brandOf } from './brand';
import { SSO_ERRORS, connectionsQueryOptions, disconnect, searchParam, type SsoConnection } from './api';

/**
 * @param {{ connection: SsoConnection }} props
 * @returns {JSX.Element}
 */
function ConnectionRow({ connection }: { connection: SsoConnection }) {
    const queryClient = useQueryClient();
    const brand = brandOf(connection.id);
    const remove = useMutation({
        mutationFn: () => disconnect(connection.id),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: connectionsQueryOptions().queryKey }),
    });
    const onDisconnect = useExtensionAction('disconnect', async () => {
        try {
            await remove.mutateAsync();
            toast.success(`${connection.name} has been disconnected.`);
        } catch (error) {
            toast.error(httpErrorToHuman(error));
        }
    });

    return (
        <li className={'sso:flex sso:items-center sso:gap-4 sso:px-4 sso:py-3'}>
            <span
                className={`sso:flex sso:size-10 sso:shrink-0 sso:items-center sso:justify-center sso:overflow-hidden sso:rounded-full sso:border ${brand.className}`}
            >
                {connection.account?.avatar_url ? (
                    <img
                        alt={''}
                        className={'sso:size-full sso:rounded-full sso:object-cover'}
                        referrerPolicy={'no-referrer'}
                        src={connection.account.avatar_url}
                    />
                ) : (
                    brand.icon
                )}
            </span>
            <div className={'sso:min-w-0 sso:flex-1'}>
                <p className={'sso:font-medium sso:text-foreground'}>{connection.name}</p>
                <p className={'sso:truncate sso:text-sm sso:text-muted-foreground'}>
                    {connection.account
                        ? [connection.account.name, connection.account.email].filter(Boolean).join(' · ') ||
                          'Connected'
                        : 'Not connected'}
                </p>
            </div>
            {connection.account ? (
                <Button size={'small'} color={'red'} isSecondary disabled={remove.isPending} onClick={onDisconnect}>
                    Disconnect
                </Button>
            ) : (
                connection.enabled && (
                    <a
                        href={connection.link_url}
                        className={
                            'sso:rounded-sm sso:border sso:border-primary sso:bg-primary sso:px-4 sso:py-2 sso:text-sm sso:uppercase sso:tracking-wide sso:text-primary-foreground sso:no-underline sso:hover:bg-primary/90'
                        }
                    >
                        Connect
                    </a>
                )
            )}
        </li>
    );
}

/**
 * @param {ScreenComponentProps} props
 * @returns {JSX.Element}
 */
export default function ConnectionsScreen({ data }: ScreenComponentProps) {
    const connections = useQuery(connectionsQueryOptions());
    const linked = searchParam(data.search, 'sso_linked');
    const error = searchParam(data.search, 'sso_error');
    const linkedName = connections.data?.find((connection) => connection.id === linked)?.name ?? linked;

    if (connections.error) {
        return <ServerError message={httpErrorToHuman(connections.error)} onRetry={() => connections.refetch()} />;
    }

    return (
        <PageContentBlock title={'Connections'}>
            <div className={'sso:flex sso:flex-col sso:gap-4'}>
                {linked && <Alert type={'success'}>Your {linkedName} account is now connected.</Alert>}
                {error && <Alert type={'danger'}>{SSO_ERRORS[error] ?? SSO_ERRORS.provider}</Alert>}
                <ContentBox title={'Connected Accounts'}>
                    <p className={'sso:px-4 sso:pb-2 sso:text-sm sso:text-muted-foreground'}>
                        Connect an external account to sign in to the panel without your password.
                    </p>
                    {!connections.data ? (
                        <div className={'sso:flex sso:justify-center sso:p-6'}>
                            <Spinner size={'small'} />
                        </div>
                    ) : connections.data.length === 0 ? (
                        <p className={'sso:px-4 sso:py-3 sso:text-sm sso:text-muted-foreground'}>
                            No sign-in providers are enabled on this panel.
                        </p>
                    ) : (
                        <ul className={'sso:divide-y sso:divide-border'}>
                            {connections.data.map((connection) => (
                                <ConnectionRow key={connection.id} connection={connection} />
                            ))}
                        </ul>
                    )}
                </ContentBox>
            </div>
        </PageContentBlock>
    );
}
