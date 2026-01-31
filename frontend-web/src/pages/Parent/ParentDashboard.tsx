import React from 'react';
import Loading from '../../components/common/Loading';
import {
    Calendar,
    Clock,
    CheckCircle2,
    XCircle,
    AlertTriangle,
    History,
    ChevronRight,
    Shield
} from 'lucide-react';
import {
    useStudentInfo,
    useTodayAttendance,
    useAttendanceHistory
} from '../../modules/parent/hooks';
import { AnnouncementWidget } from '../../components/AnnouncementWidget';
import { useNavigate } from 'react-router-dom';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';

const ParentDashboard: React.FC = () => {
    const navigate = useNavigate();
    const { data: student, isLoading: studentLoading } = useStudentInfo();
    const { data: today, isLoading: todayLoading } = useTodayAttendance();
    const { data: history, isLoading: historyLoading } = useAttendanceHistory();

    if (studentLoading || todayLoading || historyLoading) return <Loading text="Memuat data siswa..." />;

    // Helper untuk status warna dan icon
    const getStatusColor = (status: string) => {
        switch (status) {
            case 'present': return 'bg-green-500 text-white';
            case 'late': return 'bg-yellow-500 text-white';
            case 'sick': return 'bg-blue-500 text-white';
            case 'alpha': return 'bg-red-500 text-white';
            default: return 'bg-slate-400 text-white';
        }
    };

    const getStatusText = (status: string) => {
        switch (status) {
            case 'present': return 'Hadir Tepat Waktu';
            case 'late': return 'Terlambat';
            case 'sick': return 'Sakit / Izin';
            case 'alpha': return 'Tidak Hadir (Alpha)';
            default: return 'Belum Ada Data';
        }
    };

    const StatusIcon = ({ status, className }: { status: string, className?: string }) => {
        switch (status) {
            case 'present': return <CheckCircle2 className={className} />;
            case 'late': return <Clock className={className} />;
            case 'sick': return <Shield className={className} />;
            case 'alpha': return <XCircle className={className} />;
            default: return <AlertTriangle className={className} />;
        }
    };

    return (
        <div className="min-h-screen bg-slate-50 pb-20 font-sans text-slate-900 max-w-md mx-auto md:max-w-none shadow-xl md:shadow-none min-h-screen bg-white md:bg-slate-50">
            {/* Mobile-First Header */}
            <div className="bg-blue-600 pt-8 pb-16 px-6 rounded-b-[2.5rem] shadow-lg relative overflow-hidden">
                <div className="absolute top-0 right-0 p-8 opacity-10">
                    <Shield className="w-48 h-48 text-white rotate-12" />
                </div>

                <div className="relative z-10 flex items-center gap-4 mb-6">
                    <div className="w-14 h-14 rounded-full border-2 border-white/30 shadow-sm overflow-hidden bg-white/10 backdrop-blur-sm">
                        <img src={student?.photo_url || "https://ui-avatars.com/api/?name=User"} alt="Profile" className="w-full h-full object-cover" />
                    </div>
                    <div>
                        <p className="text-blue-100 text-sm font-medium">Orang Tua dari</p>
                        <h1 className="text-2xl font-bold text-white leading-tight">{student?.name}</h1>
                        <p className="text-blue-200 text-sm opacity-90">{student?.class_name} • {student?.nis}</p>
                    </div>
                </div>

                {/* Today's Status Card - Floating overlap */}
                <div className="absolute -bottom-12 left-6 right-6">
                    <div className="bg-white rounded-2xl p-5 shadow-lg border border-slate-100 flex items-center justify-between">
                        <div>
                            <p className="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Status Hari Ini</p>
                            <h2 className={`text-lg font-bold ${today?.status === 'present' ? 'text-green-600' :
                                today?.status === 'late' ? 'text-yellow-600' :
                                    today?.status === 'alpha' ? 'text-red-600' : 'text-slate-600'
                                }`}>
                                {getStatusText(today?.status || 'unknown')}
                            </h2>
                            <div className="flex items-center gap-2 mt-1 text-sm text-slate-500">
                                <Clock className="w-3.5 h-3.5" />
                                <span>Check-in: <span className="font-semibold text-slate-700">{today?.check_in || '-'}</span></span>
                            </div>
                        </div>
                        <div className={`w-12 h-12 rounded-full flex items-center justify-center shadow-sm ${getStatusColor(today?.status || 'unknown')}`}>
                            <StatusIcon status={today?.status || 'unknown'} className="w-6 h-6" />
                        </div>
                    </div>
                </div>
            </div>

            {/* Spacer for floating card */}
            <div className="mt-16 px-6 space-y-8">

                {/* 1. Quick Stats (Mingguan) */}
                <div className="bg-white md:bg-transparent md:bg-white md:p-6 md:rounded-2xl md:border md:border-slate-200 md:shadow-sm">
                    <h3 className="font-bold text-slate-800 mb-4 flex items-center gap-2">
                        <Calendar className="w-5 h-5 text-blue-600" />
                        Ringkasan Minggu Ini
                    </h3>
                    <div className="grid grid-cols-3 gap-3">
                        <div className="bg-green-50 p-3 rounded-xl border border-green-100 flex flex-col items-center">
                            <span className="text-2xl font-bold text-green-600">4</span>
                            <span className="text-xs text-green-700 font-medium">Hadir</span>
                        </div>
                        <div className="bg-yellow-50 p-3 rounded-xl border border-yellow-100 flex flex-col items-center">
                            <span className="text-2xl font-bold text-yellow-600">1</span>
                            <span className="text-xs text-yellow-700 font-medium">Telat</span>
                        </div>
                        <div className="bg-red-50 p-3 rounded-xl border border-red-100 flex flex-col items-center">
                            <span className="text-2xl font-bold text-red-600">0</span>
                            <span className="text-xs text-red-700 font-medium">Alpha</span>
                        </div>
                    </div>
                </div>

                {/* 2. Announcement Widget */}
                <div className="bg-white md:bg-transparent md:bg-white md:p-0 md:rounded-2xl md:border-0 md:shadow-none">
                    <AnnouncementWidget />
                </div>

                {/* 3. Recent History List */}
                <div>
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="font-bold text-slate-800 flex items-center gap-2">
                            <History className="w-5 h-5 text-blue-600" />
                            Riwayat Kehadiran
                        </h3>
                        <button onClick={() => navigate('/parent/children-history')} className="text-xs font-semibold text-blue-600 hover:text-blue-700">Lihat Semua</button>
                    </div>

                    <div className="space-y-3">
                        {history?.map((item, index) => (
                            <div key={index} className="bg-white border border-slate-100 p-4 rounded-xl shadow-sm flex items-center justify-between hover:bg-slate-50 transition-colors">
                                <div className="flex items-center gap-4">
                                    <div className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 ${item.status === 'present' ? 'bg-green-100 text-green-600' :
                                        item.status === 'late' ? 'bg-yellow-100 text-yellow-600' :
                                            item.status === 'sick' ? 'bg-blue-100 text-blue-600' :
                                                'bg-red-100 text-red-600'
                                        }`}>
                                        <span className="text-xs font-bold uppercase">{format(new Date(item.date), 'dd', { locale: id })}</span>
                                    </div>
                                    <div>
                                        <p className="font-semibold text-slate-900 text-sm">
                                            {format(new Date(item.date), 'EEEE, d MMMM', { locale: id })}
                                        </p>
                                        <div className="flex items-center gap-3 mt-1">
                                            <span className={`text-xs px-2 py-0.5 rounded font-medium ${item.status === 'present' ? 'bg-green-100 text-green-700' :
                                                item.status === 'late' ? 'bg-yellow-100 text-yellow-700' :
                                                    item.status === 'sick' ? 'bg-blue-100 text-blue-700' :
                                                        'bg-red-100 text-red-700'
                                                }`}>
                                                {item.status === 'present' ? 'Hadir' : item.status === 'late' ? 'Terlambat' : item.status}
                                            </span>
                                            {item.check_in && <span className="text-xs text-slate-500 flex items-center gap-1"><Clock className="w-3 h-3" /> {item.check_in}</span>}
                                        </div>
                                    </div>
                                </div>
                                <ChevronRight className="w-4 h-4 text-slate-300" />
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default ParentDashboard;
