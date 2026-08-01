import React, { useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../../lib/api';
import {
  AreaChart,
  Area,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
  ComposedChart,
  Line,
} from 'recharts';
import {
  TrendingUp,
  TrendingDown,
  Calendar,
  Activity,
  Loader2,
  AlertCircle,
  BarChart3,
  RefreshCw,
} from 'lucide-react';

// ─── Types ────────────────────────────────────────────────────────────────

interface TrendDay {
  date: string;
  label: string;
  present: number;
  late: number;
  absent: number;
  excused: number;
  total: number;
  rate: number;
}

interface TrendResponse {
  summary: {
    period: string;
    days: number;
    total_records: number;
    total_present: number;
    total_late: number;
    total_absent: number;
    total_excused: number;
    avg_attendance_rate: number;
    days_with_data: number;
  };
  daily: TrendDay[];
  weekly: {
    week: number;
    label: string;
    start_date: string;
    present: number;
    late: number;
    absent: number;
    excused: number;
    total: number;
    rate: number;
  }[] | null;
}

interface ComparisonData {
  this_week: { rate: number; total: number; attended: number };
  last_week: { rate: number; total: number; attended: number };
  change_percent: number;
  trend_direction: 'up' | 'down' | 'stable';
}

// ─── Hooks ────────────────────────────────────────────────────────────────

const useAttendanceTrend = (period: string) => {
  return useQuery({
    queryKey: ['attendance-trends', period],
    queryFn: async () => {
      const response = await apiClient.get<TrendResponse>('/attendance/trends', {
        params: { period },
      });
      return response.data;
    },
    refetchInterval: 120000, // Refresh every 2 minutes
    staleTime: 60000,
  });
};

const useTrendComparison = () => {
  return useQuery({
    queryKey: ['attendance-trends-comparison'],
    queryFn: async () => {
      const response = await apiClient.get<ComparisonData>('/attendance/trends/comparison');
      return response.data;
    },
    refetchInterval: 120000,
    staleTime: 60000,
  });
};

// ─── Custom Tooltip ───────────────────────────────────────────────────────

const CustomTooltip = ({ active, payload, label }: any) => {
  if (!active || !payload || !payload.length) return null;

  const data = payload[0]?.payload;

  return (
    <div className="bg-white rounded-xl shadow-xl border border-slate-100 p-4 min-w-[200px]">
      <p className="text-sm font-bold text-slate-900 mb-3">{label}</p>
      <div className="space-y-1.5">
        <div className="flex items-center justify-between gap-4">
          <div className="flex items-center gap-2">
            <div className="w-3 h-3 rounded-full bg-emerald-500" />
            <span className="text-sm text-slate-600">Hadir</span>
          </div>
          <span className="text-sm font-bold text-slate-900">{data?.present ?? 0}</span>
        </div>
        <div className="flex items-center justify-between gap-4">
          <div className="flex items-center gap-2">
            <div className="w-3 h-3 rounded-full bg-amber-400" />
            <span className="text-sm text-slate-600">Terlambat</span>
          </div>
          <span className="text-sm font-bold text-slate-900">{data?.late ?? 0}</span>
        </div>
        <div className="flex items-center justify-between gap-4">
          <div className="flex items-center gap-2">
            <div className="w-3 h-3 rounded-full bg-red-400" />
            <span className="text-sm text-slate-600">Alpha</span>
          </div>
          <span className="text-sm font-bold text-slate-900">{data?.absent ?? 0}</span>
        </div>
        <div className="flex items-center justify-between gap-4">
          <div className="flex items-center gap-2">
            <div className="w-3 h-3 rounded-full bg-blue-400" />
            <span className="text-sm text-slate-600">Sakit/Izin</span>
          </div>
          <span className="text-sm font-bold text-slate-900">{data?.excused ?? 0}</span>
        </div>
        <div className="border-t border-slate-100 mt-2 pt-2">
          <div className="flex items-center justify-between">
            <span className="text-sm text-slate-500">Kehadiran</span>
            <span className="text-sm font-bold text-emerald-600">{data?.rate ?? 0}%</span>
          </div>
        </div>
      </div>
    </div>
  );
};

// ─── Period Selector ──────────────────────────────────────────────────────

const periods = [
  { value: '7d', label: '7 Hari' },
  { value: '30d', label: '30 Hari' },
  { value: '90d', label: '90 Hari' },
];

// ─── Chart Mode Selector ──────────────────────────────────────────────────

type ChartMode = 'area' | 'bar' | 'rate';

// ─── Comparison Card ──────────────────────────────────────────────────────

const ComparisonCard: React.FC<{ data?: ComparisonData; isLoading: boolean }> = ({
  data,
  isLoading,
}) => {
  if (isLoading) {
    return (
      <div className="bg-white rounded-xl border border-slate-200 p-4 animate-pulse">
        <div className="h-4 bg-slate-200 rounded w-24 mb-3" />
        <div className="h-8 bg-slate-200 rounded w-16" />
      </div>
    );
  }

  if (!data) return null;

  const { this_week, last_week, change_percent, trend_direction } = data;
  const isUp = trend_direction === 'up';
  const isDown = trend_direction === 'down';

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-4">
      <p className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">
        vs Minggu Lalu
      </p>
      <div className="flex items-end gap-3">
        <div>
          <p className="text-2xl font-bold text-slate-900">{this_week.rate}%</p>
          <p className="text-xs text-slate-500">Minggu Ini</p>
        </div>
        <div className="flex items-center gap-1 mb-1">
          {isUp ? (
            <TrendingUp className="w-4 h-4 text-emerald-500" />
          ) : isDown ? (
            <TrendingDown className="w-4 h-4 text-red-500" />
          ) : (
            <BarChart3 className="w-4 h-4 text-slate-400" />
          )}
          <span
            className={`text-sm font-bold ${
              isUp ? 'text-emerald-600' : isDown ? 'text-red-600' : 'text-slate-500'
            }`}
          >
            {change_percent > 0 ? '+' : ''}
            {change_percent}%
          </span>
        </div>
      </div>
      <div className="mt-3 flex gap-3 text-xs text-slate-400">
        <span>Minggu Lalu: {last_week.rate}%</span>
      </div>
    </div>
  );
};

