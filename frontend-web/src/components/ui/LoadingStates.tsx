import React from 'react';
import { Loader2, RefreshCw, Check } from 'lucide-react';

/**
 * Comprehensive Loading State Components
 * Provides consistent loading states across the application
 */

// ============================================================
// LOADING SPINNER VARIANTS
// ============================================================

export type SpinnerSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';
export type SpinnerVariant = 'primary' | 'secondary' | 'white' | 'success' | 'danger';

const spinnerSizes: Record<SpinnerSize, string> = {
  xs: 'w-3 h-3',
  sm: 'w-4 h-4',
  md: 'w-6 h-6',
  lg: 'w-8 h-8',
  xl: 'w-12 h-12',
};

const spinnerColors: Record<SpinnerVariant, string> = {
  primary: 'text-blue-600',
  secondary: 'text-gray-500',
  white: 'text-white',
  success: 'text-green-600',
  danger: 'text-red-600',
};

interface SpinnerProps {
  size?: SpinnerSize;
  variant?: SpinnerVariant;
  className?: string;
}

export const Spinner: React.FC<SpinnerProps> = ({
  size = 'md',
  variant = 'primary',
  className = '',
}) => (
  <Loader2
    className={`animate-spin ${spinnerSizes[size]} ${spinnerColors[variant]} ${className}`}
    aria-hidden="true"
  />
);

// ============================================================
// DOTS LOADING ANIMATION
// ============================================================

interface DotsLoaderProps {
  size?: 'sm' | 'md' | 'lg';
  variant?: SpinnerVariant;
}

export const DotsLoader: React.FC<DotsLoaderProps> = ({
  size = 'md',
  variant = 'primary',
}) => {
  const dotSizes = { sm: 'w-1.5 h-1.5', md: 'w-2 h-2', lg: 'w-3 h-3' };
  const dotColors: Record<SpinnerVariant, string> = {
    primary: 'bg-blue-600',
    secondary: 'bg-gray-500',
    white: 'bg-white',
    success: 'bg-green-600',
    danger: 'bg-red-600',
  };

  return (
    <div className="flex items-center gap-1" role="status" aria-label="Memuat">
      {[0, 1, 2].map((i) => (
        <div
          key={i}
          className={`${dotSizes[size]} ${dotColors[variant]} rounded-full animate-bounce`}
          style={{ animationDelay: `${i * 0.15}s` }}
        />
      ))}
    </div>
  );
};

// ============================================================
// PULSE LOADER (for content placeholders)
// ============================================================

export const PulseLoader: React.FC<{ className?: string }> = ({ className = '' }) => (
  <div className={`animate-pulse ${className}`} role="status" aria-label="Memuat konten">
    <span className="sr-only">Memuat...</span>
  </div>
);

// ============================================================
// SKELETON LOADERS
// ============================================================

interface SkeletonProps {
  className?: string;
  variant?: 'text' | 'circular' | 'rectangular' | 'rounded';
  width?: string | number;
  height?: string | number;
  animation?: 'pulse' | 'wave' | 'none';
}

export const Skeleton: React.FC<SkeletonProps> = ({
  className = '',
  variant = 'text',
  width,
  height,
  animation = 'pulse',
}) => {
  const variantClasses = {
    text: 'rounded h-4',
    circular: 'rounded-full',
    rectangular: '',
    rounded: 'rounded-lg',
  };

  const animationClasses = {
    pulse: 'animate-pulse',
    wave: 'animate-shimmer',
    none: '',
  };

  const style: React.CSSProperties = {
    width: typeof width === 'number' ? `${width}px` : width,
    height: typeof height === 'number' ? `${height}px` : height,
  };

  return (
    <div
      className={`bg-gray-200 ${variantClasses[variant]} ${animationClasses[animation]} ${className}`}
      style={style}
      role="status"
      aria-label="Memuat..."
    />
  );
};

