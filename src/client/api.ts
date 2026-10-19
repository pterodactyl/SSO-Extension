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

export interface SsoAccountProvider {
    id: string;
    name: string;
    field: string;
    pattern: string;
    hint: string;
    lookup: boolean;
}

export interface SsoAccount {
    id: string;
    name: string;
    avatar_url: string | null;
}

export interface SsoSetup {
    pending: boolean;
    username: string;
    email: string;
    name_first: string;
    name_last: string;
}

export interface SsoSetupValues {
    username: string;
    email: string;
    name_first: string;
    name_last: string;
    password: string;
    password_confirmation: string;
}

export const SETUP_PATH = '/account/setup';

export const SETUP_REQUIRED = 'SsoSetupRequired';

export const SSO_ERRORS: Record<string, string> = {
    state: 'Your sign-in attempt expired. Please try again.',
    denied: 'Sign-in was cancelled.',
    unavailable: 'That sign-in method is not available.',
    provider: 'The sign-in provider could not be reached. Please try again.',
    unlinked:
        'No panel account is connected to that login. Sign in with your password, then connect it from Account → Connections.',
    taken: 'That account is already connected to another panel user.',
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

export const accountProvidersQueryOptions = () =>
    queryOptions({
        queryKey: ['extensions', 'sso', 'accounts'],
        queryFn: async () =>
            (await http.get<{ data: SsoAccountProvider[] }>('/api/admin/extensions/sso/accounts')).data.data,
        staleTime: 60_000,
    });

export const accountQueryOptions = (provider: string, id: string) =>
    queryOptions({
        queryKey: ['extensions', 'sso', 'accounts', provider, id],
        queryFn: async () => {
            try {
                return (
                    await http.get<{ data: SsoAccount }>(
                        `/api/admin/extensions/sso/accounts/${encodeURIComponent(provider)}/${encodeURIComponent(id)}`
                    )
                ).data.data;
            } catch (error) {
                if (responseStatus(error) === 404) return null;
                throw error;
            }
        },
        staleTime: 300_000,
        retry: false,
    });

export const setupQueryOptions = () =>
    queryOptions({
        queryKey: ['extensions', 'sso', 'setup'],
        queryFn: async () => (await http.get<{ data: SsoSetup }>('/api/client/extensions/sso/setup')).data.data,
        retry: false,
    });

/**
 * @param {SsoSetupValues} values
 * @returns {Promise<void>}
 */
export async function completeSetup(values: SsoSetupValues): Promise<void> {
    await http.post('/api/client/extensions/sso/setup', values);
}

/**
 * @param {unknown} error
 * @returns {number | undefined}
 */
export function responseStatus(error: unknown): number | undefined {
    if (typeof error !== 'object' || error === null || !('response' in error)) return undefined;
    const response = (error as { response?: { status?: unknown } }).response;
    return typeof response?.status === 'number' ? response.status : undefined;
}

/**
 * Whether a request was refused because the account still has to finish its setup.
 *
 * @param {unknown} error
 * @returns {boolean}
 */
export function isSetupRequired(error: unknown): boolean {
    if (responseStatus(error) !== 409) return false;
    const data = (error as { response: { data?: unknown } }).response.data;
    const errors = typeof data === 'object' && data !== null ? (data as { errors?: unknown }).errors : undefined;
    return Array.isArray(errors) && errors.some((entry) => entry?.code === SETUP_REQUIRED);
}

/**
 * @param {string} pattern
 * @param {string} value
 * @returns {boolean}
 */
export function matchesPattern(pattern: string, value: string): boolean {
    try {
        return new RegExp(pattern).test(value);
    } catch {
        return false;
    }
}

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
