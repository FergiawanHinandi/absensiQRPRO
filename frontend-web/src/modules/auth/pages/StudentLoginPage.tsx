import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useAuthStore } from '../stores/useAuthStore';
import { authService } from '../services/authService';
import type { AuthResponse } from '../types';
import { getErrorMessage } from '../../../utils/errorHandler';

export const StudentLoginPage: React.FC = () => {
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
            const redirectUrl = data.redirect_url || '/student/dashboard';
            navigate(redirectUrl, { replace: true });
        } catch (err: unknown) {
            setError(getErrorMessage(err));
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-gradient-to-br from-purple-900 via-pink-900 to-rose-900 flex items-center justify-center p-4 relative overflow-hidden">
            {/* Animated Background */}
            <div className="absolute inset-0 overflow-hidden">
                <div className="absolute top-20 right-20 w-96 h-96 bg-purple-500 rounded-full mix-blend-soft-light filter blur-3xl opacity-20 animate-blob"></div>
                <div className="absolute bottom-20 left-20 w-96 h-96 bg-pink-500 rounded-full mix-blend-soft-light filter blur-3xl opacity-20 animate-blob animation-delay-2000"></div>
                <div className="absolute top-1/2 left-1/2 w-96 h-96 bg-rose-500 rounded-full mix-blend-soft-light filter blur-3xl opacity-20 animate-blob animation-delay-4000"></div>
                
                {/* Floating youth icons */}
                {['🎮', '🎵', '⚽', '🎨', '📱', '🌟', '🎯'].map((icon, i) => (
                    <div
                        key={i}
                        className="absolute text-4xl opacity-20"
                        style={{
                            left: `${5 + i * 14}%`,
                            top: `${10 + (i % 4) * 22}%`,
                            animation: `float ${2.5 + i * 0.4}s ease-in-out infinite`,
                            animationDelay: `${i * 0.2}s`,
                        }}
                    >
                        {icon}
                    </div>
                ))}
            </div>

            <div className="relative z-10 w-full max-w-md">
                {/* Back button */}
                <Link to="/login" className="inline-flex items-center text-purple-300 hover:text-purple-200 mb-6 transition-colors">
                    <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                    Kembali
                </Link>

                {/* Login Card */}
                <div className="bg-white/10 backdrop-blur-xl rounded-3xl p-8 border border-white/20 shadow-2xl">
                    {/* Header */}
                    <div className="text-center mb-8">
                        <div className="inline-flex items-center justify-center w-20 h-20 bg-gradient-to-br from-purple-400 to-pink-600 rounded-2xl mb-6 shadow-lg shadow-purple-500/30 animate-bounce-slow">
                            <span className="text-4xl">🎒</span>
                        </div>
                        <h1 className="text-3xl font-bold text-white mb-2">Halo, Siswa! 👋</h1>
                        <p className="text-purple-200">Lihat absensi & jadwal harianmu</p>
                    </div>

                    {/* Fun Stats */}
                    <div className="grid grid-cols-3 gap-2 mb-6">
                        <div className="bg-white/10 rounded-xl p-3 text-center">
                            <span className="text-2xl">🏆</span>
                            <p className="text-xs text-purple-300 mt-1">Leaderboard</p>
                        </div>
                        <div className="bg-white/10 rounded-xl p-3 text-center">
                            <span className="text-2xl">🎯</span>
                            <p className="text-xs text-purple-300 mt-1">Badges</p>
                        </div>
                        <div className="bg-white/10 rounded-xl p-3 text-center">
                            <span className="text-2xl">📊</span>
                            <p className="text-xs text-purple-300 mt-1">Stats</p>
                        </div>
                    </div>

                    {/* Form */}
                    <form onSubmit={handleSubmit} className="space-y-5">
                        <div>
                            <label className="block text-sm font-medium text-purple-200 mb-2">Username</label>
                            <div className="relative">
                                <input
                                    type="text"
                                    value={username}
                                    onChange={(e) => setUsername(e.target.value)}
                                    className="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-purple-200/50 focus:outline-none focus:ring-2 focus:ring-purple-400 focus:border-transparent transition-all"
                                    placeholder="Masukkan NIS atau username"
                                    required
                                />
                                <div className="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                    <span className="text-xl">🎒</span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-purple-200 mb-2">Password</label>
                            <div className="relative">
                                <input
                                    type={showPassword ? 'text' : 'password'}
                                    value={password}
                                    onChange={(e) => setPassword(e.target.value)}
                                    className="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-purple-200/50 focus:outline-none focus:ring-2 focus:ring-purple-400 focus:border-transparent transition-all"
                                    placeholder="Masukkan password"
                                    required
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(!showPassword)}
                                    className="absolute inset-y-0 right-0 pr-3 flex items-center text-purple-300 hover:text-purple-200 transition-colors"
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
                            className="w-full py-4 bg-gradient-to-r from-purple-500 to-pink-600 text-white font-bold rounded-xl hover:from-purple-400 hover:to-pink-500 transform hover:scale-[1.02] transition-all duration-300 disabled:opacity-50 disabled:cursor-not-allowed shadow-lg shadow-purple-500/30 text-lg"
                        >
                            {isLoading ? (
                                <span className="flex items-center justify-center">
                                    <svg className="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Masuk...
                                </span>
                            ) : (
                                '🚀 Masuk Sekarang!'
                            )}
                        </button>
                    </form>

                    {/* Gamification teaser */}
                    <div className="mt-6 bg-gradient-to-r from-purple-500/20 to-pink-500/20 rounded-xl p-4 border border-purple-400/30">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm font-semibold text-white">🎯 Ada yang menarik!</p>
                                <p className="text-xs text-purple-300">Kumpulkan points & menangkan hadiah</p>
                            </div>
                            <span className="text-3xl animate-bounce">🎁</span>
                        </div>
                    </div>

                    {/* Demo credentials - only in development */}
                    {import.meta.env.DEV && (
                        <div className="mt-4 p-3 bg-white/5 rounded-xl border border-white/10">
                            <p className="text-xs text-purple-300 text-center mb-1">Demo Credentials:</p>
                            <p className="text-sm text-purple-200 text-center font-mono">siswasd / password</p>
                        </div>
                    )}
                </div>
            </div>

            <style>{`
                @keyframes float {
                    0%, 100% { transform: translateY(0px) rotate(0deg); }
                    50% { transform: translateY(-20px) rotate(10deg); }
                }
                @keyframes blob {
                    0%, 100% { transform: translate(0, 0) scale(1); }
                    33% { transform: translate(30px, -50px) scale(1.1); }
                    66% { transform: translate(-20px, 20px) scale(0.9); }
                }
                @keyframes bounce-slow {
                    0%, 100% { transform: translateY(0); }
                    50% { transform: translateY(-10px); }
                }
                .animation-delay-2000 { animation-delay: 2s; }
                .animation-delay-4000 { animation-delay: 4s; }
                .animate-bounce-slow { animation: bounce-slow 2s ease-in-out infinite; }
            `}</style>
        </div>
    );
};
