import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import LoginButtons from './LoginButtons';

export default definePterodactylExtension({
    setup({ slots, screens }) {
        slots.register('auth.login.form.after', LoginButtons);
        screens.register('connections', () => import('./ConnectionsScreen'));
        screens.register('providers', () => import('./ProvidersScreen'), {
            visible: ({ config }) => config.oidc_enabled === true,
        });
    },
});
