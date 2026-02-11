/**
 * Error Message Improvements
 * Provides user-friendly error messages with Indonesian localization
 */

// ============================================================
// ERROR MESSAGE MAPPING
// ============================================================

export type ErrorCode = 
  | 'NETWORK_ERROR'
  | 'TIMEOUT'
  | 'UNAUTHORIZED'
  | 'FORBIDDEN'
  | 'NOT_FOUND'
  | 'VALIDATION_ERROR'
  | 'SERVER_ERROR'
  | 'MAINTENANCE'
  | 'RATE_LIMITED'
  | 'SESSION_EXPIRED'
  | 'DUPLICATE_ENTRY'
  | 'INVALID_CREDENTIALS'
  | 'ACCOUNT_LOCKED'
  | 'ACCOUNT_INACTIVE'
  | 'QR_EXPIRED'
  | 'QR_INVALID'
  | 'OUTSIDE_SCHEDULE'
  | 'OUTSIDE_RADIUS'
  | 'ALREADY_ATTENDED'
  | 'DEVICE_NOT_APPROVED'
  | 'GPS_REQUIRED'
  | 'UNKNOWN';

interface ErrorMessageConfig {
  title: string;
  message: string;
  suggestion?: string;
  retryable: boolean;
  severity: 'info' | 'warning' | 'error' | 'critical';
}

const errorMessages: Record<ErrorCode, ErrorMessageConfig> = {
  NETWORK_ERROR: {
    title: 'Masalah Koneksi',
    message: 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.',
    suggestion: 'Pastikan WiFi atau data seluler aktif, lalu coba lagi.',
    retryable: true,
    severity: 'warning',
  },
  TIMEOUT: {
    title: 'Waktu Habis',
    message: 'Permintaan membutuhkan waktu terlalu lama.',
    suggestion: 'Server mungkin sibuk. Tunggu sebentar, lalu coba lagi.',
    retryable: true,
    severity: 'warning',
  },
  UNAUTHORIZED: {
    title: 'Sesi Berakhir',
    message: 'Sesi login Anda telah berakhir.',
    suggestion: 'Silakan login kembali untuk melanjutkan.',
    retryable: false,
    severity: 'info',
  },
  FORBIDDEN: {
    title: 'Akses Ditolak',
    message: 'Anda tidak memiliki izin untuk melakukan tindakan ini.',
    suggestion: 'Hubungi administrator jika Anda merasa ini adalah kesalahan.',
    retryable: false,
    severity: 'error',
  },
  NOT_FOUND: {
    title: 'Tidak Ditemukan',
    message: 'Data yang Anda cari tidak ditemukan atau sudah dihapus.',
    suggestion: 'Periksa kembali atau kembali ke halaman sebelumnya.',
    retryable: false,
    severity: 'warning',
  },
  VALIDATION_ERROR: {
    title: 'Data Tidak Valid',
    message: 'Beberapa data yang Anda masukkan tidak sesuai format.',
    suggestion: 'Periksa kembali form dan perbaiki data yang salah.',
    retryable: false,
    severity: 'warning',
  },
  SERVER_ERROR: {
    title: 'Kesalahan Server',
    message: 'Terjadi kesalahan pada server kami.',
    suggestion: 'Tim teknis sudah diberitahu. Silakan coba lagi nanti.',
    retryable: true,
    severity: 'error',
  },
  MAINTENANCE: {
    title: 'Sedang Pemeliharaan',
    message: 'Sistem sedang dalam pemeliharaan terjadwal.',
    suggestion: 'Silakan coba lagi dalam beberapa menit.',
    retryable: true,
    severity: 'info',
  },
  RATE_LIMITED: {
    title: 'Terlalu Banyak Permintaan',
    message: 'Anda telah melakukan terlalu banyak permintaan.',
    suggestion: 'Tunggu beberapa saat sebelum mencoba lagi.',
    retryable: true,
    severity: 'warning',
  },
  SESSION_EXPIRED: {
    title: 'Sesi Kadaluarsa',
    message: 'Sesi Anda telah berakhir karena tidak ada aktivitas.',
    suggestion: 'Login kembali untuk melanjutkan.',
    retryable: false,
    severity: 'info',
  },
  DUPLICATE_ENTRY: {
    title: 'Data Sudah Ada',
    message: 'Data yang Anda masukkan sudah terdaftar sebelumnya.',
    suggestion: 'Gunakan data yang berbeda atau periksa data yang sudah ada.',
    retryable: false,
    severity: 'warning',
  },
  INVALID_CREDENTIALS: {
    title: 'Login Gagal',
    message: 'Username atau password yang Anda masukkan salah.',
    suggestion: 'Periksa kembali kredensial Anda. Gunakan "Lupa Password" jika perlu.',
    retryable: false,
    severity: 'error',
  },
  ACCOUNT_LOCKED: {
    title: 'Akun Terkunci',
    message: 'Akun Anda telah dikunci karena terlalu banyak percobaan login gagal.',
    suggestion: 'Hubungi administrator untuk membuka kunci akun.',
    retryable: false,
    severity: 'critical',
  },
  ACCOUNT_INACTIVE: {
    title: 'Akun Tidak Aktif',
    message: 'Akun Anda saat ini tidak aktif.',
    suggestion: 'Hubungi administrator sekolah untuk mengaktifkan akun.',
    retryable: false,
    severity: 'warning',
  },
  QR_EXPIRED: {
    title: 'QR Code Kadaluarsa',
    message: 'QR Code yang Anda scan sudah tidak berlaku.',
    suggestion: 'Minta guru untuk menampilkan QR Code yang baru.',
    retryable: false,
    severity: 'warning',
  },
  QR_INVALID: {
    title: 'QR Code Tidak Valid',
    message: 'QR Code yang Anda scan tidak dapat dikenali.',
    suggestion: 'Pastikan Anda scan QR Code absensi yang benar.',
    retryable: false,
    severity: 'error',
  },
  OUTSIDE_SCHEDULE: {
    title: 'Di Luar Jadwal',
    message: 'Absensi hanya dapat dilakukan pada jam pelajaran.',
    suggestion: 'Periksa jadwal pelajaran Anda.',
    retryable: false,
    severity: 'warning',
  },
  OUTSIDE_RADIUS: {
    title: 'Di Luar Area',
    message: 'Anda berada di luar area yang diizinkan untuk absensi.',
    suggestion: 'Pastikan Anda berada di dalam area sekolah.',
    retryable: true,
    severity: 'warning',
  },
  ALREADY_ATTENDED: {
    title: 'Sudah Absen',
    message: 'Anda sudah melakukan absensi untuk sesi ini.',
    suggestion: 'Tidak perlu melakukan absensi lagi.',
    retryable: false,
    severity: 'info',
  },
  DEVICE_NOT_APPROVED: {
    title: 'Perangkat Tidak Dikenal',
    message: 'Perangkat ini belum terdaftar untuk akun Anda.',
    suggestion: 'Hubungi administrator untuk mendaftarkan perangkat.',
    retryable: false,
    severity: 'warning',
  },
  GPS_REQUIRED: {
    title: 'GPS Diperlukan',
    message: 'Izin lokasi diperlukan untuk melakukan absensi.',
    suggestion: 'Aktifkan GPS dan izinkan akses lokasi untuk aplikasi ini.',
    retryable: true,
    severity: 'warning',
  },
  UNKNOWN: {
    title: 'Terjadi Kesalahan',
    message: 'Maaf, terjadi kesalahan yang tidak terduga.',
    suggestion: 'Coba muat ulang halaman atau hubungi dukungan teknis.',
    retryable: true,
    severity: 'error',
  },
};

