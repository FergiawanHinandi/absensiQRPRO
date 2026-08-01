import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/useAuthStore';
import { authService } from '../services/authService';
import type { AuthResponse } from '../types';
import { Input } from '../../../components/ui/Input';
import { getErrorMessage } from '../../../utils/errorHandler';

export const LoginForm: React.FC = () => {
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [isLoading, setLoading] = useState(false);
    const navigate = useNavigate();

    const login = useAuthStore((state) => state.login);

    const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        try {
            const data: AuthResponse = await authService.login({ username, password });

            // Store token in sessionStorage
            login(data.token, data.user);

            // ✅ Server provides redirect URL - no manual role checking
            const redirectUrl = data.redirect_url || '/dashboard';
            
            // Use React Router navigation (preserves state)
            navigate(redirectUrl, { replace: true });
        } catch (err: unknown) {
            if (import.meta.env.DEV) {
                console.error('[LOGIN] Error:', err);
            }
            setError(getErrorMessage(err));
        } finally {
            setLoading(false);
        }
    };

    return (
        <form className="mt-8 space-y-6" onSubmit={handleSubmit}>
            <div className="space-y-4">
                <Input
                    id="username"
                    label="Username / Email"
                    type="text"
                    required
                    placeholder="Masukkan username atau email"
                    value={username}
                    onChange={(e) => setUsername(e.target.value)}
                    autoComplete="username"
                />

                <Input
                    id="password"
                    label="Password"
                    type="password"
                    required
                    placeholder="Masukkan password"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    autoComplete="current-password"
                />
            </div>

            {error && (
                <div className="text-red-600 text-sm text-center bg-red-50 p-2 rounded border border-red-200">
                    {error}
                </div>
            )}

            <button 
                type="submit" 
                className="w-full py-2 px-4 bg-blue-600 text-white rounded-md mt-4 disabled:opacity-50 disabled:cursor-not-allowed"
                disabled={isLoading}
            >
                {isLoading ? 'Memproses...' : 'Masuk'}
            </button>
        </form>
    );
};
