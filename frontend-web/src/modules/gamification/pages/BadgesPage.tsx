import React from 'react';
import Loading from '../../../components/common/Loading';
import { EmptyState } from '../../../components/ui/EmptyStates';
import { useBadges } from '../hooks';
import { Award, Lock, CheckCircle2, Star, Shield, Flame, Target, Zap } from 'lucide-react';

const badgeIconMap: Record<string, React.ElementType> = {
  'achievement': Star,
  'streak': Flame,
  'attendance': CheckCircle2,
  'punctuality': Target,
  'consistency': Shield,
  'special': Zap,
};

const BadgesPage: React.FC = () => {
  const { data: badges, isLoading } = useBadges();

  if (isLoading) return <Loading text="Memuat badges..." />;

  const earned = (badges || []).filter((b) => b.is_earned);
  const locked = (badges || []).filter((b) => !b.is_earned);

  return (
    <div className="p-6 space-y-6 max-w-4xl mx-auto">
      <div>
        <h1 className="text-2xl font-bold text-slate-900 flex items-center gap-2">
          <Award className="w-6 h-6 text-amber-500" />
          Badges & Pencapaian
        </h1>
        <p className="text-sm text-slate-500">
          {earned.length} dari {(badges || []).length} badge telah diraih
        </p>
      </div>

      {/* Progress Bar */}
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
        <div className="flex items-center justify-between mb-2">
          <span className="text-sm font-medium text-slate-700">Progress</span>
          <span className="text-sm text-slate-500">{earned.length}/{(badges || []).length}</span>
        </div>
        <div className="h-3 bg-slate-100 rounded-full overflow-hidden">
          <div
            className="h-full bg-gradient-to-r from-amber-400 to-amber-600 rounded-full transition-all"
            style={{ width: `${badges?.length ? (earned.length / badges.length) * 100 : 0}%` }}
          />
        </div>
      </div>

      {/* Earned Badges */}
      {earned.length > 0 && (
        <div>
          <h2 className="text-lg font-semibold text-slate-800 mb-3 flex items-center gap-2">
            <CheckCircle2 className="w-5 h-5 text-green-600" />
            Badge Diperoleh ({earned.length})
          </h2>
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            {earned.map((badge) => {
              const Icon = badgeIconMap[badge.category] || Award;
              return (
                <div key={badge.id} className="bg-white rounded-xl shadow-sm border border-amber-200 p-4 text-center hover:shadow-md transition-shadow">
                  <div className="w-14 h-14 mx-auto rounded-full bg-gradient-to-br from-amber-100 to-amber-50 flex items-center justify-center mb-3">
                    <Icon className="w-7 h-7 text-amber-600" />
                  </div>
                  <p className="font-semibold text-slate-800 text-sm">{badge.name}</p>
                  <p className="text-xs text-slate-400 mt-1">{badge.description}</p>
                  {badge.earned_at && (
                    <p className="text-xs text-amber-600 mt-2">
                      Diraih: {new Date(badge.earned_at).toLocaleDateString('id-ID')}
                    </p>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Locked Badges */}
      {locked.length > 0 && (
        <div>
          <h2 className="text-lg font-semibold text-slate-800 mb-3 flex items-center gap-2">
            <Lock className="w-5 h-5 text-slate-400" />
            Badge Terkunci ({locked.length})
          </h2>
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            {locked.map((badge) => {
              const Icon = badgeIconMap[badge.category] || Award;
              return (
                <div key={badge.id} className="bg-slate-50 rounded-xl border border-slate-200 p-4 text-center opacity-60">
                  <div className="w-14 h-14 mx-auto rounded-full bg-slate-200 flex items-center justify-center mb-3 relative">
                    <Icon className="w-7 h-7 text-slate-400" />
                    <Lock className="w-4 h-4 text-slate-500 absolute -bottom-0.5 -right-0.5" />
                  </div>
                  <p className="font-semibold text-slate-600 text-sm">{badge.name}</p>
                  <p className="text-xs text-slate-400 mt-1">{badge.description}</p>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {(!badges || badges.length === 0) && (
        <EmptyState
          icon={Award}
          title="Belum Ada Badge"
          description="Badge akan muncul saat Anda mencapai pencapaian tertentu. Terus tingkatkan kehadiran Anda untuk meraih badge!"
          size="md"
        />
      )}
    </div>
  );
};

export default BadgesPage;
