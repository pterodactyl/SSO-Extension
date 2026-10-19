import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    Alert,
    ContentBox,
    Form,
    PageContentBlock,
    ServerError,
    Spinner,
    httpErrorToHuman,
    useAppForm,
    useExtensionAction,
    type ScreenComponentProps,
} from '@pterodactyl/sdk';
import { completeSetup, setupQueryOptions, type SsoSetup, type SsoSetupValues } from './api';

/**
 * @param {string} message
 * @returns {(field: { value: string }) => string | undefined}
 */
const required =
    (message: string) =>
    ({ value }: { value: string }): string | undefined =>
        value.trim() === '' ? message : undefined;

/**
 * @param {{ value: string }} field
 * @returns {string | undefined}
 */
function validateEmail({ value }: { value: string }): string | undefined {
    if (value.trim() === '') return 'Enter your email address.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return 'Enter a valid email address.';
    if (/(^|\.)invalid\.?$/i.test(value.split('@').pop() ?? '')) return 'Enter an email address you can receive mail at.';
    return undefined;
}

/**
 * @param {{ value: string }} field
 * @returns {string | undefined}
 */
function validatePassword({ value }: { value: string }): string | undefined {
    return value !== '' && value.length < 8 ? 'Your password must be at least 8 characters.' : undefined;
}

/**
 * @param {{ setup: SsoSetup }} props
 * @returns {JSX.Element}
 */
function SetupForm({ setup }: { setup: SsoSetup }) {
    const [error, setError] = useState<string | null>(null);
    const submit = useExtensionAction('setup', async (values: SsoSetupValues) => {
        setError(null);
        try {
            await completeSetup(values);
            window.location.assign('/');
        } catch (cause) {
            setError(httpErrorToHuman(cause));
        }
    });
    const form = useAppForm({
        defaultValues: {
            username: setup.username,
            email: setup.email,
            name_first: setup.name_first,
            name_last: setup.name_last,
            password: '',
            password_confirmation: '',
        } satisfies SsoSetupValues,
        onSubmit: ({ value }) => submit(value),
    });

    return (
        <Form form={form}>
            <div className={'sso:flex sso:flex-col sso:gap-6'}>
                {error && <Alert type={'danger'}>{error}</Alert>}
                <form.AppField name={'username'} validators={{ onChange: required('Choose a username.') }}>
                    {(field) => (
                        <field.TextField
                            label={'Username'}
                            autoComplete={'username'}
                            description={'A unique name that identifies you on the panel.'}
                        />
                    )}
                </form.AppField>
                <form.AppField name={'email'} validators={{ onChange: validateEmail }}>
                    {(field) => (
                        <field.TextField
                            type={'email'}
                            label={'Email address'}
                            autoComplete={'email'}
                            description={'Used for notifications and to reset your password.'}
                        />
                    )}
                </form.AppField>
                <div className={'sso:grid sso:grid-cols-1 sso:gap-6 sso:sm:grid-cols-2'}>
                    <form.AppField name={'name_first'} validators={{ onChange: required('Enter your first name.') }}>
                        {(field) => <field.TextField label={'First name'} autoComplete={'given-name'} />}
                    </form.AppField>
                    <form.AppField name={'name_last'} validators={{ onChange: required('Enter your last name.') }}>
                        {(field) => <field.TextField label={'Last name'} autoComplete={'family-name'} />}
                    </form.AppField>
                </div>
                <form.AppField name={'password'} validators={{ onChange: validatePassword }}>
                    {(field) => (
                        <field.TextField
                            type={'password'}
                            label={'Password'}
                            autoComplete={'new-password'}
                            description={'Optional. Without one, you keep signing in with your connected account.'}
                        />
                    )}
                </form.AppField>
                <form.AppField
                    name={'password_confirmation'}
                    validators={{
                        onChangeListenTo: ['password'],
                        onChange: ({ value, fieldApi }) =>
                            value !== fieldApi.form.getFieldValue('password') ? 'The passwords do not match.' : undefined,
                    }}
                >
                    {(field) => (
                        <field.TextField type={'password'} label={'Confirm password'} autoComplete={'new-password'} />
                    )}
                </form.AppField>
                <div className={'sso:flex sso:justify-end'}>
                    <form.AppForm>
                        <form.SubmitButton>Finish setup</form.SubmitButton>
                    </form.AppForm>
                </div>
            </div>
        </Form>
    );
}

/**
 * The screen an account an admin created lands on at its first sign-in. The panel keeps the
 * rest of the account closed until it is done.
 *
 * @param {ScreenComponentProps} props
 * @returns {JSX.Element}
 */
export default function SetupScreen(_: ScreenComponentProps) {
    const setup = useQuery(setupQueryOptions());

    if (setup.error) {
        return <ServerError message={httpErrorToHuman(setup.error)} onRetry={() => setup.refetch()} />;
    }

    return (
        <PageContentBlock title={'Finish your account'}>
            <ContentBox title={'Finish your account'}>
                {!setup.data ? (
                    <div className={'sso:flex sso:justify-center sso:p-6'}>
                        <Spinner size={'small'} />
                    </div>
                ) : !setup.data.pending ? (
                    <Alert type={'success'}>Your account is set up. There is nothing left to do here.</Alert>
                ) : (
                    <div className={'sso:flex sso:flex-col sso:gap-6'}>
                        <p className={'sso:text-sm sso:text-muted-foreground'}>
                            An administrator created this account for you. Choose your username and email address
                            before you continue. You can also set a password to sign in without your connected
                            account.
                        </p>
                        <SetupForm setup={setup.data} />
                    </div>
                )}
            </ContentBox>
        </PageContentBlock>
    );
}
