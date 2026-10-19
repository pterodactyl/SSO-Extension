import './styles.css';
import { definePterodactylExtension, http } from '@pterodactyl/sdk';
import AccountFields from './AccountFields';
import LoginButtons from './LoginButtons';
import { SETUP_PATH, isSetupRequired } from './api';

/**
 * Sends an account that still has to finish its setup to the setup screen when the panel
 * refuses one of its requests.
 *
 * @param {unknown} error
 * @returns {Promise<never>}
 */
export function redirectToSetup(error: unknown): Promise<never> {
    if (isSetupRequired(error) && window.location.pathname !== SETUP_PATH) {
        window.location.assign(SETUP_PATH);
    }

    return Promise.reject(error);
}

export default definePterodactylExtension({
    setup({ slots, screens, forms }) {
        slots.register('auth.login.form.after', LoginButtons);
        screens.register('connections', () => import('./ConnectionsScreen'));
        screens.register('setup', () => import('./SetupScreen'));
        forms.extend('admin.user', AccountFields);
        http.interceptors.response.use(undefined, redirectToSetup);
    },
});
