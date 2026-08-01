import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuthStore } from '../stores/useAuthStore';
import { authService } from '../services/authService';
import type { AuthResponse } from '../types';
import { getErrorMessage } from '../../../utils/errorHandler';

export const ParentLoginPage: React.FC = () => {
    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [isLoading, setLoading] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const navigate = useNavigate();
    const login = useAuthStore((state) => state.login);

    const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        setError('');
        setLoading(true);

        try {
            const data: AuthResponse = await authService.login({ username, password });
            login(data.token, data.user);
            const redirectUrl = data.redirect_url || '/parent/dashboard';
            navigate(redirectUrl, { replace: true });
        } catch (err: unknown) {
            setError(getErrorMessage(err));
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-orange-900 via-amber-900 to-yellow-900 flex items-center justify-center p-4 relative overflow-hidden">
            {/* Animated Background */}
            <div className="absolute inset-0 overflow-hidden">
                <div className="absolute top-10 left-1/4 w-96 h-96 bg-orange-500 rounded-full mix-blend-soft-light filter blur-3xl opacity-15 animate-blob"></div>
                <div className="absolute bottom-10 right-1/4 w-96 h-96 bg-amber-500 rounded-full mix-blend-soft-light filter blur-3xl opacity-15 animate-blob animation-delay-2000"></div>
                <div className="absolute top-1/2 left-1/2 w-96 h-96 bg-yellow-500 rounded-full mix-blend-soft-light filter blur-3xl opacity-15 animate-blob animation-delay-4000"></div>
                
                {/* Floating family icons */}
                {['👨‍👩‍👧‍👦', '❤️', '🏠', '👶', '📚', '🌟'].map((icon, i) => (
                    <div
                        key={i}
                        className="absolute text-4xl opacity-15"
                        style={{
                            left: `${10 + i * 16}%`,
                            top: `${15 + (i % 3) * 28}%`,
                            animation: `float ${3.5 + i * 0.5}s ease-in-out infinite`,
                            animationDelay: `${i * 0.4}s`,
                        }}
                    >
                        {icon}
                    </div>
                ))}
            </div>

            <div className="relative z-10 w-full max-w-md">
                {/* Back button */}
                <Link to="/login" className="inline-flex items-center text-orange-300 hover:text-orange-200 mb-6 transition-colors">
                    <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                    Kembali
                </Link>

                {/* Login Card */}
                <div className="bg-white/10 backdrop-blur-xl rounded-3xl p-8 border border-white/20 shadow-2xl">
                    {/* Header */}
                    <div className="text-center mb-8">
                        <div className="inline-flex items-center justify-center w-20 h-20 bg-gradient-to-br from-orange-400 to-amber-600 rounded-2xl mb-6 shadow-lg shadow-orange-500/30">
                            <span className="text-4xl">👨‍👩‍👧‍👦</span>
                        </div>
                        <h1 className="text-3xl font-bold text-white mb-2">Halo, Ayah/Bunda! 💕</h1>
                        <p className="text-orange-200">Pantau perkembangan buah hati</p>
                    </div>

                    {/* Features Preview */}
                    <div className="grid grid-cols-2 gap-3 mb-6">
                        <div className="bg-white/10 rounded-xl p-3 text-center">
                            <span className="text-2xl">📊</span>
                            <p className="text-xs text-orange-300 mt-1">Absensi Anak</p>
                        </div>
                        <div className="bg-white/10 rounded-xl p-3 text-center">
                            <span className="text-2xl">📱</span>
                            <p className="text-xs text-orange-300 mt-1">Notifikasi Real-time</p>
                        </div>
                        <div className="bg-white/10 rounded-xl p-3 text-center">
                            <span className="text-2xl">📝</span>
                            <p className="text-xs text-orange-300 mt-1">Ajukan Izin</p>
                        </div>
                        <div className="bg-white/10 rounded-xl p-3 text-center">
                            <span className="text-2xl">📈</span>
                            <p className="text-xs text-orange-300 mt-1">Laporan Bulanan</p>
                        </div>
                    </div>

                    {/* Form */}
                    <form onSubmit={handleSubmit} className="space-y-5">
                        <div>
                            <label className="block text-sm font-medium text-orange-200 mb-2">Username / Email</label>
                            <div className="relative">
                                <input
                                    type="text"
                                    value={username}
                                    onChange={(e) => setUsername(e.target.value)}
                                    className="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-orange-200/50 focus:outline-none focus:ring-2 focus:ring-orange-400 focus:border-transparent transition-all"
                                    placeholder="Masukkan username atau email"
                                    required
                                />
                                <div className="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                    <span className="text-xl">👤</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-orange-200 mb-2">Password</label>
                            <div className="relative">
                                <input
                                    type={showPassword ? 'text' : 'password'}
                                    value={password}
                                    onChange={(e) => setPassword(e.target.value)}
                                    className="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-orange-200/50 focus:outline-none focus:ring-2 focus:ring-orange-400 focus:border-transparent transition-all"
                                    placeholder="Masukkan password"
                                    required
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(!showPassword)}
                                    className="absolute inset-y-0 right-0 pr-3 flex items-center text-orange-300 hover:text-orange-200 transition-colors"
                                >
                                    {showPassword ? '🙈' : '👁️'}
                                </button>
                            </div>
                        </div>

                        {error && (
                            <div className="bg-red-500/10 border border-red-500/30 rounded-xl p-4 text-red-300 text-sm text-center">
                                {error}
                            </div>
                        )}

                        <button
                            type="submit"
                            disabled={isLoading}
                            className="w-full py-4 bg-gradient-to-r from-orange-500 to-amber-600 text-white font-bold rounded-xl hover:from-orange-400 hover:to-amber-500 transform hover:scale-[1.02] transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-orange-500/30"
                        >
                            {isLoading ? (
                                <span className="flex items-center justify-center">
                                    <svg className="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Memproses...
                                </span>
                            ) : (
                                'Masuk sebagai Orang Tua'
                            )}
                        </button>
                    </form>

                    {/* Trust indicators */}
                    <div className="mt-6 flex items-center justify-center space-x-4 text-orange-300/60">
                        <div className="flex items-center text-xs">
                            <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clipRule="evenodd" />
                            </svg>
                            Aman & Terenkripsi
                        </div>
                        <div className="flex items-center text-xs">
                            <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                            </svg>
                            Data Privasi Terjaga
                        </div>
                    </div>

                    {/* Demo credentials - only in development */}
                    {import.meta.env.DEV && (
                        <div className="mt-4 p-3 bg-white/5 rounded-xl border border-white/10">
                            <p className="text-xs text-orange-300 text-center mb-1">Demo Credentials:</p>
                            <p className="text-sm text-orange-200 text-center font-mono">ortu_sd / password</p>
                        </div>
                    )}
                </div>
            </div>

            <style>{`
                @keyframes float {
                    0%, 100% { transform: translateY(0px); }
                    50% { transform: translateY(-15px); }
                }
                @keyframes blob {
                    0%, 100% { transform: translate(0, 0) scale(1); }
                    33% { transform: translate(30px, -50px) scale(1.1); }
                    66% { transform: translate(-20px, 20px) scale(0.9); }
                }
                .animation-delay-2000 { animation-delay: 2s; }
                .animation-delay-4000 { animation-delay: 4s; }
            `}</style>
        </div>
    );
};
