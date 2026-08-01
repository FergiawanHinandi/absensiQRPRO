import React, { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { apiClient } from '../../lib/api';
import showToast from '../../utils/toast';
import { Eye, EyeOff, UserPlus, ArrowLeft } from 'lucide-react';

const RegisterPage: React.FC = () => {
  const navigate = useNavigate();
  const [form, setForm] = useState({
    name: '',
    email: '',
    username: '',
    password: '',
    password_confirmation: '',
    role_type: 'student',
    school_code: '',
  });
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    setForm({ ...form, [e.target.name]: e.target.value });
    setErrors({ ...errors, [e.target.name]: [] });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrors({});

    try {
      await apiClient.post('/auth/register', form);
      showToast.success('Registrasi berhasil! Silakan login.');
      navigate('/login');
    } catch (err: any) {
      if (err.response?.status === 422) {
        setErrors(err.response.data.errors || {});
        showToast.error(err.response.data.message || 'Data tidak valid');
      } else {
        showToast.error('Registrasi gagal. Silakan coba lagi.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4">
      <div className="max-w-md w-full space-y-6 bg-white p-8 rounded-lg shadow-md border border-gray-100">
        <div className="text-center">
          <h2 className="text-3xl font-extrabold text-gray-900">Daftar Akun</h2>
          <p className="mt-2 text-sm text-gray-600">
            Buat akun baru untuk AbsensiQR Pro
          </p>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <FormField label="Nama Lengkap" name="name" value={form.name} onChange={handleChange} errors={errors.name} placeholder="Masukkan nama lengkap" />
          <FormField label="Email" name="email" type="email" value={form.email} onChange={handleChange} errors={errors.email} placeholder="email@contoh.com" />
          <FormField label="Username" name="username" value={form.username} onChange={handleChange} errors={errors.username} placeholder="username" />
          <FormField label="Kode Sekolah" name="school_code" value={form.school_code} onChange={handleChange} errors={errors.school_code} placeholder="Masukkan kode sekolah" />

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Role</label>
            <select
              name="role_type"
              value={form.role_type}
              onChange={handleChange}
              className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
            >
              <option value="student">Siswa</option>
              <option value="parent">Orang Tua</option>
            </select>
          </div>

          <div className="relative">
            <label className="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input
              type={showPassword ? 'text' : 'password'}
              name="password"
              value={form.password}
              onChange={handleChange}
              placeholder="Minimal 8 karakter"
              className={`w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 text-sm pr-10 ${errors.password ? 'border-red-300' : 'border-gray-300'}`}
            />
            <button type="button" onClick={() => setShowPassword(!showPassword)} className="absolute right-3 top-8 text-gray-400">
              {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
            </button>
            {errors.password && <p className="text-xs text-red-500 mt-1">{errors.password[0]}</p>}
          </div>

          <FormField label="Konfirmasi Password" name="password_confirmation" type="password" value={form.password_confirmation} onChange={handleChange} errors={errors.password_confirmation} placeholder="Ulangi password" />

          <button
            type="submit"
            disabled={isLoading}
            className="w-full flex items-center justify-center gap-2 py-2.5 px-4 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 disabled:opacity-50 transition-colors"
          >
            {isLoading ? (
              <span className="animate-spin w-4 h-4 border-2 border-white/30 border-t-white rounded-full" />
            ) : (
              <UserPlus className="w-4 h-4" />
            )}
            {isLoading ? 'Mendaftar...' : 'Daftar'}
          </button>
        </form>

        <div className="text-center text-sm">
          <Link to="/login" className="text-blue-600 hover:text-blue-800 inline-flex items-center gap-1">
            <ArrowLeft className="w-3 h-3" /> Sudah punya akun? Login
          </Link>
        </div>
      </div>
    </div>
  );
};

const FormField: React.FC<{
  label: string;
  name: string;
  type?: string;
  value: string;
  onChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  errors?: string[];
  placeholder?: string;
}> = ({ label, name, type = 'text', value, onChange, errors, placeholder }) => (
  <div>
    <label className="block text-sm font-medium text-gray-700 mb-1">{label}</label>
    <input
      type={type}
      name={name}
      value={value}
      onChange={onChange}
      placeholder={placeholder}
      className={`w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 text-sm ${errors?.length ? 'border-red-300' : 'border-gray-300'}`}
    />
    {errors?.[0] && <p className="text-xs text-red-500 mt-1">{errors[0]}</p>}
  </div>
);

export default RegisterPage;
