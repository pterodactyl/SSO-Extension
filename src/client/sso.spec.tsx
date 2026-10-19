import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import {
    Form,
    http,
    toast,
    useAppForm,
    type AdminUserFormValues,
    type ExtensionField,
    type ExtensionSetupContext,
    type RouteSlotData,
} from '@pterodactyl/sdk';
import { createExtensionTestHost, type ExtensionTestHost } from '@pterodactyl/sdk/testing';
import extension, { redirectToSetup } from './index';
import AccountFields, { type AccountFieldValues } from './AccountFields';
import LoginButtons from './LoginButtons';
import ConnectionsScreen from './ConnectionsScreen';
import SetupScreen from './SetupScreen';
import type { SsoAccountProvider, SsoConnection } from './api';
import { brandOf } from './brand';

let host: ExtensionTestHost | undefined;
beforeEach(() => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
});
afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.restoreAllMocks();
});

/**
 * @param {ReactNode} children
 * @param {string} path
 * @returns {ReturnType<typeof render>}
 */
function renderInHost(children: ReactNode, path: string) {
    host = createExtensionTestHost({ extensionId: 'sso', path, prefix: 'sso' });
    return render(children, { wrapper: host.Wrapper });
}

/**
 * @param {Record<string, unknown>} responses
 */
function respond(responses: Record<string, unknown>) {
    vi.spyOn(http, 'get').mockImplementation(async (url: string) => {
        if (!(url in responses)) throw new Error(`Unexpected request to ${url}`);
        return { data: { data: responses[url] } };
    });
}

/**
 * @param {Record<string, string>} search
 * @returns {RouteSlotData}
 */
function route(search: Record<string, string> = {}): RouteSlotData {
    return { pathname: '/auth/login', search, params: {} };
}

const discord: SsoConnection = {
    id: 'discord',
    name: 'Discord',
    enabled: true,
    link_url: '/extensions/sso/discord/link',
    account: null,
};

it('adds sign-in buttons to the login form and a connections page to the account area', () => {
    const context: ExtensionSetupContext = {
        meta: { id: 'sso' },
        config: {},
        views: { override: vi.fn() },
        slots: { register: vi.fn() },
        screens: { register: vi.fn() },
        columns: { register: vi.fn() },
        forms: { extend: vi.fn() },
    };
    const interceptors = vi.spyOn(http.interceptors.response, 'use').mockReturnValue(0);

    extension.setup(context);

    expect(context.slots.register).toHaveBeenCalledWith('auth.login.form.after', LoginButtons);
    expect(context.screens.register).toHaveBeenCalledWith('connections', expect.any(Function));
    expect(context.screens.register).toHaveBeenCalledWith('setup', expect.any(Function));
    expect(context.forms.extend).toHaveBeenCalledWith('admin.user', AccountFields);
    expect(interceptors).toHaveBeenCalledWith(undefined, redirectToSetup);
});

it('styles each provider from the brand in its own directory and others with a neutral button', () => {
    expect(brandOf('discord').id).toBe('discord');
    expect(brandOf('discord').className).toContain('sso:bg-[#5865F2]');
    expect(brandOf('unknown').id).toBe('');
});

it('offers every enabled provider as a link that starts its sign-in', async () => {
    respond({
        '/extensions/sso/providers': [{ id: 'discord', name: 'Discord', login_url: '/extensions/sso/discord/redirect' }],
    });
    renderInHost(<LoginButtons data={route()} />, '/auth/login');

    expect((await screen.findByRole('link', { name: 'Continue with Discord' })).getAttribute('href')).toBe(
        '/extensions/sso/discord/redirect'
    );
});

it('renders nothing on the login form when no provider is enabled', async () => {
    respond({ '/extensions/sso/providers': [] });
    const { container } = renderInHost(<LoginButtons data={route()} />, '/auth/login');

    await waitFor(() => expect(http.get).toHaveBeenCalled());
    expect(container.textContent).toBe('');
});

it('explains why a sign-in was sent back to the login page', async () => {
    respond({ '/extensions/sso/providers': [] });
    renderInHost(<LoginButtons data={route({ sso_error: 'unlinked' })} />, '/auth/login?sso_error=unlinked');

    expect(await screen.findByText(/No panel account is connected to that login/)).toBeTruthy();
});

