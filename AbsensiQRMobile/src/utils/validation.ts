/**
 * Centralized Validation Library for React Native
 *
 * Provides standardized validation functions for mobile forms.
 * All form validation should use these functions for consistency and security.
 *
 * Features:
 * - Type-safe validation functions
 * - Consistent error messages (Indonesian localized)
 * - Input sanitization helpers
 * - Mobile-specific validations
 */

export interface ValidationResult {
  isValid: boolean;
  error?: string;
}

// ============================================================================
// COMMON PATTERNS (Regex)
// ============================================================================

export const patterns = {
  // Email pattern
  email: /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/,

  // Username: alphanumeric, underscore, dot, 3-30 chars
  username: /^[a-zA-Z0-9_.]{3,30}$/,

  // Password: min 8 chars, at least 1 upper, 1 lower, 1 number
  passwordStrong: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d@$!%*?&]{8,}$/,

  // NISN (Nomor Induk Siswa Nasional): 10 digits
  nisn: /^\d{10}$/,

  // Indonesian phone number
  phoneIndonesia: /^(\+62|62|0)8[1-9][0-9]{7,10}$/,

  // UUID v4
  uuid: /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,

  // Name (letters, spaces, common name characters)
  name: /^[a-zA-Z\s'.,-]+$/,

  // Date format: YYYY-MM-DD
  dateISO: /^\d{4}-\d{2}-\d{2}$/,

  // Time format: HH:MM
  time: /^([01]?[0-9]|2[0-3]):[0-5][0-9]$/,

  // QR Token format
  qrToken: /^[A-Za-z0-9+/=_-]+\.[A-Za-z0-9+/=_-]+$/,

  // Device ID format (alphanumeric with hyphens)
  deviceId: /^[A-Za-z0-9-]+$/,

  // Dangerous characters that could indicate injection
  dangerousChars: /<script|javascript:|eval\(/i,
} as const;

// ============================================================================
// ERROR MESSAGES (Indonesian)
// ============================================================================

export const errorMessages = {
  required: 'Field ini wajib diisi',
  email: 'Format email tidak valid',
  username: 'Username harus 3-30 karakter (huruf, angka, _, .)',
  password:
    'Password minimal 8 karakter dengan huruf besar, huruf kecil, dan angka',
  passwordMismatch: 'Password tidak cocok',
  minLength: (min: number) => `Minimal ${min} karakter`,
  maxLength: (max: number) => `Maksimal ${max} karakter`,
  nisn: 'NISN harus 10 digit angka',
  phone: 'Format nomor telepon tidak valid',
  date: 'Format tanggal tidak valid',
  time: 'Format waktu tidak valid',
  name: 'Nama hanya boleh berisi huruf dan spasi',
  numeric: 'Harus berupa angka',
  qrToken: 'Format QR tidak valid',
  deviceId: 'Format Device ID tidak valid',
  locationRequired: 'Izin lokasi diperlukan untuk absensi',
  locationAccuracy: 'Akurasi lokasi tidak mencukupi',
  dangerousInput: 'Input tidak valid',
} as const;

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Check if value is empty
 */
export const isEmpty = (value: unknown): boolean => {
  if (value === null || value === undefined) {
    return true;
  }
  if (typeof value === 'string') {
    return value.trim() === '';
  }
  if (Array.isArray(value)) {
    return value.length === 0;
  }
  return false;
};

// ============================================================================
// VALIDATION FUNCTIONS
// ============================================================================

/**
 * Required field validation
 */
export const required = (
  value: unknown,
  message = errorMessages.required,
): ValidationResult => {
  const isValid = !isEmpty(value);
  return {isValid, error: isValid ? undefined : message};
};

/**
 * Email validation
 */
export const email = (
  value: string,
  message = errorMessages.email,
): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = patterns.email.test(value);
  return {isValid, error: isValid ? undefined : message};
};

/**
 * Username validation
 */
export const username = (
  value: string,
  message = errorMessages.username,
): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = patterns.username.test(value);
  return {isValid, error: isValid ? undefined : message};
};

/**
 * Strong password validation
 */
export const passwordStrong = (
  value: string,
  message = errorMessages.password,
): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = patterns.passwordStrong.test(value);
  return {isValid, error: isValid ? undefined : message};
};

/**
 * Password confirmation validation
 */
export const passwordMatch = (
  password: string,
  confirmation: string,
  message = errorMessages.passwordMismatch,
): ValidationResult => {
  const isValid = password === confirmation;
  return {isValid, error: isValid ? undefined : message};
};

/**
 * Minimum length validation
 */
export const minLength = (value: string, min: number): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = value.length >= min;
  return {isValid, error: isValid ? undefined : errorMessages.minLength(min)};
};

/**
 * Maximum length validation
 */
export const maxLength = (value: string, max: number): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = value.length <= max;
  return {isValid, error: isValid ? undefined : errorMessages.maxLength(max)};
};

/**
 * NISN validation
 */
export const nisn = (
  value: string,
  message = errorMessages.nisn,
): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = patterns.nisn.test(value);
  return {isValid, error: isValid ? undefined : message};
};

/**
 * Phone validation
 */