// Preset skeleton components
export const SkeletonText: React.FC<{ lines?: number; className?: string }> = ({
  lines = 3,
  className = '',
}) => (
  <div className={`space-y-2 ${className}`}>
    {Array.from({ length: lines }).map((_, i) => (
      <Skeleton
        key={i}
        variant="text"
        width={i === lines - 1 ? '60%' : '100%'}
        height={16}
      />
    ))}
  </div>
);

export const SkeletonCard: React.FC<{ className?: string }> = ({ className = '' }) => (
  <div className={`bg-white rounded-lg p-4 shadow-sm ${className}`}>
    <div className="flex items-center gap-4 mb-4">
      <Skeleton variant="circular" width={48} height={48} />
      <div className="flex-1">
        <Skeleton variant="text" width="40%" height={20} className="mb-2" />
        <Skeleton variant="text" width="60%" height={14} />
      </div>
    </div>
    <SkeletonText lines={2} />
  </div>
);

export const SkeletonTable: React.FC<{ rows?: number; cols?: number }> = ({
  rows = 5,
  cols = 4,
}) => (
  <div className="bg-white rounded-lg overflow-hidden">
    <div className="bg-gray-50 px-4 py-3 flex gap-4">
      {Array.from({ length: cols }).map((_, i) => (
        <Skeleton key={i} variant="text" width="20%" height={16} />
      ))}
    </div>
    <div className="divide-y divide-gray-100">
      {Array.from({ length: rows }).map((_, row) => (
        <div key={row} className="px-4 py-3 flex gap-4">
          {Array.from({ length: cols }).map((_, col) => (
            <Skeleton key={col} variant="text" width="20%" height={16} />
          ))}
        </div>
      ))}
    </div>
  </div>
);

export const SkeletonDashboard: React.FC = () => (
  <div className="space-y-6">
    {/* Stats row */}
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
      {[1, 2, 3, 4].map((i) => (
        <div key={i} className="bg-white rounded-lg p-4 shadow-sm">
          <Skeleton variant="text" width="40%" height={14} className="mb-2" />
          <Skeleton variant="text" width="60%" height={32} />
        </div>
      ))}
    </div>
    {/* Main content */}
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div className="lg:col-span-2">
        <SkeletonCard className="h-64" />
      </div>
      <div>
        <SkeletonCard className="h-64" />
      </div>
    </div>
  </div>
);

// ============================================================
// PAGE LOADING OVERLAY
// ============================================================

interface PageLoaderProps {
  message?: string;
  show?: boolean;
}

export const PageLoader: React.FC<PageLoaderProps> = ({
  message = 'Memuat halaman...',
  show = true,
}) => {
  if (!show) return null;

  return (
    <div
      className="fixed inset-0 bg-white/90 backdrop-blur-sm z-50 flex flex-col items-center justify-center transition-opacity duration-300"
      role="dialog"
      aria-modal="true"
      aria-label={message}
    >
      <div className="relative">
        {/* Outer ring */}
        <div className="w-16 h-16 border-4 border-blue-100 rounded-full" />
        {/* Spinning ring */}
        <div className="absolute inset-0 w-16 h-16 border-4 border-transparent border-t-blue-600 rounded-full animate-spin" />
      </div>
      <p className="mt-4 text-gray-600 font-medium animate-pulse">{message}</p>
    </div>
  );
};

// ============================================================
// INLINE LOADING STATES
// ============================================================

interface InlineLoaderProps {
  text?: string;
  size?: SpinnerSize;
}

export const InlineLoader: React.FC<InlineLoaderProps> = ({
  text = 'Memuat...',
  size = 'sm',
}) => (
  <span className="inline-flex items-center gap-2 text-gray-500" role="status">
    <Spinner size={size} variant="secondary" />
    <span className="text-sm">{text}</span>
  </span>
);

// ============================================================
// BUTTON LOADING STATE
// ============================================================

interface ButtonLoaderProps {
  loading: boolean;
  loadingText?: string;
  children: React.ReactNode;
  successState?: boolean;
  successText?: string;
}