// ============================================================
// ERROR PARSING UTILITIES
// ============================================================

interface ParsedError {
  code: ErrorCode;
  config: ErrorMessageConfig;
  originalMessage?: string;
  details?: Record<string, string[]>;
}

/**
 * Parse HTTP status code to error code
 */
export function httpStatusToErrorCode(status: number): ErrorCode {
  switch (status) {
    case 401:
      return 'UNAUTHORIZED';
    case 403:
      return 'FORBIDDEN';
    case 404:
      return 'NOT_FOUND';
    case 422:
      return 'VALIDATION_ERROR';
    case 429:
      return 'RATE_LIMITED';
    case 500:
    case 502:
    case 504:
      return 'SERVER_ERROR';
    case 503:
      return 'MAINTENANCE';
    default:
      return 'UNKNOWN';
  }
}

/**
 * Parse API error response to user-friendly format
 */
export function parseApiError(error: any): ParsedError {
  // Handle Axios errors
  if (error?.response) {
    const status = error.response.status;
    const data = error.response.data;

    // Check for specific error codes from API
    if (data?.error_code) {
      const code = data.error_code.toUpperCase() as ErrorCode;
      if (errorMessages[code]) {
        return {
          code,
          config: errorMessages[code],
          originalMessage: data.message,
          details: data.errors,
        };
      }
    }

    // Map HTTP status
    const code = httpStatusToErrorCode(status);
    return {
      code,
      config: errorMessages[code],
      originalMessage: data?.message || error.message,
      details: data?.errors,
    };
  }

  // Handle network errors
  if (error?.code === 'ERR_NETWORK' || error?.message === 'Network Error') {
    return {
      code: 'NETWORK_ERROR',
      config: errorMessages.NETWORK_ERROR,
    };
  }

  // Handle timeout
  if (error?.code === 'ECONNABORTED' || error?.message?.includes('timeout')) {
    return {
      code: 'TIMEOUT',
      config: errorMessages.TIMEOUT,
    };
  }

  // Unknown error
  return {
    code: 'UNKNOWN',
    config: errorMessages.UNKNOWN,
    originalMessage: error?.message,
  };
}