// ─── Main Chart Component ─────────────────────────────────────────────────

interface AttendanceTrendChartProps {
  title?: string;
  showComparison?: boolean;
  className?: string;
  /** Controlled period — if provided, component uses this instead of internal state */
  period?: string;
  /** Called when user changes period (only fires if not controlled) */
  onPeriodChange?: (period: string) => void;
}

const AttendanceTrendChart: React.FC<AttendanceTrendChartProps> = ({
  title = 'Tren Kehadiran',
  showComparison = true,
  className = '',
  period: controlledPeriod,
  onPeriodChange,
}) => {
  const [internalPeriod, setInternalPeriod] = useState('7d');

  // Use controlled period if provided, otherwise fall back to internal state
  const period = controlledPeriod ?? internalPeriod;

  const setPeriod = (newPeriod: string) => {
    if (onPeriodChange) {
      onPeriodChange(newPeriod);
    }
    if (!controlledPeriod) {
      setInternalPeriod(newPeriod);
    }
  };

  const [chartMode, setChartMode] = useState<ChartMode>('area');

  const { data: trendData, isLoading, error, refetch } = useAttendanceTrend(period);
  const { data: comparisonData, isLoading: comparisonLoading } = useTrendComparison();

  const chartData = useMemo(() => {
    if (!trendData?.daily) return [];
    return trendData.daily.map((day) => ({
      ...day,
      attended: day.present + day.late,
    }));
  }, [trendData]);

  // Summary stats
  const summary = trendData?.summary;
  const avgRate = summary?.avg_attendance_rate ?? 0;
  const rateColor = avgRate >= 90 ? 'text-emerald-600' : avgRate >= 75 ? 'text-amber-600' : 'text-red-600';

  if (error) {
    return (
      <div className={`bg-white rounded-2xl border border-slate-200 shadow-sm p-8 ${className}`}>
        <div className="flex flex-col items-center justify-center text-center gap-3">
          <AlertCircle className="w-10 h-10 text-red-400" />
          <p className="text-slate-600 font-medium">Gagal memuat data tren</p>
          <button
            onClick={() => refetch()}
            className="flex items-center gap-2 px-4 py-2 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition-colors text-sm font-medium"
          >
            <RefreshCw className="w-4 h-4" /> Muat Ulang
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className={`bg-white rounded-2xl border border-slate-200 shadow-sm ${className}`}>
      {/* Header */}
      <div className="px-6 py-5 border-b border-slate-100">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-lg shadow-blue-200">
              <Activity className="w-5 h-5 text-white" />
            </div>
            <div>
              <h3 className="font-bold text-slate-900">{title}</h3>
              {summary && (
                <p className="text-xs text-slate-400">
                  {summary.days_with_data} hari aktif dari {summary.days} hari
                </p>
              )}
            </div>
          </div>

          <div className="flex items-center gap-2">
            {/* Chart mode toggles */}
            <div className="flex bg-slate-100 rounded-lg p-0.5 mr-2">
              {(['area', 'bar', 'rate'] as ChartMode[]).map((mode) => (
                <button
                  key={mode}
                  onClick={() => setChartMode(mode)}
                  className={`px-2.5 py-1.5 rounded-md text-xs font-medium transition-all ${
                    chartMode === mode
                      ? 'bg-white text-slate-900 shadow-sm'
                      : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  {mode === 'area' ? 'Area' : mode === 'bar' ? 'Bar' : 'Rate'}
                </button>
              ))}
            </div>

            {/* Period selectors */}
            <div className="flex bg-slate-100 rounded-lg p-0.5">
              {periods.map((p) => (
                <button
                  key={p.value}
                  onClick={() => setPeriod(p.value)}
                  className={`px-3 py-1.5 rounded-md text-xs font-medium transition-all ${
                    period === p.value
                      ? 'bg-white text-slate-900 shadow-sm'
                      : 'text-slate-500 hover:text-slate-700'
                  }`}
                >
                  {p.label}
                </button>
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* Body */}
      <div className="p-6">
        {isLoading ? (
          <div className="flex items-center justify-center h-[300px]">
            <Loader2 className="w-8 h-8 text-blue-500 animate-spin" />
          </div>
        ) : chartData.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-[300px] text-slate-400 gap-2">
            <Calendar className="w-12 h-12 opacity-30" />
            <p className="text-sm font-medium">Belum ada data absensi</p>
            <p className="text-xs">Data akan muncul setelah siswa mulai check-in</p>
          </div>
        ) : (
          <div className="space-y-6">
            {/* Summary cards row */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div className="bg-slate-50 rounded-xl p-3 border border-slate-100">
                <p className="text-xs text-slate-500 font-medium">Rata-rata Kehadiran</p>
                <p className={`text-lg font-bold ${rateColor} mt-0.5`}>{avgRate}%</p>
              </div>
              {summary && (
                <>
                  <div className="bg-emerald-50 rounded-xl p-3 border border-emerald-100">
                    <p className="text-xs text-emerald-600 font-medium">Hadir</p>
                    <p className="text-lg font-bold text-emerald-700 mt-0.5">
                      {summary.total_present}
                    </p>
                  </div>
                  <div className="bg-amber-50 rounded-xl p-3 border border-amber-100">
                    <p className="text-xs text-amber-600 font-medium">Terlambat</p>
                    <p className="text-lg font-bold text-amber-700 mt-0.5">
                      {summary.total_late}
                    </p>
                  </div>
                  <div className="bg-red-50 rounded-xl p-3 border border-red-100">
                    <p className="text-xs text-red-600 font-medium">Alpha</p>
                    <p className="text-lg font-bold text-red-700 mt-0.5">
                      {summary.total_absent}
                    </p>
                  </div>
                </>
              )}
            </div>

            {/* Chart */}
            <div className="h-[300px] w-full">
              <ResponsiveContainer width="100%" height="100%">
                {chartMode === 'area' ? (
                  <AreaChart data={chartData} margin={{ top: 5, right: 5, left: -20, bottom: 5 }}>
                    <defs>
                      <linearGradient id="colorPresent" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="#10B981" stopOpacity={0.2} />
                        <stop offset="95%" stopColor="#10B981" stopOpacity={0} />
                      </linearGradient>
                      <linearGradient id="colorLate" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="#F59E0B" stopOpacity={0.15} />
                        <stop offset="95%" stopColor="#F59E0B" stopOpacity={0} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" vertical={false} />
                    <XAxis
                      dataKey="label"
                      axisLine={false}
                      tickLine={false}
                      tick={{ fill: '#94A3B8', fontSize: 11 }}
                      dy={8}
                    />
                    <YAxis
                      axisLine={false}
                      tickLine={false}
                      tick={{ fill: '#94A3B8', fontSize: 11 }}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend
                      verticalAlign="top"
                      height={36}
                      iconType="circle"
                      iconSize={8}
                      formatter={(value) => (
                        <span className="text-xs text-slate-600 font-medium">{value}</span>
                      )}
                    />
                    <Area
                      type="monotone"
                      dataKey="present"
                      name="Hadir"
                      stroke="#10B981"
                      strokeWidth={2}
                      fill="url(#colorPresent)"
                      dot={false}
                      activeDot={{ r: 5, fill: '#10B981', stroke: '#fff', strokeWidth: 2 }}
                    />
                    <Area
                      type="monotone"
                      dataKey="late"
                      name="Terlambat"
                      stroke="#F59E0B"
                      strokeWidth={2}
                      fill="url(#colorLate)"
                      dot={false}
                      activeDot={{ r: 5, fill: '#F59E0B', stroke: '#fff', strokeWidth: 2 }}
                    />
                  </AreaChart>
                ) : chartMode === 'bar' ? (
                  <BarChart data={chartData} margin={{ top: 5, right: 5, left: -20, bottom: 5 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" vertical={false} />
                    <XAxis
                      dataKey="label"
                      axisLine={false}
                      tickLine={false}
                      tick={{ fill: '#94A3B8', fontSize: 11 }}
                      dy={8}
                    />
                    <YAxis
                      axisLine={false}
                      tickLine={false}
                      tick={{ fill: '#94A3B8', fontSize: 11 }}
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend
                      verticalAlign="top"
                      height={36}
                      iconType="circle"
                      iconSize={8}
                      formatter={(value) => (
                        <span className="text-xs text-slate-600 font-medium">{value}</span>
                      )}
                    />
                    <Bar
                      dataKey="present"
                      name="Hadir"
                      fill="#10B981"
                      radius={[4, 4, 0, 0]}
                      stackId="a"
                      maxBarSize={period === '7d' ? 40 : 24}
                    />
                    <Bar
                      dataKey="late"
                      name="Terlambat"
                      fill="#F59E0B"
                      radius={[4, 4, 0, 0]}
                      stackId="a"
                      maxBarSize={period === '7d' ? 40 : 24}
                    />
                    <Bar
                      dataKey="absent"
                      name="Alpha"
                      fill="#EF4444"
                      radius={[4, 4, 0, 0]}
                      stackId="a"
                      maxBarSize={period === '7d' ? 40 : 24}
                    />
                  </BarChart>
                ) : (
                  <ComposedChart data={chartData} margin={{ top: 5, right: 5, left: -20, bottom: 5 }}>
                    <defs>
                      <linearGradient id="colorRate" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="#3B82F6" stopOpacity={0.2} />
                        <stop offset="95%" stopColor="#3B82F6" stopOpacity={0} />
                      </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" vertical={false} />
                    <XAxis
                      dataKey="label"
                      axisLine={false}
                      tickLine={false}
                      tick={{ fill: '#94A3B8', fontSize: 11 }}
                      dy={8}
                    />
                    <YAxis
                      axisLine={false}
                      tickLine={false}
                      tick={{ fill: '#94A3B8', fontSize: 11 }}
                      domain={[0, 100]}
                      unit="%"
                    />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend
                      verticalAlign="top"
                      height={36}
                      iconType="circle"
                      iconSize={8}
                      formatter={(value) => (
                        <span className="text-xs text-slate-600 font-medium">{value}</span>
                      )}
                    />
                    <Area
                      type="monotone"
                      dataKey="rate"
                      name="Tingkat Kehadiran"
                      stroke="#3B82F6"
                      strokeWidth={2}
                      fill="url(#colorRate)"
                      dot={{ r: 3, fill: '#3B82F6' }}
                      activeDot={{ r: 6, fill: '#3B82F6', stroke: '#fff', strokeWidth: 2 }}
                    />
                    <Line
                      type="monotone"
                      dataKey="attended"
                      name="Total Hadir"
                      stroke="#10B981"
                      strokeWidth={2}
                      dot={false}
                      strokeDasharray="4 4"
                    />
                  </ComposedChart>
                )}
              </ResponsiveContainer>
            </div>

            {/* Weekly aggregation (for 30d & 90d) */}
            {trendData?.weekly && trendData.weekly.length > 0 && (
              <div className="border-t border-slate-100 pt-4">
                <p className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">
                        Agregasi Mingguan
                      </p>
                      <div className="h-[160px]">
                        <ResponsiveContainer width="100%" height="100%">
                          <BarChart
                            data={trendData.weekly}
                            margin={{ top: 5, right: 5, left: -20, bottom: 5 }}
                          >
                            <CartesianGrid strokeDasharray="3 3" stroke="#F1F5F9" vertical={false} />
                            <XAxis
                              dataKey="label"
                              axisLine={false}
                              tickLine={false}
                              tick={{ fill: '#94A3B8', fontSize: 11 }}
                              dy={8}
                            />
                            <YAxis
                              axisLine={false}
                              tickLine={false}
                              tick={{ fill: '#94A3B8', fontSize: 11 }}
                              domain={[0, 100]}
                              unit="%"
                            />
                            <Tooltip
                              contentStyle={{
                                borderRadius: '12px',
                                border: 'none',
                                boxShadow: '0 10px 15px -3px rgb(0 0 0 / 0.1)',
                                padding: '12px',
                              }}
                            />
                            <Bar
                              dataKey="rate"
                              name="Tingkat Kehadiran"
                              fill="#3B82F6"
                              radius={[6, 6, 0, 0]}
                              maxBarSize={48}
                            />
                          </BarChart>
                        </ResponsiveContainer>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

      {/* Comparison footer */}
      {showComparison && (
        <div className="px-6 py-4 border-t border-slate-100 bg-slate-50/50 rounded-b-2xl">
          <ComparisonCard data={comparisonData} isLoading={comparisonLoading} />
        </div>
      )}
    </div>
  );
};

export { AttendanceTrendChart, useAttendanceTrend, useTrendComparison };
export type { TrendDay, TrendResponse, ComparisonData };
