import React, { useEffect, useState } from 'react';
import Loading from '../../../components/common/Loading';
import ErrorMessage from '../../../components/common/ErrorMessage';
import { apiClient } from '../../../lib/api';

const TeacherClassListPage: React.FC = () => {
  const [data, setData] = useState<any[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/v1/teacher/classes')
      .then(res => setData(res.data?.classes || []))
      .catch(() => setError('Gagal memuat daftar kelas'))
      .finally(() => setIsLoading(false));
  }, []);

  if (isLoading) return <Loading text="Memuat daftar kelas..." />;
  if (error) return <ErrorMessage message={error} onRetry={() => window.location.reload()} />;

  return (
    <div className="max-w-2xl mx-auto p-8">
      <div className="bg-white rounded-xl shadow p-6 border border-slate-200">
        <h2 className="text-lg font-bold mb-4">Daftar Kelas</h2>
        <ul className="divide-y">
          {data.length === 0 && <li className="py-4 text-gray-500">Tidak ada data kelas.</li>}
          {data.map((c) => (
            <li key={c.id} className="py-4">
              <div className="font-semibold">{c.name}</div>
              <div className="text-sm text-gray-600">Tingkat: {c.grade_level}</div>
              <div className="text-xs text-gray-400">Tahun Ajaran: {c.academic_year_label}</div>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
};

export default TeacherClassListPage;