/**
 * Get user-friendly error message
 */
export function getErrorMessage(error: any): string {
  const parsed = parseApiError(error);
  return parsed.config.message;
}

/**
 * Get full error info for display
 */
export function getErrorInfo(error: any): ErrorMessageConfig & { 
  details?: Record<string, string[]>;
  originalMessage?: string;
} {
  const parsed = parseApiError(error);
  return {
    ...parsed.config,
    details: parsed.details,
    originalMessage: parsed.originalMessage,
  };
}

/**
 * Check if error is retryable
 */
export function isRetryableError(error: any): boolean {
  const parsed = parseApiError(error);
  return parsed.config.retryable;
}

// ============================================================
// VALIDATION ERROR HELPERS
// ============================================================

interface ValidationErrors {
  [field: string]: string[];
}

/**
 * Format validation errors for display
 */
export function formatValidationErrors(errors: ValidationErrors): string[] {
  const messages: string[] = [];
  
  for (const [field, fieldErrors] of Object.entries(errors)) {
    fieldErrors.forEach(err => {
      messages.push(translateFieldError(field, err));
    });
  }
  
  return messages;
}

/**
 * Translate field name and error to Indonesian
 */
function translateFieldError(field: string, error: string): string {
  const fieldNames: Record<string, string> = {
    name: 'Nama',
    email: 'Email',
    password: 'Password',
    username: 'Username',
    phone: 'Nomor Telepon',
    nisn: 'NISN',
    nip: 'NIP',
    class_id: 'Kelas',
    subject_id: 'Mata Pelajaran',
    schedule_id: 'Jadwal',
    date: 'Tanggal',
    time: 'Waktu',
    latitude: 'Lokasi',
    longitude: 'Lokasi',
    qr_token: 'QR Code',
    device_id: 'Perangkat',
  };

  const translatedField = fieldNames[field] || field;
  
  // Common error patterns
  if (error.includes('required')) {
    return `${translatedField} wajib diisi`;
  }
  if (error.includes('unique') || error.includes('already been taken')) {
    return `${translatedField} sudah digunakan`;
  }
  if (error.includes('email')) {
    return `Format ${translatedField} tidak valid`;
  }
  if (error.includes('min:')) {
    const min = error.match(/min:(\d+)/)?.[1] || '';
    return `${translatedField} minimal ${min} karakter`;
  }
  if (error.includes('max:')) {
    const max = error.match(/max:(\d+)/)?.[1] || '';
    return `${translatedField} maksimal ${max} karakter`;
  }
  if (error.includes('numeric')) {
    return `${translatedField} harus berupa angka`;
  }
  if (error.includes('date')) {
    return `Format ${translatedField} tidak valid`;
  }

  return `${translatedField}: ${error}`;
}

// ============================================================
// ERROR MESSAGE COMPONENT PROPS HELPER
// ============================================================

export interface ErrorDisplayProps {
  title: string;
  message: string;
  suggestion?: string;
  severity: 'info' | 'warning' | 'error' | 'critical';
  retryable: boolean;
  details?: string[];
  onRetry?: () => void;
  onDismiss?: () => void;
}

/**
 * Get props for error display component
 */
export function getErrorDisplayProps(
  error: any,
  onRetry?: () => void,
  onDismiss?: () => void
): ErrorDisplayProps {
  const parsed = parseApiError(error);
  
  return {
    title: parsed.config.title,
    message: parsed.config.message,
    suggestion: parsed.config.suggestion,
    severity: parsed.config.severity,
    retryable: parsed.config.retryable,
    details: parsed.details ? formatValidationErrors(parsed.details) : undefined,
    onRetry: parsed.config.retryable ? onRetry : undefined,
    onDismiss,
  };
}

export default {
  parseApiError,
  getErrorMessage,
  getErrorInfo,
  isRetryableError,
  formatValidationErrors,
  getErrorDisplayProps,
  httpStatusToErrorCode,
};