it('continues a two factor sign-in on the native checkpoint screen', async () => {
    respond({
        '/extensions/sso/providers': [],
        '/extensions/sso/checkpoint': { confirmation_token: 'checkpoint-token' },
    });
    renderInHost(<LoginButtons data={route({ sso: 'checkpoint' })} />, '/auth/login?sso=checkpoint');

    await waitFor(() => expect(host!.location().pathname).toBe('/auth/login/checkpoint'));
    expect(host!.location().state).toMatchObject({ token: 'checkpoint-token' });
});

it('lists connections and lets the user connect or disconnect them', async () => {
    respond({
        '/api/client/extensions/sso/connections': [
            {
                ...discord,
                account: { name: 'Nelly', email: 'nelly@discord.test', avatar_url: null, linked_at: null },
            },
        ],
    });
    const remove = vi.spyOn(http, 'delete').mockResolvedValue({ data: '' });
    const success = vi.spyOn(toast, 'success');
    renderInHost(
        <ConnectionsScreen data={{ pathname: '/account/connections', search: { sso_linked: 'discord' }, params: {} }} />,
        '/account/connections'
    );

    expect(await screen.findByText('Nelly · nelly@discord.test')).toBeTruthy();
    expect(screen.getByText('Your Discord account is now connected.')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Disconnect' }));

    await waitFor(() => expect(success).toHaveBeenCalledWith('Discord has been disconnected.'));
    expect(remove).toHaveBeenCalledWith('/api/client/extensions/sso/connections/discord');
});

it('links to the provider for accounts that are not connected yet', async () => {
    respond({ '/api/client/extensions/sso/connections': [discord] });
    renderInHost(
        <ConnectionsScreen data={{ pathname: '/account/connections', search: {}, params: {} }} />,
        '/account/connections'
    );

    expect((await screen.findByRole('link', { name: 'Connect' })).getAttribute('href')).toBe(
        '/extensions/sso/discord/link'
    );
    expect(screen.getByText('Not connected')).toBeTruthy();
});

const discordAccounts: SsoAccountProvider = {
    id: 'discord',
    name: 'Discord',
    field: 'discord_id',
    pattern: '^[0-9]{17,20}$',
    hint: 'A Discord user ID is 17 to 20 digits.',
    lookup: true,
};

/**
 * @param {Record<string, unknown>} responses
 * @param {Record<string, number>} failures
 */
function respondOrFail(responses: Record<string, unknown>, failures: Record<string, number> = {}) {
    vi.spyOn(http, 'get').mockImplementation(async (url: string) => {
        if (url in failures) {
            throw Object.assign(new Error('Request failed'), {
                isAxiosError: true,
                response: { status: failures[url], data: { errors: [{ detail: 'Request failed' }] } },
            });
        }
        if (!(url in responses)) throw new Error(`Unexpected request to ${url}`);
        return { data: { data: responses[url] } };
    });
}

/**
 * The admin create form, reduced to the fields these tests look at.
 *
 * @param {{ initial?: Partial<AdminUserFormValues>; onValues: (values: AdminUserFormValues) => void }} props
 * @returns {JSX.Element}
 */
function CreateUserForm({
    initial = {},
    onValues,
}: {
    initial?: Partial<AdminUserFormValues>;
    onValues: (values: AdminUserFormValues) => void;
}) {
    const form = useAppForm({
        defaultValues: {
            email: '',
            username: '',
            nameFirst: '',
            nameLast: '',
            password: '',
            rootAdmin: false,
            language: 'en',
            extensions: {},
            ...initial,
        } as AdminUserFormValues,
    });

    return (
        <Form form={form}>
            <form.Subscribe selector={(state) => state.values}>
                {(values) => {
                    onValues(values);
                    const sso = (values.extensions.sso ?? {}) as AccountFieldValues;
                    const field = (name: string): ExtensionField<string | boolean | null> => ({
                        id: `sso-${name}`,
                        name: `extensions.sso.${name}`,
                        value: sso[name],
                        error: undefined,
                        setValue: (value) =>
                            form.setFieldValue('extensions', (current) => ({
                                ...current,
                                sso: { ...current.sso, [name]: value },
                            })),
                    });

                    return (
                        <form.AppField name={'extensions'}>
                            {() => (
                                <AccountFields form={'admin.user'} mode={'create'} values={sso} field={field} />
                            )}
                        </form.AppField>
                    );
                }}
            </form.Subscribe>
        </Form>
    );
}

it('adds an id field for each provider to the admin user form and shows whose account it is', async () => {
    respondOrFail(
        {
            '/api/admin/extensions/sso/accounts': [discordAccounts],
            '/api/admin/extensions/sso/accounts/discord/80351110224678912': {
                id: '80351110224678912',
                name: 'Nelly',
                avatar_url: null,
            },
        },
        { '/api/admin/extensions/sso/accounts/discord/123456789012345678': 404 }
    );
    const values: AccountFieldValues = { discord_id: null, setup_on_first_login: false };
    const field = (name: string): ExtensionField<string | boolean | null> => ({
        id: `sso-${name}`,
        name: `extensions.sso.${name}`,
        value: values[name],
        error: name === 'discord_id' && values[name] === '80351110224678913' ? 'Already linked.' : undefined,
        setValue: (value) => {
            values[name] = value;
        },
    });
    const view = renderInHost(
        <AccountFields form={'admin.user'} mode={'edit'} values={values} field={field} />,
        '/panel/users/1'
    );

    const input = await screen.findByLabelText('Discord ID');
    expect(screen.getByText('A Discord user ID is 17 to 20 digits.')).toBeTruthy();

    fireEvent.change(input, { target: { value: ' 80351110224678912 ' } });
    expect(values.discord_id).toBe('80351110224678912');

    values.discord_id = 'nelly';
    view.rerender(<AccountFields form={'admin.user'} mode={'edit'} values={values} field={field} />);
    expect(screen.getByText('That is not a valid Discord ID.')).toBeTruthy();

    values.discord_id = '80351110224678912';
    view.rerender(<AccountFields form={'admin.user'} mode={'edit'} values={values} field={field} />);
    expect(await screen.findByText('Nelly')).toBeTruthy();

    values.discord_id = '123456789012345678';
    view.rerender(<AccountFields form={'admin.user'} mode={'edit'} values={values} field={field} />);
    expect(await screen.findByText('No Discord account has this ID.')).toBeTruthy();

    values.discord_id = '80351110224678913';
    view.rerender(<AccountFields form={'admin.user'} mode={'edit'} values={values} field={field} />);
    expect(screen.getByText('Already linked.')).toBeTruthy();
});

it('fills the required details with placeholders while the user sets up on first sign-in', async () => {
    respondOrFail({ '/api/admin/extensions/sso/accounts': [{ ...discordAccounts, lookup: false }] });
    let values = {} as AdminUserFormValues;
    renderInHost(
        <CreateUserForm initial={{ nameFirst: 'Nelly' }} onValues={(next) => (values = next)} />,
        '/panel/users/new'
    );

    fireEvent.change(await screen.findByLabelText('Discord ID'), { target: { value: '80351110224678912' } });
    fireEvent.click(screen.getByRole('switch', { name: 'Set up on first sign-in' }));

    await waitFor(() => expect(values.email).toBe('80351110224678912@discord.invalid'));
    expect(values).toMatchObject({ username: 'discord-80351110224678912', nameFirst: 'Nelly', nameLast: 'User' });
    expect(values.extensions).toEqual({ sso: { discord_id: '80351110224678912', setup_on_first_login: true } });
    expect(screen.getByText(/Nothing is emailed to a placeholder address/)).toBeTruthy();

    fireEvent.click(screen.getByRole('switch', { name: 'Set up on first sign-in' }));

    await waitFor(() => expect(values.email).toBe(''));
    expect(values).toMatchObject({ username: '', nameFirst: 'Nelly', nameLast: '' });
});

it('asks an account an admin created to finish its setup', async () => {
    respondOrFail({
        '/api/client/extensions/sso/setup': {
            pending: true,
            username: '',
            email: 'nelly@discord.test',
            name_first: '',
            name_last: '',
        },
    });
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: '' });
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, pathname: '/account/setup', assign });
    renderInHost(<SetupScreen data={{ pathname: '/account/setup', search: {}, params: {} }} />, '/account/setup');

    expect(((await screen.findByLabelText('Email address')) as HTMLInputElement).value).toBe('nelly@discord.test');
    fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'nelly' } });
    fireEvent.change(screen.getByLabelText('First name'), { target: { value: 'Nelly' } });
    fireEvent.change(screen.getByLabelText('Last name'), { target: { value: 'Example' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'correct horse' } });
    fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'something else' } });
    expect(await screen.findByText('The passwords do not match.')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'correct horse' } });
    await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Finish setup' }));
    });

    await waitFor(() => expect(assign).toHaveBeenCalledWith('/'));
    expect(post).toHaveBeenCalledWith('/api/client/extensions/sso/setup', {
        username: 'nelly',
        email: 'nelly@discord.test',
        name_first: 'Nelly',
        name_last: 'Example',
        password: 'correct horse',
        password_confirmation: 'correct horse',
    });
    vi.unstubAllGlobals();
});

