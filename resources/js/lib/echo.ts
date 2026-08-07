import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echoInstance: Echo<'reverb'> | null = null;

const isLocalReverbHost = (host: string): boolean =>
    host === '127.0.0.1' || host === 'localhost' || host === '::1';

const resolveReverbClientConfig = (): { host: string; port: number; scheme: string } => {
    const configuredHost = (import.meta.env.VITE_REVERB_HOST as string | undefined)?.trim();
    const configuredPort = import.meta.env.VITE_REVERB_PORT as string | undefined;
    const configuredScheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined)?.trim();

    if (configuredHost && !isLocalReverbHost(configuredHost)) {
        return {
            host: configuredHost,
            port: Number(configuredPort || 8080),
            scheme: configuredScheme || 'http',
        };
    }

    const scheme = window.location.protocol === 'https:' ? 'https' : 'http';
    const port = scheme === 'https'
        ? 443
        : Number(window.location.port || 80);

    return {
        host: window.location.hostname,
        port,
        scheme,
    };
};

export const getEcho = (): Echo<'reverb'> | null => {
    if (echoInstance) {
        return echoInstance;
    }

    const appKey = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

    if (!appKey) {
        return null;
    }

    const { host, port, scheme } = resolveReverbClientConfig();

    (window as Window & { Pusher?: typeof Pusher }).Pusher = Pusher;

    echoInstance = new Echo({
        broadcaster: 'reverb',
        key: appKey,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return echoInstance;
};
