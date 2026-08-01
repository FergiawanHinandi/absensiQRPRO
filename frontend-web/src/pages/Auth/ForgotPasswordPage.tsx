import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';
import { Mail, ArrowLeft, CheckCircle2, KeyRound } from 'lucide-react';

const ForgotPasswordPage: React.FC = () => {
  const [email, setEmail] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [isSent, setIsSent] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email.trim()) {
      showToast.error('Masukkan alamat email');
      return;
    }

    setIsLoading(true);
    try {
      await apiClient.post('/auth/forgot-password', { email });
      setIsSent(true);
      showToast.success('Link reset password telah dikirim ke email Anda');
    } catch (err: any) {
      if (err.response?.status === 422) {
        showToast.error(err.response.data.message || 'Email tidak ditemukan');
      } else if (err.response?.status === 429) {
        showToast.error('Terlalu banyak permintaan. Coba lagi nanti.');
      } else {
        showToast.error('Gagal mengirim email reset. Coba lagi nanti.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  if (isSent) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4">
        <div className="max-w-md w-full space-y-6 bg-white p-8 rounded-lg shadow-md border border-gray-100 text-center">
          <div className="mx-auto w-16 h-16 bg-green-100 rounded-full flex items-center justify-center">
            <CheckCircle2 className="w-8 h-8 text-green-600" />
          </div>
          <h2 className="text-2xl font-bold text-gray-900">Email Terkirim!</h2>
          <p className="text-gray-600">
            Kami telah mengirim link reset password ke <strong>{email}</strong>. Silakan cek inbox dan folder spam Anda.
          </p>
          <div className="space-y-3">
            <button
              onClick={() => { setIsSent(false); setEmail(''); }}
              className="w-full py-2.5 px-4 bg-slate-100 text-slate-700 rounded-lg font-medium hover:bg-slate-200 transition-colors"
            >
              Kirim Ulang
            </button>
            <Link
              to="/login"
              className="block w-full py-2.5 px-4 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 text-center transition-colors"
            >
              Kembali ke Login
            </Link>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4">
      <div className="max-w-md w-full space-y-6 bg-white p-8 rounded-lg shadow-md border border-gray-100">
        <div className="text-center">
          <div className="mx-auto w-14 h-14 bg-blue-100 rounded-full flex items-center justify-center mb-4">
            <KeyRound className="w-7 h-7 text-blue-600" />
          </div>
          <h2 className="text-2xl font-bold text-gray-900">Lupa Password?</h2>
          <p className="mt-2 text-sm text-gray-600">
            Masukkan email yang terdaftar. Kami akan mengirim link untuk reset password Anda.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <div className="relative">
              <Mail className="absolute left-3 top-2.5 w-4 h-4 text-gray-400" />
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="email@contoh.com"
                className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                autoFocus
              />
            </div>
          </div>

          <button
            type="submit"
            disabled={isLoading}
            className="w-full flex items-center justify-center gap-2 py-2.5 px-4 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 disabled:opacity-50 transition-colors"
          >
            {isLoading ? (
              <span className="animate-spin w-4 h-4 border-2 border-white/30 border-t-white rounded-full" />
            ) : (
              <Mail className="w-4 h-4" />
            )}
            {isLoading ? 'Mengirim...' : 'Kirim Link Reset'}
          </button>
        </form>

        <div className="text-center text-sm">
          <Link to="/login" className="text-blue-600 hover:text-blue-800 inline-flex items-center gap-1">
            <ArrowLeft className="w-3 h-3" /> Kembali ke Login
          </Link>
        </div>
      </div>
    </div>
  );
};

export default ForgotPasswordPage;