it('shows why the panel refused the setup', async () => {
    respondOrFail({
        '/api/client/extensions/sso/setup': {
            pending: true,
            username: 'nelly',
            email: 'taken@example.test',
            name_first: 'Nelly',
            name_last: 'Example',
        },
    });
    vi.spyOn(http, 'post').mockRejectedValue(
        Object.assign(new Error('Request failed'), {
            isAxiosError: true,
            response: { status: 422, data: { errors: [{ detail: 'The email has already been taken.' }] } },
        })
    );
    renderInHost(<SetupScreen data={{ pathname: '/account/setup', search: {}, params: {} }} />, '/account/setup');

    await screen.findByLabelText('Email address');
    await act(async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Finish setup' }));
    });

    expect(await screen.findByText('The email has already been taken.')).toBeTruthy();
});

it('tells an account that is already set up there is nothing to do', async () => {
    respondOrFail({
        '/api/client/extensions/sso/setup': { pending: false, username: 'nelly', email: 'a@b.test', name_first: 'N', name_last: 'E' },
    });
    renderInHost(<SetupScreen data={{ pathname: '/account/setup', search: {}, params: {} }} />, '/account/setup');

    expect(await screen.findByText(/Your account is set up/)).toBeTruthy();
    expect(screen.queryByLabelText('Username')).toBeNull();
});

