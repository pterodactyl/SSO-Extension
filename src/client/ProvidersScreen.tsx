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
} from '@pterodactyl/sdk';
import { deleteOidcProvider, oidcProvidersQueryOptions } from './api';

const linkClass =
    'sso:rounded-sm sso:border sso:border-primary sso:bg-primary sso:px-4 sso:py-2 sso:text-sm sso:uppercase sso:tracking-wide sso:text-primary-foreground sso:no-underline sso:hover:bg-primary/90';

const smallLinkClass =
    'sso:rounded-sm sso:border sso:border-border sso:px-3 sso:py-1 sso:text-xs sso:uppercase sso:tracking-wide sso:text-foreground sso:no-underline sso:hover:bg-accent/20';

/**
 * @returns {JSX.Element}
 */
export default function ProvidersScreen() {
    const queryClient = useQueryClient();
    const providers = useQuery(oidcProvidersQueryOptions());
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

    const records = providers.data?.providers ?? [];

    return (
        <PageContentBlock title={'OpenID Connect'}>
            <ContentBox title={'OpenID Connect providers'}>
                {providers.isLoading ? (
                    <div className={'sso:flex sso:justify-center sso:p-4'}>
                        <Spinner size={'small'} />
                    </div>
                ) : (
                    <>
                        {records.length === 0 && (
                            <Alert type={'info'}>No OpenID Connect provider has been added yet.</Alert>
                        )}
                        <ul className={'sso:divide-y sso:divide-border'}>
                            {records.map((record) => (
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
                                    <PanelLink
                                        className={linkClass}
                                        destination={{
                                            extension: 'sso',
                                            screen: 'provider-edit',
                                            params: { record: String(record.id) },
                                        }}
                                    >
                                        Edit
                                    </PanelLink>
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
                            <PanelLink className={linkClass} destination={{ extension: 'sso', screen: 'provider-new' }}>
                                Add provider
                            </PanelLink>
                        </div>
                    </>
                )}
            </ContentBox>
        </PageContentBlock>
    );
}
