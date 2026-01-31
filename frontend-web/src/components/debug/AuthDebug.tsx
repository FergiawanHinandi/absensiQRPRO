import React from 'react';
import { useAuthStore } from '../../modules/auth/stores/useAuthStore';

export const AuthDebug: React.FC = () => {
    const { user, isAuthenticated, isLoading, token } = useAuthStore();

    if (import.meta.env.PROD) return null;

    return (
        <div className="fixed bottom-4 right-4 bg-black/80 text-white p-4 rounded-lg text-xs max-w-sm z-50">
            <h3 className="font-bold mb-2">Auth Debug</h3>
            <div className="space-y-1">
                <div>Loading: {isLoading ? 'Yes' : 'No'}</div>
                <div>Authenticated: {isAuthenticated ? 'Yes' : 'No'}</div>
                <div>Token: {token ? 'Present' : 'None'}</div>
                <div>User Role: {user?.role_type || 'None'}</div>
                <div>User Name: {user?.name || 'None'}</div>
                <div>Current Path: {window.location.pathname}</div>
            </div>
        </div>
    );
};