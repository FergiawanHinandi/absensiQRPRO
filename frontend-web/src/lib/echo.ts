import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';
import { tokenStore } from './secureTokenStore';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'> | null;
    }
}

window.Pusher = Pusher;

/**
 * Laravel Echo instance configured for Laravel Reverb.
 *
 * Features:
 * - Reverb broadcaster with WebSocket transport
 * - Custom authorizer using secure in-memory token (not localStorage)
 * - Auto-fallback when Reverb server is unavailable
 */
const createEcho = (): Echo<'reverb'> | null => {
    const appKey = import.meta.env.VITE_REVERB_APP_KEY;
    const wsHost = import.meta.env.VITE_REVERB_HOST;
    const wsPort = import.meta.env.VITE_REVERB_PORT;

    // Don't initialize if config is missing (graceful fallback)
    if (!appKey || !wsHost) {
        if (import.meta.env.DEV) {
            console.warn('[Echo] Reverb not configured. Real-time features disabled.');
        }
        return null;
    }

    try {
        const apiUrl = import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1';
        const baseOrigin = (() => {
            try {
                return new URL(apiUrl).origin;
            } catch {
                return 'http://localhost:8000';
            }
        })();

        return new Echo({
            broadcaster: 'reverb',
            key: appKey,
            wsHost: wsHost,
            wsPort: wsPort ? parseInt(wsPort) : 8080,
            wssPort: wsPort ? parseInt(wsPort) : 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            authorizer: (channel: { name: string }) => {
                return {
                    authorize: (socketId: string, callback: (error: any, data?: any) => void) => {
                        const token = tokenStore.getToken();
                        axios.post(`${baseOrigin}/broadcasting/auth`, {
                            socket_id: socketId,
                            channel_name: channel.name
                        }, {
                            headers: {
                                Authorization: token ? `Bearer ${token}` : '',
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                            }
                        })
                            .then(response => {
                                callback(false, response.data);
                            })
                            .catch(error => {
                                if (import.meta.env.DEV) {
                                    console.warn('[Echo] Authorization failed:', channel.name);
                                }
                                callback(true, error);
                            });
                    }
                };
            },
        });
    } catch (error) {
        if (import.meta.env.DEV) {
            console.warn('[Echo] Failed to initialize:', error);
        }
        return null;
    }
};

const echo = createEcho();
window.Echo = echo;

export default echo;
