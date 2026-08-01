import React from 'react';
import Loading from '../../../components/common/Loading';
import { EmptyState } from '../../../components/ui/EmptyStates';
import { useLeaderboard, useClassCompetition, useHallOfFame } from '../hooks';
import { Trophy, Medal, Crown, Star, Users, Flame, Target } from 'lucide-react';

const LeaderboardPage: React.FC = () => {
  const { data: leaderboard, isLoading } = useLeaderboard();
  const { data: classes } = useClassCompetition();
  const { data: hallOfFame } = useHallOfFame();

  if (isLoading) return <Loading text="Memuat leaderboard..." />;

  const getRankIcon = (rank: number) => {
    if (rank === 1) return <Crown className="w-5 h-5 text-yellow-500" />;
    if (rank === 2) return <Medal className="w-5 h-5 text-slate-400" />;
    if (rank === 3) return <Medal className="w-5 h-5 text-amber-600" />;
    return <span className="w-5 text-center font-mono text-slate-400">{rank}</span>;
  };

  return (
    <div className="p-6 space-y-6 max-w-4xl mx-auto">
      <div>
        <h1 className="text-2xl font-bold text-slate-900 flex items-center gap-2">
          <Trophy className="w-6 h-6 text-amber-500" />
          Leaderboard
        </h1>
        <p className="text-sm text-slate-500">Peringkat siswa berdasarkan poin kehadiran</p>
      </div>

      {/* Top 3 Spotlight */}
      {leaderboard && leaderboard.length >= 3 && (
        <div className="grid grid-cols-3 gap-4">
          {[leaderboard[1], leaderboard[0], leaderboard[2]].map((entry, idx) => {
            const rank = idx === 0 ? 2 : idx === 1 ? 1 : 3;
            const isFirst = rank === 1;
            return (
              <div
                key={entry.student_id}
                className={`text-center p-4 rounded-2xl border ${isFirst ? 'bg-gradient-to-b from-amber-50 to-white border-amber-200 shadow-md -mt-4' : 'bg-white border-slate-200'}`}
              >
                <div className="relative inline-block">
                  <img
                    src={entry.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(entry.student_name)}&background=${isFirst ? 'f59e0b' : '6366f1'}&color=fff&size=64`}
                    alt={entry.student_name}
                    className={`rounded-full mx-auto mb-2 ${isFirst ? 'w-16 h-16' : 'w-12 h-12'}`}
                  />
                  <div className="absolute -bottom-1 -right-1">{getRankIcon(rank)}</div>
                </div>
                <p className={`font-semibold text-slate-800 ${isFirst ? 'text-base' : 'text-sm'} truncate`}>{entry.student_name}</p>
                <p className="text-xs text-slate-400">{entry.class_name}</p>
                <p className="text-lg font-bold text-amber-600 mt-1">{entry.total_points} pts</p>
                <div className="flex items-center justify-center gap-1 mt-1">
                  <Flame className="w-3 h-3 text-orange-500" />
                  <span className="text-xs text-slate-500">{entry.current_streak} hari</span>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Full Leaderboard */}
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-slate-50">
              <tr className="text-left text-slate-400 text-xs uppercase">
                <th className="px-4 py-3 w-12">#</th>
                <th className="px-4 py-3">Siswa</th>
                <th className="px-4 py-3 text-center">Poin</th>
                <th className="px-4 py-3 text-center">Streak</th>
                <th className="px-4 py-3 text-center">Kehadiran</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-50">
              {(leaderboard || []).map((entry) => (
                <tr key={entry.student_id} className={`hover:bg-slate-50 ${entry.rank <= 3 ? 'bg-amber-50/30' : ''}`}>
                  <td className="px-4 py-3">{getRankIcon(entry.rank)}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <img
                        src={entry.photo_url || `https://ui-avatars.com/api/?name=${encodeURIComponent(entry.student_name)}&size=32`}
                        alt=""
                        className="w-8 h-8 rounded-full"
                      />
                      <div>
                        <p className="font-medium text-slate-800">{entry.student_name}</p>
                        <p className="text-xs text-slate-400">{entry.class_name}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-center font-mono font-semibold text-amber-600">{entry.total_points}</td>
                  <td className="px-4 py-3 text-center">
                    <span className="inline-flex items-center gap-1 text-orange-500">
                      <Flame className="w-3 h-3" /> {entry.current_streak}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-center">
                    <span className={`font-mono ${entry.attendance_rate >= 90 ? 'text-green-600' : entry.attendance_rate >= 75 ? 'text-yellow-600' : 'text-red-600'}`}>
                      {entry.attendance_rate}%
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {(!leaderboard || leaderboard.length === 0) && (
          <EmptyState
            icon={Trophy}
            title="Belum Ada Data Leaderboard"
            description="Data leaderboard akan muncul setelah ada aktivitas absensi. Mulai raih poin dengan hadir tepat waktu!"
            size="md"
          />
        )}
      </div>

      {/* Class Competition */}
      {classes && classes.length > 0 && (
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
          <h2 className="text-lg font-semibold text-slate-800 mb-4 flex items-center gap-2">
            <Users className="w-5 h-5 text-blue-600" />
            Kompetisi Antar Kelas
          </h2>
          <div className="space-y-3">
            {classes.map((cls) => (
              <div key={cls.class_id} className="flex items-center gap-4 p-3 bg-slate-50 rounded-lg">
                <span className={`w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold ${cls.rank <= 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-200 text-slate-500'}`}>
                  {cls.rank}
                </span>
                <div className="flex-1">
                  <p className="font-medium text-slate-800">{cls.class_name}</p>
                  <p className="text-xs text-slate-400">{cls.grade_level} • {cls.total_students} siswa</p>
                </div>
                <div className="text-right">
                  <p className="font-semibold text-amber-600">{cls.total_points} pts</p>
                  <p className="text-xs text-slate-400">{cls.avg_attendance_rate}% kehadiran</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Hall of Fame */}
      {hallOfFame && hallOfFame.length > 0 && (
        <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
          <h2 className="text-lg font-semibold text-slate-800 mb-4 flex items-center gap-2">
            <Star className="w-5 h-5 text-amber-500" />
            Hall of Fame
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {hallOfFame.map((entry, idx) => (
              <div key={idx} className="flex items-center gap-3 p-4 bg-gradient-to-r from-amber-50 to-white rounded-lg border border-amber-100">
                <Target className="w-8 h-8 text-amber-500" />
                <div>
                  <p className="font-semibold text-slate-800">{entry.student_name}</p>
                  <p className="text-xs text-slate-400">{entry.class_name}</p>
                  <p className="text-sm text-amber-600 font-medium">{entry.label}: {entry.value}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

export default LeaderboardPage;