export const phoneIndonesia = (
  value: string,
  message = errorMessages.phone,
): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = patterns.phoneIndonesia.test(value.replace(/[\s-]/g, ''));
  return {isValid, error: isValid ? undefined : message};
};

/**
 * Name validation
 */
export const name = (
  value: string,
  message = errorMessages.name,
): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = patterns.name.test(value);
  return {isValid, error: isValid ? undefined : message};
};

/**
 * Date validation
 */
export const dateISO = (
  value: string,
  message = errorMessages.date,
): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  if (!patterns.dateISO.test(value)) {
    return {isValid: false, error: message};
  }
  const date = new Date(value);
  const isValid = !isNaN(date.getTime());
  return {isValid, error: isValid ? undefined : message};
};

/**
 * QR Token validation
 */
export const qrToken = (
  value: string,
  message = errorMessages.qrToken,
): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = patterns.qrToken.test(value);
  return {isValid, error: isValid ? undefined : message};
};

/**
 * Device ID validation
 */
export const deviceId = (
  value: string,
  message = errorMessages.deviceId,
): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = patterns.deviceId.test(value);
  return {isValid, error: isValid ? undefined : message};
};

/**
 * UUID validation
 */
export const uuid = (
  value: string,
  message = 'Format ID tidak valid',
): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isValid = patterns.uuid.test(value);
  return {isValid, error: isValid ? undefined : message};
};

// ============================================================================
// MOBILE-SPECIFIC VALIDATIONS
// ============================================================================

/**
 * Location validation for attendance
 */
export const locationForAttendance = (
  location: {latitude: number; longitude: number; accuracy?: number} | null,
  maxAccuracyMeters = 100,
): ValidationResult => {
  if (!location) {
    return {isValid: false, error: errorMessages.locationRequired};
  }

  if (location.accuracy && location.accuracy > maxAccuracyMeters) {
    return {isValid: false, error: errorMessages.locationAccuracy};
  }

  // Validate coordinate ranges
  if (location.latitude < -90 || location.latitude > 90) {
    return {isValid: false, error: 'Latitude tidak valid'};
  }

  if (location.longitude < -180 || location.longitude > 180) {
    return {isValid: false, error: 'Longitude tidak valid'};
  }

  return {isValid: true};
};

/**
 * Attendance scan payload validation
 */
export const attendanceScanPayload = (payload: {
  qr_token?: string;
  latitude?: number;
  longitude?: number;
  device_id?: string;
}): ValidationResult => {
  // QR Token required
  if (!payload.qr_token) {
    return {isValid: false, error: 'QR Token diperlukan'};
  }

  const qrResult = qrToken(payload.qr_token);
  if (!qrResult.isValid) {
    return qrResult;
  }

  // Location validation if provided
  if (payload.latitude !== undefined && payload.longitude !== undefined) {
    const locationResult = locationForAttendance({
      latitude: payload.latitude,
      longitude: payload.longitude,
    });
    if (!locationResult.isValid) {
      return locationResult;
    }
  }

  // Device ID validation if provided
  if (payload.device_id) {
    const deviceResult = deviceId(payload.device_id);
    if (!deviceResult.isValid) {
      return deviceResult;
    }
  }

  return {isValid: true};
};

// ============================================================================
// SECURITY FUNCTIONS
// ============================================================================

/**
 * Check for potentially dangerous input
 */
export const checkDangerousInput = (value: string): ValidationResult => {
  if (isEmpty(value)) {
    return {isValid: true};
  }
  const isDangerous = patterns.dangerousChars.test(value);
  return {
    isValid: !isDangerous,
    error: isDangerous ? errorMessages.dangerousInput : undefined,
  };
};

/**
 * Sanitize string input
 */
export const sanitizeInput = (value: string): string => {
  if (!value) {
    return value;
  }
  return value
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#x27;');
};

// ============================================================================
// COMPOSITE VALIDATORS
// ============================================================================

/**
 * Required email
 */
export const requiredEmail = (value: string): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) {
    return reqResult;
  }
  return email(value);
};

/**
 * Required username
 */
export const requiredUsername = (value: string): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) {
    return reqResult;
  }
  return username(value);
};

/**
 * Required password
 */
export const requiredPassword = (value: string): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) {
    return reqResult;
  }
  return passwordStrong(value);
};

/**
 * Required name
 */
export const requiredName = (value: string): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) {
    return reqResult;
  }
  const lengthResult = minLength(value, 2);
  if (!lengthResult.isValid) {
    return lengthResult;
  }
  return name(value);
};

// ============================================================================
// DEFAULT EXPORT
// ============================================================================

const validation = {
  patterns,
  errorMessages,
  isEmpty,
  required,
  email,
  username,
  passwordStrong,
  passwordMatch,
  minLength,
  maxLength,
  nisn,
  phoneIndonesia,
  name,
  dateISO,
  qrToken,
  deviceId,
  uuid,
  locationForAttendance,
  attendanceScanPayload,
  checkDangerousInput,
  sanitizeInput,
  requiredEmail,
  requiredUsername,
  requiredPassword,
  requiredName,
};

export default validation;
