
import React, { useEffect, useState } from 'react';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { apiClient } from '../../lib/api';

const ActiveAcademicYear: React.FC = () => {
  const [data, setData] = useState<any>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiClient.get('/v1/admin/settings/academic-year')
      .then(res => setData(res.data))
      .catch(() => setError('Gagal memuat tahun ajaran'))
      .finally(() => setIsLoading(false));
  }, []);

  if (isLoading) return <Loading text="Memuat tahun ajaran..." />;
  if (error) return <ErrorMessage message={error} onRetry={() => window.location.reload()} />;

  const active = data?.years?.find((y: any) => y.is_active);

  return (
    <div className="max-w-2xl mx-auto p-8">
      {/* Card Tahun Ajaran Aktif */}
      <div className="bg-white rounded-xl shadow p-6 border border-slate-200">
        <h2 className="text-lg font-bold mb-4 flex items-center gap-2">
          <span className="text-blue-600">
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
          </span>
          Tahun Ajaran Aktif
        </h2>
        <p><strong>Tahun Ajaran:</strong> {active?.label || '-'}</p>
        <p><strong>Semester:</strong> {active?.semester || '-'}</p>
        <p><strong>Periode:</strong> {active?.start_date} s/d {active?.end_date}</p>
        <hr className="my-4" />
        <p className="font-semibold">Daftar Tahun Ajaran</p>
        <ul className="list-disc pl-6">
          {data?.years?.map((y: any) => (
            <li key={y.id} className={y.is_active ? 'font-bold text-blue-600' : ''}>
              {y.label} ({y.start_date} s/d {y.end_date})
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
};

export default ActiveAcademicYear;
