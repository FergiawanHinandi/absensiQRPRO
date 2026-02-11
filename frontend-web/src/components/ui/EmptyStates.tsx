import React from 'react';
import {
  Users,
  Calendar,
  Search,
  Bell,
  ClipboardList,
  BookOpen,
  QrCode,
  BarChart3,
  Settings,
  FolderOpen,
  Inbox,
  AlertCircle,
  WifiOff,
  Lock,
  Plus,
  RefreshCw,
  type LucideIcon,
} from 'lucide-react';

/**
 * Empty State Components
 * Provides informative empty states for various scenarios
 */

// ============================================================
// PRESET EMPTY STATE CONFIGURATIONS
// ============================================================

export type EmptyStatePreset =
  | 'no-data'
  | 'no-results'
  | 'no-students'
  | 'no-attendance'
  | 'no-schedules'
  | 'no-notifications'
  | 'no-reports'
  | 'no-subjects'
  | 'no-qr'
  | 'offline'
  | 'error'
  | 'unauthorized'
  | 'empty-folder'
  | 'empty-inbox';

interface PresetConfig {
  icon: LucideIcon;
  title: string;
  description: string;
  actionLabel?: string;
}

const presetConfigs: Record<EmptyStatePreset, PresetConfig> = {
  'no-data': {
    icon: FolderOpen,
    title: 'Belum Ada Data',
    description: 'Data belum tersedia. Mulai dengan menambahkan data baru.',
    actionLabel: 'Tambah Data',
  },
  'no-results': {
    icon: Search,
    title: 'Tidak Ditemukan',
    description: 'Pencarian tidak menemukan hasil. Coba kata kunci lain atau filter yang berbeda.',
  },
  'no-students': {
    icon: Users,
    title: 'Belum Ada Siswa',
    description: 'Belum ada siswa terdaftar di kelas ini. Tambahkan siswa untuk memulai.',
    actionLabel: 'Tambah Siswa',
  },
  'no-attendance': {
    icon: ClipboardList,
    title: 'Belum Ada Data Kehadiran',
    description: 'Belum ada catatan kehadiran untuk periode ini. Data akan muncul setelah ada absensi.',
  },
  'no-schedules': {
    icon: Calendar,
    title: 'Belum Ada Jadwal',
    description: 'Belum ada jadwal pelajaran yang dibuat. Buat jadwal untuk mengatur kegiatan belajar mengajar.',
    actionLabel: 'Buat Jadwal',
  },
  'no-notifications': {
    icon: Bell,
    title: 'Tidak Ada Notifikasi',
    description: 'Semua sudah beres! Anda tidak memiliki notifikasi baru saat ini.',
  },
  'no-reports': {
    icon: BarChart3,
    title: 'Belum Ada Laporan',
    description: 'Laporan akan tersedia setelah ada data kehadiran yang tercatat.',
  },
  'no-subjects': {
    icon: BookOpen,
    title: 'Belum Ada Mata Pelajaran',
    description: 'Belum ada mata pelajaran yang ditambahkan. Tambahkan mata pelajaran untuk melengkapi kurikulum.',
    actionLabel: 'Tambah Mapel',
  },
  'no-qr': {
    icon: QrCode,
    title: 'QR Code Tidak Tersedia',
    description: 'QR Code untuk sesi ini belum dibuat atau sudah kadaluarsa.',
    actionLabel: 'Buat QR Code',
  },
  offline: {
    icon: WifiOff,
    title: 'Tidak Ada Koneksi',
    description: 'Perangkat tidak terhubung ke internet. Periksa koneksi Anda dan coba lagi.',
    actionLabel: 'Coba Lagi',
  },
  error: {
    icon: AlertCircle,
    title: 'Terjadi Kesalahan',
    description: 'Maaf, terjadi kesalahan saat memuat data. Silakan coba lagi.',
    actionLabel: 'Muat Ulang',
  },
  unauthorized: {
    icon: Lock,
    title: 'Akses Ditolak',
    description: 'Anda tidak memiliki izin untuk mengakses halaman ini. Hubungi administrator jika ini adalah kesalahan.',
  },
  'empty-folder': {
    icon: FolderOpen,
    title: 'Folder Kosong',
    description: 'Folder ini masih kosong. Unggah atau tambahkan file untuk memulai.',
    actionLabel: 'Unggah File',
  },
  'empty-inbox': {
    icon: Inbox,
    title: 'Inbox Kosong',
    description: 'Tidak ada pesan baru. Pesan yang Anda terima akan muncul di sini.',
  },
};

// ============================================================
// EMPTY STATE COMPONENT
// ============================================================

