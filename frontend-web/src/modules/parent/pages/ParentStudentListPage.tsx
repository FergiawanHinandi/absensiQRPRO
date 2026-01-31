import React, { useEffect, useState } from 'react';
import Loading from '../../../components/common/Loading';
import ErrorMessage from '../../../components/common/ErrorMessage';
import { apiClient } from '../../../lib/api';

const ParentStudentListPage: React.FC = () => {
  const [data, setData] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/v1/parent/students')
      .then(res => setData(res.data?.students || []))
      .catch(() => setError('Gagal memuat daftar siswa'))
      .finally(() => setIsLoading(false));
  }, []);

  if (isLoading) return <Loading text="Memuat daftar siswa..." />;
  if (error) return <ErrorMessage message={error} onRetry={() => window.location.reload()} />;

  return (
    <div className="max-w-2xl mx-auto p-8">
      <div className="bg-white rounded-xl shadow p-6 border border-slate-200">
        <h2 className="text-lg font-bold mb-4">Daftar Anak</h2>
        <ul className="divide-y">
          {data.length === 0 && <li className="py-4 text-gray-500">Tidak ada data siswa.</li>}
          {data.map((s) => (
            <li key={s.id} className="py-4">
              <div className="font-semibold">{s.name}</div>
              <div className="text-sm text-gray-600">Kelas: {s.class_name}</div>
              <div className="text-xs text-gray-400">NISN: {s.nisn}</div>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
};

export default ParentStudentListPage;
