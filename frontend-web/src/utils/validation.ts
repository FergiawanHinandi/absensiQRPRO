/**
 * Centralized Validation Library
 * 
 * Provides standardized validation functions and patterns for the entire application.
 * All form validation should use these functions for consistency and security.
 * 
 * Features:
 * - Type-safe validation functions
 * - Consistent error messages (Indonesian localized)
 * - XSS prevention through pattern matching
 * - Input sanitization helpers
 */

export interface ValidationResult {
  isValid: boolean;
  error?: string;
}

export interface ValidationRule {
  validate: (value: unknown) => boolean;
  message: string;
}

// ============================================================================
// COMMON PATTERNS (Regex)
// ============================================================================

export const patterns = {
  // Email pattern (RFC 5322 simplified)
  email: /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/,
  
  // Username: alphanumeric, underscore, dot, 3-30 chars
  username: /^[a-zA-Z0-9_.]{3,30}$/,
  
  // Password: min 8 chars, at least 1 upper, 1 lower, 1 number
  passwordStrong: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d@$!%*?&]{8,}$/,
  
  // NISN (Nomor Induk Siswa Nasional): 10 digits
  nisn: /^\d{10}$/,
  
  // NPSN (Nomor Pokok Sekolah Nasional): 8 digits
  npsn: /^\d{8}$/,
  
  // Indonesian phone number
  phoneIndonesia: /^(\+62|62|0)8[1-9][0-9]{7,10}$/,
  
  // UUID v4
  uuid: /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
  
  // Alphanumeric only
  alphanumeric: /^[a-zA-Z0-9]+$/,
  
  // Name (letters, spaces, common name characters)
  name: /^[a-zA-Z\s'.,-]+$/,
  
  // Indonesian name (includes common characters)
  nameIndonesia: /^[a-zA-Z\s'.,-]+$/,
  
  // Date format: YYYY-MM-DD
  dateISO: /^\d{4}-\d{2}-\d{2}$/,
  
  // Time format: HH:MM or HH:MM:SS
  time: /^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/,
  
  // Latitude: -90 to 90
  latitude: /^-?([0-8]?[0-9]|90)(\.[0-9]{1,8})?$/,
  
  // Longitude: -180 to 180
  longitude: /^-?((1[0-7]|[0-9])?[0-9]|180)(\.[0-9]{1,8})?$/,
  
  // QR Token format
  qrToken: /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/,
  
  // Dangerous characters that could indicate XSS/injection
  dangerousChars: /<script|javascript:|onerror|onload|eval\(|document\.|window\./i,
} as const;

// ============================================================================
// ERROR MESSAGES (Indonesian)
// ============================================================================

export const errorMessages = {
  required: 'Field ini wajib diisi',
  email: 'Format email tidak valid',
  emailInvalid: 'Email tidak valid',
  username: 'Username harus 3-30 karakter (huruf, angka, _, .)',
  password: 'Password minimal 8 karakter dengan huruf besar, huruf kecil, dan angka',
  passwordMismatch: 'Password tidak cocok',
  passwordWeak: 'Password terlalu lemah',
  minLength: (min: number) => `Minimal ${min} karakter`,
  maxLength: (max: number) => `Maksimal ${max} karakter`,
  min: (min: number) => `Nilai minimal ${min}`,
  max: (max: number) => `Nilai maksimal ${max}`,
  pattern: 'Format tidak valid',
  nisn: 'NISN harus 10 digit angka',
  npsn: 'NPSN harus 8 digit angka',
  phone: 'Format nomor telepon tidak valid',
  date: 'Format tanggal tidak valid (YYYY-MM-DD)',
  time: 'Format waktu tidak valid (HH:MM)',
  name: 'Nama hanya boleh berisi huruf dan spasi',
  numeric: 'Harus berupa angka',
  integer: 'Harus berupa bilangan bulat',
  positive: 'Harus berupa bilangan positif',
  alphanumeric: 'Hanya boleh huruf dan angka',
  uuid: 'Format ID tidak valid',
  url: 'Format URL tidak valid',
  latitude: 'Latitude harus antara -90 dan 90',
  longitude: 'Longitude harus antara -180 dan 180',
  dangerousInput: 'Input mengandung karakter berbahaya',
  fileTooLarge: (maxMB: number) => `Ukuran file maksimal ${maxMB}MB`,
  fileTypeInvalid: 'Tipe file tidak diizinkan',
  selectRequired: 'Pilih salah satu opsi',
} as const;

// ============================================================================
// VALIDATION FUNCTIONS
// ============================================================================

/**
 * Check if value is empty (null, undefined, empty string, empty array)
 */
export const isEmpty = (value: unknown): boolean => {
  if (value === null || value === undefined) return true;
  if (typeof value === 'string') return value.trim() === '';
  if (Array.isArray(value)) return value.length === 0;
  if (typeof value === 'object') return Object.keys(value).length === 0;
  return false;
};

/**
 * Required field validation
 */
export const required = (value: unknown, message = errorMessages.required): ValidationResult => {
  const isValid = !isEmpty(value);
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Email validation
 */
export const email = (value: string, message = errorMessages.email): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = patterns.email.test(value);
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Username validation
 */
export const username = (value: string, message = errorMessages.username): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = patterns.username.test(value);
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Strong password validation
 */
export const passwordStrong = (value: string, message = errorMessages.password): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = patterns.passwordStrong.test(value);
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Password confirmation validation
 */
export const passwordMatch = (
  password: string,
  confirmation: string,
  message = errorMessages.passwordMismatch
): ValidationResult => {
  const isValid = password === confirmation;
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Minimum length validation
 */
export const minLength = (value: string, min: number): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = value.length >= min;
  return { isValid, error: isValid ? undefined : errorMessages.minLength(min) };
};

/**
 * Maximum length validation
 */
export const maxLength = (value: string, max: number): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = value.length <= max;
  return { isValid, error: isValid ? undefined : errorMessages.maxLength(max) };
};

/**
 * Length range validation
 */
export const lengthRange = (value: string, min: number, max: number): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  if (value.length < min) return { isValid: false, error: errorMessages.minLength(min) };
  if (value.length > max) return { isValid: false, error: errorMessages.maxLength(max) };
  return { isValid: true };
};

/**
 * Minimum number validation
 */
export const minValue = (value: number, min: number): ValidationResult => {
  const isValid = value >= min;
  return { isValid, error: isValid ? undefined : errorMessages.min(min) };
};

/**
 * Maximum number validation
 */
export const maxValue = (value: number, max: number): ValidationResult => {
  const isValid = value <= max;
  return { isValid, error: isValid ? undefined : errorMessages.max(max) };
};

/**
 * NISN validation (10 digits)
 */
export const nisn = (value: string, message = errorMessages.nisn): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = patterns.nisn.test(value);
  return { isValid, error: isValid ? undefined : message };
};

