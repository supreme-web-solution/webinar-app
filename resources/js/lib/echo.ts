import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echoInstance: Echo<'reverb'> | null = null;

export const getEcho = (): Echo<'reverb'> | null => {
    if (echoInstance) {
        return echoInstance;
    }

    const appKey = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;
    const host = (import.meta.env.VITE_REVERB_HOST as string | undefined) || window.location.hostname;
    const port = Number(import.meta.env.VITE_REVERB_PORT || 8080);
    const scheme = (import.meta.env.VITE_REVERB_SCHEME as string | undefined) || 'http';

    if (!appKey) {
        return null;
    }

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
