import { http } from '@pterodactyl/sdk';
import { queryOptions } from '@tanstack/react-query';

export interface SsoProvider {
    id: string;
    name: string;
    login_url: string;
}

export interface SsoConnection {
    id: string;
    name: string;
    enabled: boolean;
    link_url: string;
    account: {
        name: string | null;
        email: string | null;
        avatar_url: string | null;
        linked_at: string | null;
    } | null;
}

export const SSO_ERRORS: Record<string, string> = {
    state: 'Your sign-in attempt expired. Please try again.',
    denied: 'Sign-in was cancelled.',
    unavailable: 'That sign-in method is not available.',
    provider: 'The sign-in provider could not be reached. Please try again.',
    unlinked:
        'No panel account is connected to that login. Sign in with your password, then connect it from Account → Connections.',
    taken: 'That account is already connected to another panel user.',
    forbidden: 'Your account is not allowed to sign in here.',
};

export const providersQueryOptions = () =>
    queryOptions({
        queryKey: ['extensions', 'sso', 'providers'],
        queryFn: async () => (await http.get<{ data: SsoProvider[] }>('/extensions/sso/providers')).data.data,
        staleTime: 60_000,
    });

export const checkpointQueryOptions = () =>
    queryOptions({
        queryKey: ['extensions', 'sso', 'checkpoint'],
        queryFn: async () =>
            (await http.get<{ data: { confirmation_token: string } }>('/extensions/sso/checkpoint')).data.data
                .confirmation_token,
        retry: false,
        gcTime: 0,
    });

export const connectionsQueryOptions = () =>
    queryOptions({
        queryKey: ['extensions', 'sso', 'connections'],
        queryFn: async () =>
            (await http.get<{ data: SsoConnection[] }>('/api/client/extensions/sso/connections')).data.data,
    });

/**
 * @param {string} provider
 * @returns {Promise<void>}
 */
export async function disconnect(provider: string): Promise<void> {
    await http.delete(`/api/client/extensions/sso/connections/${encodeURIComponent(provider)}`);
}

/**
 * @param {unknown} search
 * @param {string} key
 * @returns {string | undefined}
 */
export function searchParam(search: unknown, key: string): string | undefined {
    if (typeof search !== 'object' || search === null) return undefined;
    const value = (search as Record<string, unknown>)[key];
    return typeof value === 'string' && value !== '' ? value : undefined;
}

export interface OidcProviderRecord {
    id: number;
    slug: string;
    provider_id: string;
    label: string;
    issuer: string;
    client_id: string;
    has_secret: boolean;
    scopes: string;
    groups_claim: string;
    admin_groups: string[];
    member_groups: string[];
    create_users: boolean;
    enabled: boolean;
    callback_url: string;
}

export type OidcProviderInput = Omit<OidcProviderRecord, 'id' | 'provider_id' | 'has_secret' | 'callback_url' | 'slug'> & {
    slug?: string;
    client_secret?: string;
};

const ADMIN_PATH = '/api/admin/extensions/sso/oidc-providers';

export const oidcProvidersQueryOptions = () =>
    queryOptions({
        queryKey: ['extensions', 'sso', 'oidc-providers'],
        queryFn: async () => {
            const { data, meta } = (
                await http.get<{ data: OidcProviderRecord[]; meta: { callback_base: string } }>(ADMIN_PATH)
            ).data;

            return { providers: data, callbackBase: meta.callback_base };
        },
    });

/**
 * @param {number | null} id
 * @param {OidcProviderInput} input
 * @returns {Promise<void>}
 */
export async function saveOidcProvider(id: number | null, input: OidcProviderInput): Promise<void> {
    if (id === null) await http.post(ADMIN_PATH, input);
    else await http.put(`${ADMIN_PATH}/${id}`, input);
}

/**
 * @param {number} id
 * @returns {Promise<void>}
 */
export async function deleteOidcProvider(id: number): Promise<void> {
    await http.delete(`${ADMIN_PATH}/${id}`);
}