interface EmptyStateProps {
  preset?: EmptyStatePreset;
  icon?: LucideIcon;
  title?: string;
  description?: string;
  action?: {
    label: string;
    onClick: () => void;
    variant?: 'primary' | 'secondary' | 'outline';
  };
  secondaryAction?: {
    label: string;
    onClick: () => void;
  };
  size?: 'sm' | 'md' | 'lg';
  className?: string;
  illustration?: React.ReactNode;
}

export const EmptyState: React.FC<EmptyStateProps> = ({
  preset,
  icon: CustomIcon,
  title: customTitle,
  description: customDescription,
  action,
  secondaryAction,
  size = 'md',
  className = '',
  illustration,
}) => {
  // Get preset config or use custom props
  const config = preset ? presetConfigs[preset] : null;
  const Icon = CustomIcon || config?.icon || FolderOpen;
  const title = customTitle || config?.title || 'Tidak Ada Data';
  const description = customDescription || config?.description || '';

  // Size variants
  const sizeClasses = {
    sm: {
      container: 'py-6 px-4',
      icon: 'w-10 h-10',
      iconWrapper: 'w-16 h-16',
      title: 'text-base',
      description: 'text-sm',
      button: 'px-3 py-1.5 text-sm',
    },
    md: {
      container: 'py-10 px-6',
      icon: 'w-12 h-12',
      iconWrapper: 'w-20 h-20',
      title: 'text-lg',
      description: 'text-base',
      button: 'px-4 py-2',
    },
    lg: {
      container: 'py-16 px-8',
      icon: 'w-16 h-16',
      iconWrapper: 'w-24 h-24',
      title: 'text-xl',
      description: 'text-lg',
      button: 'px-6 py-3 text-lg',
    },
  };

  const classes = sizeClasses[size];

  // Button variants
  const buttonVariants = {
    primary: 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500',
    secondary: 'bg-gray-600 text-white hover:bg-gray-700 focus:ring-gray-500',
    outline: 'border-2 border-gray-300 text-gray-700 hover:bg-gray-50 focus:ring-gray-500',
  };

  return (
    <div
      className={`flex flex-col items-center justify-center text-center ${classes.container} ${className}`}
      role="status"
      aria-label={title}
    >
      {/* Illustration or Icon */}
      {illustration || (
        <div
          className={`${classes.iconWrapper} rounded-full bg-gray-100 flex items-center justify-center mb-4 animate-fade-in`}
        >
          <Icon className={`${classes.icon} text-gray-400`} strokeWidth={1.5} />
        </div>
      )}

      {/* Title */}
      <h3 className={`font-semibold text-gray-900 ${classes.title} mb-2`}>
        {title}
      </h3>

      {/* Description */}
      {description && (
        <p className={`text-gray-500 ${classes.description} max-w-md mb-6`}>
          {description}
        </p>
      )}

      {/* Actions */}
      {(action || secondaryAction) && (
        <div className="flex flex-wrap items-center justify-center gap-3">
          {action && (
            <button
              onClick={action.onClick}
              className={`
                inline-flex items-center gap-2 rounded-lg font-medium
                transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2
                ${classes.button}
                ${buttonVariants[action.variant || 'primary']}
              `}
            >
              <Plus className="w-4 h-4" />
              {action.label}
            </button>
          )}
          {secondaryAction && (
            <button
              onClick={secondaryAction.onClick}
              className={`
                inline-flex items-center gap-2 rounded-lg font-medium
                text-gray-600 hover:text-gray-900 hover:bg-gray-100
                transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500
                ${classes.button}
              `}
            >
              <RefreshCw className="w-4 h-4" />
              {secondaryAction.label}
            </button>
          )}
        </div>
      )}
    </div>
  );
};

// ============================================================
// SEARCH EMPTY STATE
// ============================================================

interface SearchEmptyStateProps {
  query: string;
  onClear: () => void;
  suggestions?: string[];
  className?: string;
}

