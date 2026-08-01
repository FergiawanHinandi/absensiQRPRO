import { useEffect, useState, useCallback, useRef } from 'react';
import echo from '../../lib/echo';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';

// ─── Types ────────────────────────────────────────────────────────────────

export type ConnectionStatus = 'connecting' | 'connected' | 'disconnected' | 'error';

interface UseRealtimeOptions {
  /** Enable/disable the subscription */
  enabled?: boolean;
  /** Called when connection status changes */
  onStatusChange?: (status: ConnectionStatus) => void;
}

interface UseRealtimeChannelOptions<T = any> {
  channelName: string;
  eventName: string;
  handler: (data: T) => void;
  /** Availble only if useRealtime is called first */
  enabled?: boolean;
}

// ─── Connection Hook ──────────────────────────────────────────────────────

/**
 * useRealtime
 *
 * Manages the global Echo connection lifecycle.
 * Tracks connection status and provides reconnect capability.
 *
 * Usage:
 * ```tsx
 * const { isConnected, status } = useRealtime({ enabled: true });
 * ```
 */
export function useRealtime(options: UseRealtimeOptions = {}) {
  const { enabled = true } = options;
  const { user } = useAuthStore();
  const [status, setStatus] = useState<ConnectionStatus>(
    echo ? 'disconnected' : 'error'
  );

  const setStatusSafe = useCallback((newStatus: ConnectionStatus) => {
    setStatus(newStatus);
    options.onStatusChange?.(newStatus);
  }, [options.onStatusChange]);

  useEffect(() => {
    if (!enabled || !echo || !user) {
      setStatusSafe(echo ? 'disconnected' : 'error');
      return;
    }

    // Try to determine initial connection state
    try {
      if ((echo as any)?.connector?.pusher?.connection?.state === 'connected') {
        setStatusSafe('connected');
      } else {
        setStatusSafe('connecting');
      }
    } catch {
      setStatusSafe('connecting');
    }

    // Listen for connection events
    let connection: any = null;
    try {
      connection = (echo as any)?.connector?.pusher?.connection;
    } catch {
      // Connection not available
    }

    if (connection) {
      const handleConnected = () => setStatusSafe('connected');
      const handleDisconnected = () => setStatusSafe('disconnected');
      const handleError = () => setStatusSafe('error');

      connection.bind('connected', handleConnected);
      connection.bind('disconnected', handleDisconnected);
      connection.bind('error', handleError);

      return () => {
        connection.unbind('connected', handleConnected);
        connection.unbind('disconnected', handleDisconnected);
        connection.unbind('error', handleError);
      };
    }
  }, [enabled, user, setStatusSafe]);

  return {
    /** Whether Echo is available and connected */
    isConnected: status === 'connected',
    /** Detailed connection status */
    status,
    /** Whether Echo is available at all */
    isAvailable: echo !== null,
  };
}

// ─── Channel Subscription Hook ────────────────────────────────────────────

/**
 * useRealtimeChannel
 *
 * Subscribe to a private Echo channel and listen for events.
 * Automatically handles cleanup on unmount and user changes.
 *
 * Usage:
 * ```tsx
 * useRealtimeChannel({
 *   channelName: `school.${schoolId}`,
 *   eventName: 'StudentAttended',
 *   handler: (data) => console.log(data),
 * });
 * ```
 */
export function useRealtimeChannel<T = any>({
  channelName,
  eventName,
  handler,
  enabled = true,
}: UseRealtimeChannelOptions<T>) {
  const handlerRef = useRef(handler);
  handlerRef.current = handler;

  useEffect(() => {
    if (!enabled || !echo || !channelName || !eventName) return;

    let channel: any = null;

    try {
      channel = echo.private(channelName);

      channel.listen(eventName, (data: T) => {
        handlerRef.current(data);
      });

      // Log successful subscription
      if (import.meta.env.DEV) {
          console.debug(`[Echo] Subscribed to ${channelName}.${eventName}`);
      }
    } catch (error) {
      if (import.meta.env.DEV) {
          console.warn(`[Echo] Failed to subscribe to ${channelName}:`, error);
      }
    }

    return () => {
      if (channel) {
        try {
          channel.stopListening(eventName);
          echo.leave(channelName);
          if (import.meta.env.DEV) {
              console.debug(`[Echo] Unsubscribed from ${channelName}.${eventName}`);
          }
        } catch {
          // Ignore cleanup errors
        }
      }
    };
  }, [channelName, eventName, enabled]);
}
