import React, { useEffect, useState } from 'react';
import { Users, School, MapPin, Package, TrendingUp } from 'lucide-react';
import { apiClient } from '../../../lib/api';

interface StatsData {
    totals: {
        schools: number;
        students: number;
        teachers: number;
        active_users_monthly: number;
    };
    regions: Array<{ region: string; count: number }>;
    plans: Array<{ plan: string; count: number }>;
}

export const PlatformStatistics: React.FC = () => {
    const [stats, setStats] = useState<StatsData | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        const fetchStats = async () => {
            try {
                const response = await apiClient.get('/super-admin/reports/platform-stats');
                if (response.data.success) {
                    setStats(response.data.data);
                }
            } catch (error) {
                console.error('Failed to fetch platform stats:', error);
            } finally {
                setLoading(false);
            }
        };

        fetchStats();
    }, []);

    if (loading) {
        return (
            <div className="flex justify-center items-center min-h-[50vh]">
                <div className="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
            </div>
        );
    }

    if (!stats) return null;

    return (
        <div className="min-h-screen bg-slate-50 p-6">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-slate-900 mb-2">Platform Statistics</h1>
                <p className="text-slate-600">Analisis pertumbuhan user dan distribusi penggunaan sistem.</p>
            </div>

            {/* Metrics Grid */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <StatCard
                    title="Total Sekolah"
                    value={stats.totals.schools.toLocaleString()}
                    icon={School}
                    color="blue"
                    trend="+2 bulan ini"
                />
                <StatCard
                    title="Total Siswa"
                    value={stats.totals.students.toLocaleString()}
                    icon={Users}
                    color="green"
                    trend="+150 bulan ini"
                />
                <StatCard
                    title="Total Guru"
                    value={stats.totals.teachers.toLocaleString()}
                    icon={Users}
                    color="purple"
                    trend="+12 bulan ini"
                />
                <StatCard
                    title="User Aktif (MAU)"
                    value={Math.round(stats.totals.active_users_monthly).toLocaleString()}
                    icon={TrendingUp}
                    color="amber"
                    trend="~85% dari total"
                />
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Regional Distribution */}
                <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                    <h3 className="font-bold text-slate-800 mb-6 flex items-center gap-2">
                        <MapPin className="w-5 h-5 text-slate-500" />
                        Sebaran Wilayah
                    </h3>
                    <div className="space-y-4">
                        {stats.regions.map((region, idx) => {
                            const max = Math.max(...stats.regions.map(r => r.count));
                            const width = (region.count / max) * 100;
                            return (
                                <div key={idx}>
                                    <div className="flex justify-between text-sm mb-1">
                                        <span className="text-slate-700 font-medium">{region.region}</span>
                                        <span className="text-slate-500">{region.count} Sekolah</span>
                                    </div>
                                    <div className="w-full bg-slate-100 rounded-full h-2">
                                        <div
                                            className="bg-blue-500 h-2 rounded-full transition-all duration-1000"
                                            style={{ width: `${width}%` }}
                                        ></div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Plan Distribution */}
                <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
                    <h3 className="font-bold text-slate-800 mb-6 flex items-center gap-2">
                        <Package className="w-5 h-5 text-slate-500" />
                        Distribusi Paket Langganan
                    </h3>
                    <div className="flex flex-col gap-4">
                        {stats.plans.map((plan, idx) => {
                            // Simple visual representation
                            const totalPlans = stats.plans.reduce((sum, p) => sum + p.count, 0);
                            const percentage = Math.round((plan.count / totalPlans) * 100);

                            let colorClass = 'bg-slate-100 text-slate-600';
                            if (plan.plan === 'Pro') colorClass = 'bg-blue-100 text-blue-700';
                            if (plan.plan === 'Enterprise') colorClass = 'bg-purple-100 text-purple-700';
                            if (plan.plan === 'Basic') colorClass = 'bg-green-100 text-green-700';

                            return (
                                <div key={idx} className="flex items-center p-4 rounded-lg border border-slate-100 hover:shadow-sm transition-shadow">
                                    <div className={`w-12 h-12 rounded-lg flex items-center justify-center font-bold text-lg mr-4 ${colorClass}`}>
                                        {plan.plan.substring(0, 1)}
                                    </div>
                                    <div className="flex-1">
                                        <h4 className="font-bold text-slate-800">{plan.plan} Plan</h4>
                                        <p className="text-xs text-slate-500">{percentage}% Market Share</p>
                                    </div>
                                    <div className="text-right">
                                        <span className="text-xl font-bold text-slate-900">{plan.count}</span>
                                        <p className="text-xs text-slate-500">Sekolah</p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
};

const StatCard = ({ title, value, icon: Icon, color, trend }: any) => {
    const colors: any = {
        blue: 'bg-blue-50 text-blue-600',
        green: 'bg-green-50 text-green-600',
        purple: 'bg-purple-50 text-purple-600',
        amber: 'bg-amber-50 text-amber-600'
    };

    return (
        <div className="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <div className="flex justify-between items-start mb-4">
                <div>
                    <p className="text-slate-500 text-sm font-medium mb-1">{title}</p>
                    <h3 className="text-2xl font-bold text-slate-900">{value}</h3>
                </div>
                <div className={`p-3 rounded-lg ${colors[color]}`}>
                    <Icon className="w-5 h-5" />
                </div>
            </div>
            <p className="text-xs text-slate-500 flex items-center gap-1">
                <TrendingUp className="w-3 h-3 text-green-500" />
                <span className="text-green-600 font-medium">{trend}</span>
            </p>
        </div>
    )
}