/**
 * NPSN validation (8 digits)
 */
export const npsn = (value: string, message = errorMessages.npsn): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = patterns.npsn.test(value);
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Indonesian phone number validation
 */
export const phoneIndonesia = (value: string, message = errorMessages.phone): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = patterns.phoneIndonesia.test(value.replace(/[\s-]/g, ''));
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Name validation (Indonesian names)
 */
export const name = (value: string, message = errorMessages.name): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = patterns.nameIndonesia.test(value);
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Date validation (ISO format: YYYY-MM-DD)
 */
export const dateISO = (value: string, message = errorMessages.date): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  if (!patterns.dateISO.test(value)) {
    return { isValid: false, error: message };
  }
  // Validate actual date
  const date = new Date(value);
  const isValid = !isNaN(date.getTime());
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Time validation (HH:MM or HH:MM:SS)
 */
export const time = (value: string, message = errorMessages.time): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = patterns.time.test(value);
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Numeric validation
 */
export const numeric = (value: string, message = errorMessages.numeric): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = !isNaN(Number(value));
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Integer validation
 */
export const integer = (value: string, message = errorMessages.integer): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = Number.isInteger(Number(value));
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Positive number validation
 */
export const positive = (value: number, message = errorMessages.positive): ValidationResult => {
  const isValid = value > 0;
  return { isValid, error: isValid ? undefined : message };
};

/**
 * UUID validation
 */
export const uuid = (value: string, message = errorMessages.uuid): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = patterns.uuid.test(value);
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Latitude validation
 */