it('sends an account that has to finish its setup to the setup screen', async () => {
    const assign = vi.fn();
    vi.stubGlobal('location', { ...window.location, pathname: '/server/abc', assign });
    const refused = { response: { status: 409, data: { errors: [{ code: 'SsoSetupRequired' }] } } };
    const other = { response: { status: 409, data: { errors: [{ code: 'Conflict' }] } } };

    await expect(redirectToSetup(other)).rejects.toBe(other);
    expect(assign).not.toHaveBeenCalled();
    await expect(redirectToSetup(refused)).rejects.toBe(refused);
    expect(assign).toHaveBeenCalledWith('/account/setup');

    vi.stubGlobal('location', { ...window.location, pathname: '/account/setup', assign: vi.fn() });
    await expect(redirectToSetup(refused)).rejects.toBe(refused);
    expect(window.location.assign).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
});

it('explains why the last way into an account cannot be disconnected', async () => {
    respond({
        '/api/client/extensions/sso/connections': [
            { ...discord, account: { name: 'Nelly', email: null, avatar_url: null, linked_at: null } },
        ],
    });
    vi.spyOn(http, 'delete').mockRejectedValue(
        Object.assign(new Error('Request failed'), {
            isAxiosError: true,
            response: { status: 409, data: { errors: [{ detail: 'This is the only way to sign in to your account.' }] } },
        })
    );
    const error = vi.spyOn(toast, 'error');
    renderInHost(
        <ConnectionsScreen data={{ pathname: '/account/connections', search: {}, params: {} }} />,
        '/account/connections'
    );

    fireEvent.click(await screen.findByRole('button', { name: 'Disconnect' }));

    await waitFor(() => expect(error).toHaveBeenCalledWith('This is the only way to sign in to your account.'));
});
