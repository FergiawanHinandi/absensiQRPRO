import React from 'react';
import { Settings, School, Calendar } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import Loading from '../../components/common/Loading';
import ErrorMessage from '../../components/common/ErrorMessage';
import { useSchoolProfile, useAcademicYears } from '../../modules/admin/hooks';

const titleMap: Record<string, string> = {
    '/admin/settings': 'Pengaturan Sekolah',
    '/admin/settings/profile': 'Profil Sekolah',
    '/admin/settings/academic-year': 'Tahun Ajaran Aktif',
    '/admin/settings/branding': 'Logo & Kop Laporan',
    '/admin/settings/notifications': 'Notifikasi (WA/Email/App)',
};

const AdminSchoolSettings: React.FC = () => {
    const location = useLocation();
    const title = titleMap[location.pathname] ?? 'Pengaturan Sekolah';
    const { data: profile, isLoading: profileLoading, error: profileError, refetch: refetchProfile } = useSchoolProfile();
    const { data: academicYears, isLoading: yearLoading, error: yearError, refetch: refetchYears } = useAcademicYears();
    const isBranding = location.pathname.includes('/settings/branding');
    const isNotifications = location.pathname.includes('/settings/notifications');
    const settings = profile?.settings ?? {};

    if (profileLoading || yearLoading) {
        return <Loading text="Memuat pengaturan sekolah..." />;
    }

    if (profileError || yearError) {
        return (
            <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
                <ErrorMessage message="Gagal memuat pengaturan sekolah" onRetry={() => {
                    refetchProfile();
                    refetchYears();
                }} />
            </div>
        );
    }

    const activeYear = academicYears?.years?.find((year) => year.is_active);

    if (isBranding) {
        return (
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                            <Settings className="w-6 h-6 text-blue-600" />
                            {title}
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">Branding sekolah untuk laporan dan aplikasi.</p>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-4">
                    <div className="text-sm text-gray-600">Logo saat ini:</div>
                    {profile?.logo_url ? (
                        <img src={profile.logo_url} alt="Logo Sekolah" className="h-20" />
                    ) : (
                        <div className="text-sm text-gray-500">Belum ada logo.</div>
                    )}
                    <pre className="bg-slate-50 rounded-lg p-4 text-xs text-slate-600 overflow-x-auto">
                        {JSON.stringify(settings?.branding ?? {}, null, 2)}
                    </pre>
                </div>
            </div>
        );
    }

    if (isNotifications) {
        return (
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                            <Settings className="w-6 h-6 text-blue-600" />
                            {title}
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">Pengaturan notifikasi sekolah.</p>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <pre className="bg-slate-50 rounded-lg p-4 text-xs text-slate-600 overflow-x-auto">
                        {JSON.stringify(settings?.notifications ?? {}, null, 2)}
                    </pre>
                </div>
            </div>
        );
    }

    // Tampilkan card tahun ajaran hanya jika di halaman academic year
    if (location.pathname === '/admin/settings/academic-year') {
        return (
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                            <Settings className="w-6 h-6 text-blue-600" />
                            {title}
                        </h1>
                        <p className="text-sm text-gray-500 mt-1">Informasi tahun ajaran aktif sekolah.</p>
                    </div>
                </div>
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-4">
                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                        <Calendar className="w-5 h-5 text-blue-600" />
                        Tahun Ajaran Aktif
                    </h2>
                    <div className="text-sm text-gray-600 space-y-2">
                        <div><span className="font-medium text-gray-900">Tahun Ajaran:</span> {activeYear?.name ?? '-'}</div>
                        <div><span className="font-medium text-gray-900">Semester:</span> {activeYear?.semester ?? '-'}</div>
                        <div><span className="font-medium text-gray-900">Periode:</span> {activeYear ? `${activeYear.start_date} - ${activeYear.end_date}` : '-'}</div>
                    </div>
                    <div className="border-t pt-4">
                        <h3 className="text-sm font-semibold text-gray-900 mb-2">Daftar Tahun Ajaran</h3>
                        <ul className="text-sm text-gray-600 space-y-1">
                            {academicYears?.years?.length ? (
                                academicYears.years.map((year) => (
                                    <li key={year.id} className="flex items-center justify-between">
                                        <span>{year.name} (Semester {year.semester})</span>
                                        <span className={`text-xs font-semibold px-2 py-0.5 rounded-full ${year.is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                                            {year.is_active ? 'Aktif' : 'Nonaktif'}
                                        </span>
                                    </li>
                                ))
                            ) : (
                                <li>-</li>
                            )}
                        </ul>
                    </div>
                </div>
            </div>
        );
    }

    // Halaman profil sekolah/settings/profile hanya tampilkan profil dan konfigurasi tambahan
    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
                        <Settings className="w-6 h-6 text-blue-600" />
                        {title}
                    </h1>
                    <p className="text-sm text-gray-500 mt-1">Informasi profil dan konfigurasi sekolah.</p>
                </div>
            </div>
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-4">
                <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                    <School className="w-5 h-5 text-blue-600" />
                    Profil Sekolah
                </h2>
                <div className="text-sm text-gray-600 space-y-2">
                    <div><span className="font-medium text-gray-900">Nama:</span> {profile?.name ?? '-'}</div>
                    <div><span className="font-medium text-gray-900">NPSN:</span> {profile?.npsn ?? '-'}</div>
                    <div><span className="font-medium text-gray-900">Jenjang:</span> {profile?.school_level ?? '-'}</div>
                    <div><span className="font-medium text-gray-900">Alamat:</span> {profile?.address ?? '-'}</div>
                    <div><span className="font-medium text-gray-900">Email:</span> {profile?.email ?? '-'}</div>
                    <div><span className="font-medium text-gray-900">Telepon:</span> {profile?.phone ?? '-'}</div>
                    <div><span className="font-medium text-gray-900">Zona Waktu:</span> {profile?.timezone ?? '-'}</div>
                    <div><span className="font-medium text-gray-900">Radius GPS:</span> {profile?.radius_meters ?? '-'} m</div>
                </div>
            </div>
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-3">Konfigurasi Tambahan</h2>
                <p className="text-sm text-gray-500 mb-3">Data konfigurasi disimpan di pengaturan sekolah.</p>
                <pre className="bg-slate-50 rounded-lg p-4 text-xs text-slate-600 overflow-x-auto">
                    {JSON.stringify(profile?.settings ?? {}, null, 2)}
                </pre>
            </div>
        </div>
    );
};

export default AdminSchoolSettings;
