import './styles.css';
import { definePterodactylExtension, type ScreenContext } from '@pterodactyl/sdk';
import LoginButtons from './LoginButtons';

export default definePterodactylExtension({
    setup({ slots, screens }) {
        slots.register('auth.login.form.after', LoginButtons);
        screens.register('connections', () => import('./ConnectionsScreen'));
        const oidcEnabled = { visible: ({ config }: ScreenContext) => config.oidc_enabled === true };
        screens.register('providers', () => import('./ProvidersScreen'), oidcEnabled);
        screens.register('provider-new', () => import('./ProviderFormScreen'), oidcEnabled);
        screens.register('provider-edit', () => import('./ProviderFormScreen'), oidcEnabled);
    },
});
