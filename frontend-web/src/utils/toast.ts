import toast from 'react-hot-toast';

/**
 * Toast Notification Utility
 * Replaces native alert() with modern toast notifications
 */

export const showToast = {
    /**
     * Success toast
     */
    success: (message: string, duration: number = 3000) => {
        toast.success(message, {
            duration,
            position: 'top-right',
            style: {
                background: '#10b981',
                color: '#fff',
                padding: '16px',
                borderRadius: '8px',
            },
            iconTheme: {
                primary: '#fff',
                secondary: '#10b981',
            },
        });
    },

    /**
     * Error toast
     */
    error: (message: string, duration: number = 4000) => {
        toast.error(message, {
            duration,
            position: 'top-right',
            style: {
                background: '#ef4444',
                color: '#fff',
                padding: '16px',
                borderRadius: '8px',
            },
            iconTheme: {
                primary: '#fff',
                secondary: '#ef4444',
            },
        });
    },

    /**
     * Warning toast
     */
    warning: (message: string, duration: number = 3500) => {
        toast(message, {
            duration,
            position: 'top-right',
            icon: '⚠️',
            style: {
                background: '#f59e0b',
                color: '#fff',
                padding: '16px',
                borderRadius: '8px',
            },
        });
    },

    /**
     * Info toast
     */
    info: (message: string, duration: number = 3000) => {
        toast(message, {
            duration,
            position: 'top-right',
            icon: 'ℹ️',
            style: {
                background: '#3b82f6',
                color: '#fff',
                padding: '16px',
                borderRadius: '8px',
            },
        });
    },

    /**
     * Loading toast
     */
    loading: (message: string) => {
        return toast.loading(message, {
            position: 'top-right',
            style: {
                background: '#6b7280',
                color: '#fff',
                padding: '16px',
                borderRadius: '8px',
            },
        });
    },

    /**
     * Dismiss a specific toast or all toasts
     */
    dismiss: (toastId?: string) => {
        if (toastId) {
            toast.dismiss(toastId);
        } else {
            toast.dismiss();
        }
    },

    /**
     * Promise toast (for async operations)
     */
    promise: <T,>(
        promise: Promise<T>,
        messages: {
            loading: string;
            success: string;
            error: string;
        }
    ) => {
        return toast.promise(
            promise,
            {
                loading: messages.loading,
                success: messages.success,
                error: messages.error,
            },
            {
                position: 'top-right',
                style: {
                    padding: '16px',
                    borderRadius: '8px',
                },
            }
        );
    },
};

// Export as default for convenience
export default showToast;