export const SearchEmptyState: React.FC<SearchEmptyStateProps> = ({
  query,
  onClear,
  suggestions = [],
  className = '',
}) => (
  <div className={`flex flex-col items-center justify-center py-10 px-6 text-center ${className}`}>
    <div className="w-16 h-16 rounded-full bg-yellow-50 flex items-center justify-center mb-4">
      <Search className="w-8 h-8 text-yellow-500" />
    </div>
    <h3 className="text-lg font-semibold text-gray-900 mb-2">
      Tidak ada hasil untuk "{query}"
    </h3>
    <p className="text-gray-500 max-w-md mb-4">
      Coba periksa ejaan atau gunakan kata kunci yang berbeda
    </p>

    {suggestions.length > 0 && (
      <div className="mb-4">
        <p className="text-sm text-gray-500 mb-2">Mungkin maksud Anda:</p>
        <div className="flex flex-wrap gap-2 justify-center">
          {suggestions.map((suggestion, i) => (
            <span
              key={i}
              className="px-3 py-1 bg-blue-50 text-blue-700 rounded-full text-sm cursor-pointer hover:bg-blue-100 transition-colors"
            >
              {suggestion}
            </span>
          ))}
        </div>
      </div>
    )}

    <button
      onClick={onClear}
      className="text-blue-600 hover:text-blue-700 font-medium text-sm"
    >
      Hapus pencarian
    </button>
  </div>
);

// ============================================================
// FILTER EMPTY STATE
// ============================================================

interface FilterEmptyStateProps {
  filterCount: number;
  onClearFilters: () => void;
  className?: string;
}

export const FilterEmptyState: React.FC<FilterEmptyStateProps> = ({
  filterCount,
  onClearFilters,
  className = '',
}) => (
  <div className={`flex flex-col items-center justify-center py-10 px-6 text-center ${className}`}>
    <div className="w-16 h-16 rounded-full bg-orange-50 flex items-center justify-center mb-4">
      <Settings className="w-8 h-8 text-orange-500" />
    </div>
    <h3 className="text-lg font-semibold text-gray-900 mb-2">
      Filter terlalu ketat
    </h3>
    <p className="text-gray-500 max-w-md mb-4">
      {filterCount} filter aktif menghasilkan data kosong. Coba kurangi filter atau gunakan kriteria yang berbeda.
    </p>
    <button
      onClick={onClearFilters}
      className="inline-flex items-center gap-2 px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-colors font-medium"
    >
      <RefreshCw className="w-4 h-4" />
      Reset Filter
    </button>
  </div>
);

// ============================================================
// ERROR EMPTY STATE
// ============================================================

interface ErrorEmptyStateProps {
  error?: Error | string;
  onRetry?: () => void;
  className?: string;
}

export const ErrorEmptyState: React.FC<ErrorEmptyStateProps> = ({
  error,
  onRetry,
  className = '',
}) => {
  const errorMessage = typeof error === 'string'
    ? error
    : error?.message || 'Terjadi kesalahan yang tidak diketahui';

  return (
    <div className={`flex flex-col items-center justify-center py-10 px-6 text-center ${className}`}>
      <div className="w-16 h-16 rounded-full bg-red-50 flex items-center justify-center mb-4">
        <AlertCircle className="w-8 h-8 text-red-500" />
      </div>
      <h3 className="text-lg font-semibold text-gray-900 mb-2">
        Gagal Memuat Data
      </h3>
      <p className="text-gray-500 max-w-md mb-2">
        Maaf, kami tidak dapat memuat data yang Anda minta.
      </p>
      <p className="text-sm text-gray-400 max-w-md mb-4 font-mono bg-gray-50 px-3 py-2 rounded">
        {errorMessage}
      </p>
      {onRetry && (
        <button
          onClick={onRetry}
          className="inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium"
        >
          <RefreshCw className="w-4 h-4" />
          Coba Lagi
        </button>
      )}
    </div>
  );
};

// ============================================================
// OFFLINE EMPTY STATE
// ============================================================

interface OfflineEmptyStateProps {
  onRetry?: () => void;
  className?: string;
}

export const OfflineEmptyState: React.FC<OfflineEmptyStateProps> = ({
  onRetry,
  className = '',
}) => (
  <div className={`flex flex-col items-center justify-center py-10 px-6 text-center ${className}`}>
    <div className="w-16 h-16 rounded-full bg-gray-100 flex items-center justify-center mb-4 relative">
      <WifiOff className="w-8 h-8 text-gray-500" />
      <span className="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full border-2 border-white" />
    </div>
    <h3 className="text-lg font-semibold text-gray-900 mb-2">
      Tidak Ada Koneksi Internet
    </h3>
    <p className="text-gray-500 max-w-md mb-4">
      Periksa koneksi internet Anda dan coba lagi. Beberapa fitur mungkin tersedia secara offline.
    </p>
    {onRetry && (
      <button
        onClick={onRetry}
        className="inline-flex items-center gap-2 px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors font-medium"
      >
        <RefreshCw className="w-4 h-4" />
        Coba Lagi
      </button>
    )}
  </div>
);

export default {
  EmptyState,
  SearchEmptyState,
  FilterEmptyState,
  ErrorEmptyState,
  OfflineEmptyState,
};
