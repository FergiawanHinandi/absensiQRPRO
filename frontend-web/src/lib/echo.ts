import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'> | null;
    }
}

window.Pusher = Pusher;

// Disable Echo temporarily - uncomment when Reverb server is ready
const echo = null;
/*
// Uncomment these imports when enabling Echo:
// import axios from 'axios';
// import { tokenStore } from './secureTokenStore';
const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ? parseInt(import.meta.env.VITE_REVERB_PORT) : 8080,
    wssPort: import.meta.env.VITE_REVERB_PORT ? parseInt(import.meta.env.VITE_REVERB_PORT) : 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }, _options: unknown) => {
        return {
            authorize: (socketId: string, callback: (error: any, data?: any) => void) => {
                const apiUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1';
                const baseOrigin = (() => {
                    try {
                        return new URL(apiUrl).origin;
                    } catch {
                        return 'http://localhost:8000';
                    }
                })();
                // Use secure memory-only token storage (not localStorage)
                const token = tokenStore.getToken();
                axios.post(`${baseOrigin}/api/broadcasting/auth`, {
                    socket_id: socketId,
                    channel_name: channel.name
                }, {
                    headers: {
                        Authorization: token ? `Bearer ${token}` : ''
                    }
                })
                    .then(response => {
                        callback(false, response.data);
                    })
                    .catch(error => {
                        callback(true, error);
                    });
            }
        };
    },
});
*/

window.Echo = echo as any;

export default echo as any;
