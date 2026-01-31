import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/useAuthStore';
import { authService } from '../services/authService';
import { Input } from '../../../components/ui/Input';
import { getErrorMessage } from '../../../utils/errorHandler';

export const LoginForm: React.FC = () => {
    console.log('[LOGIN_FORM] Rendered');
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [isLoading, setLoading] = useState(false);
    const navigate = useNavigate();

    const login = useAuthStore((state) => state.login);

    const handleSubmit = async (e: any) => {
        try {
            console.log('[LOGIN_FORM] handleSubmit called - event:', e);
            console.log('[LOGIN_FORM] event.constructor:', e?.constructor?.name);
            for (const key in e) {
                if (typeof e[key] !== 'function') {
                    console.log(`[LOGIN_FORM] event[${key}]:`, e[key]);
                }
            }
            e.preventDefault();
            console.log('[LOGIN_FORM] after preventDefault');
        } catch (err) {
            console.error('[LOGIN_FORM] ERROR on preventDefault:', err);
            return;
        }
        console.log('[LOGIN] Form submitted with:', { username, password: '***' });
        setError('');
        setLoading(true);

        try {
            console.log('[LOGIN] Calling login API...');
            const data = await authService.login({ username, password });
            console.log('[LOGIN] API success:', { token: data.token?.substring(0, 20) + '...', role: data.user.role_type });

            // Store token in sessionStorage (secure)
            console.log('[LOGIN] Calling login() to store token...');
            login(data.token, data.user);

            // Determine redirect path based on role
            const { role_type } = data.user;
            let targetPath = '/login';

            if (['teacher', 'homeroom_teacher'].includes(role_type)) {
                targetPath = '/teacher/dashboard';
            } else if (role_type === 'super_admin') {
                targetPath = '/super-admin/dashboard';
            } else if (role_type === 'school_admin') {
                targetPath = '/admin/dashboard';
            } else if (role_type === 'principal') {
                targetPath = '/principal/dashboard';
            } else if (role_type === 'student') {
                targetPath = '/student/dashboard';
            } else if (role_type === 'parent') {
                targetPath = '/parent/dashboard';
            } else {
                setError('Akses ditolak. Peran pengguna tidak dikenali.');
                setLoading(false);
                return;
            }

            // Use React Router navigation (preserves state)
            console.log('[LOGIN] Navigating to:', targetPath);
            navigate(targetPath, { replace: true });
            setLoading(false);
        } catch (err: unknown) {
            console.error('[LOGIN] Error:', err);
            setError(getErrorMessage(err));
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
