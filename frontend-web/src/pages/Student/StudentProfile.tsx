import React from 'react';
import Loading from '../../components/common/Loading';
import { useStudentProfile } from '../../modules/student/hooks';
import { Mail, Phone, School, GraduationCap, AtSign } from 'lucide-react';

const StudentProfile: React.FC = () => {
  const { data: profile, isLoading } = useStudentProfile();

  if (isLoading) return <Loading text="Memuat profil..." />;

  return (
    <div className="p-6 max-w-2xl mx-auto space-y-6">
      {/* Header Card */}
      <div className="bg-gradient-to-r from-blue-600 to-indigo-600 rounded-2xl p-6 text-white text-center">
        <div className="w-20 h-20 rounded-full mx-auto mb-4 border-3 border-white/30 overflow-hidden bg-white/10">
          <img
            src={profile?.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(profile?.name || 'S')}&background=6366f1&color=fff`}
            alt="Profile"
            className="w-full h-full object-cover"
          />
        </div>
        <h1 className="text-2xl font-bold">{profile?.name}</h1>
        <p className="text-blue-200 mt-1">{profile?.class?.name} — {profile?.class?.grade}</p>
      </div>

      {/* Info Card */}
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 divide-y divide-slate-100">
        <InfoRow icon={AtSign} label="Username" value={profile?.username} />
        <InfoRow icon={Mail} label="Email" value={profile?.email} />
        <InfoRow icon={Phone} label="Telepon" value={profile?.phone} />
        <InfoRow icon={GraduationCap} label="Kelas" value={`${profile?.class?.name} (${profile?.class?.grade})`} />
        <InfoRow icon={School} label="Sekolah" value={profile?.school} />
      </div>
    </div>
  );
};

const InfoRow: React.FC<{
  icon: React.ElementType;
  label: string;
  value?: string | null;
}> = ({ icon: Icon, label, value }) => (
  <div className="flex items-center gap-4 px-5 py-4">
    <div className="bg-slate-100 p-2 rounded-lg">
      <Icon className="w-5 h-5 text-slate-500" />
    </div>
    <div>
      <p className="text-xs text-slate-400">{label}</p>
      <p className="text-sm font-medium text-slate-800">{value || '—'}</p>
    </div>
  </div>
);

export default StudentProfile;