export const latitude = (value: number, message = errorMessages.latitude): ValidationResult => {
  const isValid = value >= -90 && value <= 90;
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Longitude validation
 */
export const longitude = (value: number, message = errorMessages.longitude): ValidationResult => {
  const isValid = value >= -180 && value <= 180;
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Custom pattern validation
 */
export const pattern = (value: string, regex: RegExp, message = errorMessages.pattern): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isValid = regex.test(value);
  return { isValid, error: isValid ? undefined : message };
};

// ============================================================================
// SECURITY FUNCTIONS
// ============================================================================

/**
 * Check for potentially dangerous input (XSS prevention)
 */
export const checkDangerousInput = (value: string): ValidationResult => {
  if (isEmpty(value)) return { isValid: true };
  const isDangerous = patterns.dangerousChars.test(value);
  return { isValid: !isDangerous, error: isDangerous ? errorMessages.dangerousInput : undefined };
};

/**
 * Sanitize string input (removes dangerous characters but keeps the string usable)
 */
export const sanitizeInput = (value: string): string => {
  if (!value) return value;
  return value
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#x27;')
    .replace(/\//g, '&#x2F;');
};

/**
 * Sanitize string for SQL-like contexts (escape special characters)
 */
export const sanitizeForSearch = (value: string): string => {
  if (!value) return value;
  return value.replace(/[%_\\]/g, '\\$&');
};

// ============================================================================
// FILE VALIDATION
// ============================================================================

const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const ALLOWED_DOCUMENT_TYPES = [
  'application/pdf',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

/**
 * File size validation
 */
export const fileSize = (file: File, maxSizeMB: number): ValidationResult => {
  const maxSizeBytes = maxSizeMB * 1024 * 1024;
  const isValid = file.size <= maxSizeBytes;
  return { isValid, error: isValid ? undefined : errorMessages.fileTooLarge(maxSizeMB) };
};

/**
 * Image file type validation
 */
export const isImageFile = (file: File, message = errorMessages.fileTypeInvalid): ValidationResult => {
  const isValid = ALLOWED_IMAGE_TYPES.includes(file.type);
  return { isValid, error: isValid ? undefined : message };
};

/**
 * Document file type validation
 */
export const isDocumentFile = (file: File, message = errorMessages.fileTypeInvalid): ValidationResult => {
  const isValid = ALLOWED_DOCUMENT_TYPES.includes(file.type);
  return { isValid, error: isValid ? undefined : message };
};

// ============================================================================
// COMPOSITE VALIDATORS
// ============================================================================

/**
 * Run multiple validation rules on a value
 * Returns first error found or valid result
 */
export const validate = (value: unknown, rules: ValidationRule[]): ValidationResult => {
  for (const rule of rules) {
    if (!rule.validate(value)) {
      return { isValid: false, error: rule.message };
    }
  }
  return { isValid: true };
};

/**
 * Create a validator function with predefined rules
 */
export const createValidator = (rules: ValidationRule[]) => {
  return (value: unknown): ValidationResult => validate(value, rules);
};

// ============================================================================
// FORM FIELD VALIDATORS (Common combinations)
// ============================================================================

/**
 * Required email field
 */
export const requiredEmail = (value: string): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) return reqResult;
  return email(value);
};

/**
 * Required username field
 */
export const requiredUsername = (value: string): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) return reqResult;
  return username(value);
};

/**
 * Required password field with strength check
 */
export const requiredPassword = (value: string): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) return reqResult;
  return passwordStrong(value);
};

/**
 * Required name field
 */
export const requiredName = (value: string, min = 2, max = 100): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) return reqResult;
  const lengthResult = lengthRange(value, min, max);
  if (!lengthResult.isValid) return lengthResult;
  return name(value);
};

/**
 * Required NISN field
 */
export const requiredNISN = (value: string): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) return reqResult;
  return nisn(value);
};

/**
 * Required phone field (Indonesian)
 */
export const requiredPhone = (value: string): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) return reqResult;
  return phoneIndonesia(value);
};

/**
 * Required date field
 */
export const requiredDate = (value: string): ValidationResult => {
  const reqResult = required(value);
  if (!reqResult.isValid) return reqResult;
  return dateISO(value);
};

/**
 * Required select field
 */
export const requiredSelect = (value: string | number | null): ValidationResult => {
  const isValid = value !== null && value !== undefined && value !== '' && value !== 0;
  return { isValid, error: isValid ? undefined : errorMessages.selectRequired };
};

// ============================================================================
// REACT HOOK FORM ADAPTERS
// ============================================================================

/**
 * Adapter for React Hook Form's validate function
 * Usage: register('email', { validate: rhfValidate(email) })
 */
export const rhfValidate = (validationFn: (value: string) => ValidationResult) => {
  return (value: string): string | true => {
    const result = validationFn(value);
    return result.isValid ? true : (result.error || 'Validation failed');
  };
};

/**
 * Combine multiple validators for React Hook Form
 * Usage: register('email', { validate: rhfValidateAll([required, email]) })
 */
export const rhfValidateAll = (validators: Array<(value: string) => ValidationResult>) => {
  return (value: string): string | true => {
    for (const validator of validators) {
      const result = validator(value);
      if (!result.isValid) {
        return result.error || 'Validation failed';
      }
    }
    return true;
  };
};

// ============================================================================
// DEFAULT EXPORT
// ============================================================================

const validation = {
  // Patterns
  patterns,
  errorMessages,
  
  // Core validators
  isEmpty,
  required,
  email,
  username,
  passwordStrong,
  passwordMatch,
  minLength,
  maxLength,
  lengthRange,
  minValue,
  maxValue,
  nisn,
  npsn,
  phoneIndonesia,
  name,
  dateISO,
  time,
  numeric,
  integer,
  positive,
  uuid,
  latitude,
  longitude,
  pattern,
  
  // Security
  checkDangerousInput,
  sanitizeInput,
  sanitizeForSearch,
  
  // Files
  fileSize,
  isImageFile,
  isDocumentFile,
  
  // Composites
  validate,
  createValidator,
  requiredEmail,
  requiredUsername,
  requiredPassword,
  requiredName,
  requiredNISN,
  requiredPhone,
  requiredDate,
  requiredSelect,
  
  // React Hook Form
  rhfValidate,
  rhfValidateAll,
};

export default validation;
