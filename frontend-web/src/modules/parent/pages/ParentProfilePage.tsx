import React, { useEffect, useState } from 'react';
import Loading from '../../../components/common/Loading';
import ErrorMessage from '../../../components/common/ErrorMessage';
import { apiClient } from '../../../lib/api';

const ParentProfilePage: React.FC = () => {
  const [data, setData] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/v1/parent/profile')
      .then(res => setData(res.data))
      .catch(() => setError('Gagal memuat profil orang tua'))
      .finally(() => setIsLoading(false));
  }, []);

  if (isLoading) return <Loading text="Memuat profil..." />;
  if (error) return <ErrorMessage message={error} onRetry={() => window.location.reload()} />;

  return (
    <div className="max-w-2xl mx-auto p-8">
      <div className="bg-white rounded-xl shadow p-6 border border-slate-200">
        <h2 className="text-lg font-bold mb-4">Profil Orang Tua</h2>
        <p><strong>Nama:</strong> {data?.name}</p>
        <p><strong>Email:</strong> {data?.email}</p>
        <p><strong>No. HP:</strong> {data?.phone}</p>
        <p><strong>Alamat:</strong> {data?.address}</p>
      </div>
    </div>
  );
};

export default ParentProfilePage;
