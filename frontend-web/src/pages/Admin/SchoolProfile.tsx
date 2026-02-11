
import React, { useEffect, useState } from 'react';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { apiClient } from '../../lib/api';

const SchoolProfile: React.FC = () => {
  const [data, setData] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/admin/school/profile')
      .then(res => setData(res.data))
      .catch(() => setError('Gagal memuat profil sekolah'))
      .finally(() => setIsLoading(false));
  }, []);

  if (isLoading) return <Loading text="Memuat profil sekolah..." />;
  if (error) return <ErrorMessage message={error} onRetry={() => window.location.reload()} />;

  return (
    <div className="max-w-2xl mx-auto p-8 space-y-8">
      {/* Card Profil Sekolah */}
      <div className="bg-white rounded-xl shadow p-6 border border-slate-200">
        <h2 className="text-lg font-bold mb-4 flex items-center gap-2">
          <span className="text-blue-600">
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 3v2m0 14v2m7-7h2m-18 0H3m15.364-6.364l1.414 1.414M4.222 19.778l1.414-1.414M19.778 19.778l-1.414-1.414M4.222 4.222l1.414 1.414" /></svg>
          </span>
          Profil Sekolah
        </h2>
        <p><strong>Nama:</strong> {data?.name}</p>
        <p><strong>NPSN:</strong> {data?.npsn}</p>
        <p><strong>Jenjang:</strong> {data?.level}</p>
        <p><strong>Alamat:</strong> {data?.address}</p>
        <p><strong>Email:</strong> {data?.email}</p>
        <p><strong>Telepon:</strong> {data?.phone}</p>
        <p><strong>Zona Waktu:</strong> {data?.timezone}</p>
        <p><strong>Radius GPS:</strong> {data?.gps_radius} m</p>
      </div>
      {/* Card Konfigurasi Tambahan */}
      <div className="bg-white rounded-xl shadow p-6 border border-slate-200">
        <h2 className="text-lg font-bold mb-4">Konfigurasi Tambahan</h2>
        <p>Data konfigurasi disimpan di pengaturan sekolah.</p>
        <pre className="bg-slate-50 rounded p-4 mt-4 text-xs">{JSON.stringify(data?.settings, null, 2)}</pre>
      </div>
    </div>
  );
};

export default SchoolProfile;