export const ButtonLoader: React.FC<ButtonLoaderProps> = ({
  loading,
  loadingText = 'Memproses...',
  children,
  successState = false,
  successText = 'Berhasil!',
}) => {
  if (successState) {
    return (
      <span className="inline-flex items-center gap-2">
        <Check className="w-4 h-4 text-green-500 animate-scale-in" />
        <span>{successText}</span>
      </span>
    );
  }

  if (loading) {
    return (
      <span className="inline-flex items-center gap-2">
        <Spinner size="sm" variant="white" />
        <span>{loadingText}</span>
      </span>
    );
  }

  return <>{children}</>;
};

// ============================================================
// REFRESH/RELOAD INDICATOR
// ============================================================

interface RefreshIndicatorProps {
  refreshing: boolean;
  onRefresh?: () => void;
}

export const RefreshIndicator: React.FC<RefreshIndicatorProps> = ({
  refreshing,
  onRefresh,
}) => (
  <button
    onClick={onRefresh}
    disabled={refreshing}
    className={`p-2 rounded-full transition-all ${
      refreshing
        ? 'bg-blue-100 cursor-not-allowed'
        : 'hover:bg-gray-100 active:bg-gray-200'
    }`}
    aria-label={refreshing ? 'Menyegarkan data...' : 'Segarkan data'}
  >
    <RefreshCw
      className={`w-5 h-5 text-gray-600 transition-transform ${
        refreshing ? 'animate-spin' : ''
      }`}
    />
  </button>
);

// ============================================================
// PROGRESS INDICATOR
// ============================================================

interface ProgressProps {
  value: number;
  max?: number;
  showValue?: boolean;
  size?: 'sm' | 'md' | 'lg';
  variant?: 'primary' | 'success' | 'warning' | 'danger';
  className?: string;
}

export const Progress: React.FC<ProgressProps> = ({
  value,
  max = 100,
  showValue = false,
  size = 'md',
  variant = 'primary',
  className = '',
}) => {
  const percentage = Math.min(Math.max((value / max) * 100, 0), 100);

  const heights = { sm: 'h-1', md: 'h-2', lg: 'h-3' };
  const colors = {
    primary: 'bg-blue-600',
    success: 'bg-green-600',
    warning: 'bg-yellow-500',
    danger: 'bg-red-600',
  };

  return (
    <div className={className}>
      <div
        className={`w-full bg-gray-200 rounded-full overflow-hidden ${heights[size]}`}
        role="progressbar"
        aria-valuenow={value}
        aria-valuemin={0}
        aria-valuemax={max}
      >
        <div
          className={`${colors[variant]} ${heights[size]} transition-all duration-500 ease-out`}
          style={{ width: `${percentage}%` }}
        />
      </div>
      {showValue && (
        <p className="text-xs text-gray-500 mt-1 text-right">
          {Math.round(percentage)}%
        </p>
      )}
    </div>
  );
};

// ============================================================
// LOADING WRAPPER (HOC Pattern)
// ============================================================

interface LoadingWrapperProps {
  loading: boolean;
  error?: Error | null;
  onRetry?: () => void;
  loadingComponent?: React.ReactNode;
  errorComponent?: React.ReactNode;
  children: React.ReactNode;
}

export const LoadingWrapper: React.FC<LoadingWrapperProps> = ({
  loading,
  error,
  onRetry,
  loadingComponent,
  errorComponent,
  children,
}) => {
  if (loading) {
    return <>{loadingComponent || <SkeletonCard />}</>;
  }

  if (error) {
    return (
      <>
        {errorComponent || (
          <div className="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
            <p className="text-red-700 mb-4">{error.message}</p>
            {onRetry && (
              <button
                onClick={onRetry}
                className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors"
              >
                Coba Lagi
              </button>
            )}
          </div>
        )}
      </>
    );
  }

  return <>{children}</>;
};

export default {
  Spinner,
  DotsLoader,
  PulseLoader,
  Skeleton,
  SkeletonText,
  SkeletonCard,
  SkeletonTable,
  SkeletonDashboard,
  PageLoader,
  InlineLoader,
  ButtonLoader,
  RefreshIndicator,
  Progress,
  LoadingWrapper,
};
