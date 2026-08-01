import {
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
  isEmpty,
  patterns,
  errorMessages,
} from '../validation';
import validation from '../validation';

// ============================================================================
// Helper: convert base64 (Node polyfill for btoa)
// ============================================================================
const toBase64 = (str: string): string => Buffer.from(str).toString('base64');

// ============================================================================
// TESTS
// ============================================================================

describe('Mobile Validation Utilities', () => {
  describe('isEmpty', () => {
    it('should return true for null, undefined, empty string', () => {
      expect(isEmpty(null)).toBe(true);
      expect(isEmpty(undefined)).toBe(true);
      expect(isEmpty('')).toBe(true);
      expect(isEmpty('   ')).toBe(true);
    });

    it('should return false for non-empty values', () => {
      expect(isEmpty('hello')).toBe(false);
      expect(isEmpty(0)).toBe(false);
      expect(isEmpty([1])).toBe(false);
    });

    it('should return true for empty array', () => {
      expect(isEmpty([])).toBe(true);
    });
  });

  describe('required', () => {
    it('should fail for empty values', () => {
      expect(required('').isValid).toBe(false);
      expect(required(null as any).isValid).toBe(false);
      expect(required(undefined as any).isValid).toBe(false);
      expect(required('   ').isValid).toBe(false);
    });

    it('should pass for non-empty values', () => {
      expect(required('hello').isValid).toBe(true);
      expect(required('hello').error).toBeUndefined();
    });

    it('should return error message on failure', () => {
      const result = required('');
      expect(result.error).toContain('wajib');
    });
  });

  describe('email', () => {
    it('should pass for valid emails', () => {
      expect(email('user@example.com').isValid).toBe(true);
      expect(email('admin@school.edu').isValid).toBe(true);
    });

    it('should fail for invalid emails', () => {
      expect(email('notanemail').isValid).toBe(false);
      expect(email('@nodomain').isValid).toBe(false);
    });

    it('should pass for empty values (not required by default)', () => {
      expect(email('').isValid).toBe(true);
    });
  });

  describe('username', () => {
    it('should pass for valid usernames', () => {
      expect(username('teacher01').isValid).toBe(true);
      expect(username('user.name_99').isValid).toBe(true);
    });

    it('should fail for invalid usernames', () => {
      expect(username('ab').isValid).toBe(false); // too short
      expect(username('user@name').isValid).toBe(false); // invalid char
    });
  });

  describe('passwordStrong', () => {
    it('should pass for strong passwords', () => {
      expect(passwordStrong('SecurePass1').isValid).toBe(true);
      expect(passwordStrong('MyP4ssw0rd').isValid).toBe(true);
    });

    it('should fail for weak passwords', () => {
      expect(passwordStrong('short').isValid).toBe(false);
      expect(passwordStrong('nouppercase1').isValid).toBe(false);
      expect(passwordStrong('NOLOWER1').isValid).toBe(false);
      expect(passwordStrong('NoDigits').isValid).toBe(false);
    });
  });

  describe('passwordMatch', () => {
    it('should pass when passwords match', () => {
      expect(passwordMatch('abc123', 'abc123').isValid).toBe(true);
    });

    it('should fail when passwords differ', () => {
      const result = passwordMatch('abc123', 'xyz456');
      expect(result.isValid).toBe(false);
      expect(result.error).toContain('cocok');
    });
  });

  describe('minLength', () => {
    it('should pass for values meeting minimum', () => {
      expect(minLength('12345678', 8).isValid).toBe(true);
      expect(minLength('longer than 8', 8).isValid).toBe(true);
    });

    it('should fail for values below minimum', () => {
      const result = minLength('short', 8);
      expect(result.isValid).toBe(false);
      expect(result.error).toContain('8');
    });

    it('should pass for empty (non-required)', () => {
      expect(minLength('', 5).isValid).toBe(true);
    });
  });

  describe('maxLength', () => {
    it('should pass for values under maximum', () => {
      expect(maxLength('short', 50).isValid).toBe(true);
    });

    it('should fail for values over maximum', () => {
      const result = maxLength('a'.repeat(51), 50);
      expect(result.isValid).toBe(false);
      expect(result.error).toContain('50');
    });
  });

  describe('nisn', () => {
    it('should pass for valid NISN (10 digits)', () => {
      expect(nisn('1234567890').isValid).toBe(true);
    });

    it('should fail for invalid NISN', () => {
      expect(nisn('12345').isValid).toBe(false);
      expect(nisn('12345678901').isValid).toBe(false);
      expect(nisn('123456789a').isValid).toBe(false);
    });
  });

  describe('phoneIndonesia', () => {
    it('should pass for valid Indonesian phone numbers', () => {
      expect(phoneIndonesia('08123456789').isValid).toBe(true);
      expect(phoneIndonesia('+6281234567890').isValid).toBe(true);
      expect(phoneIndonesia('6281234567890').isValid).toBe(true);
    });

    it('should fail for invalid phone numbers', () => {
      expect(phoneIndonesia('12345').isValid).toBe(false);
      expect(phoneIndonesia('abc').isValid).toBe(false);
    });
  });

  describe('name', () => {
    it('should pass for valid names', () => {
      expect(name('John Doe').isValid).toBe(true);
      expect(name("O'Brien").isValid).toBe(true);
    });

    it('should fail for names with invalid characters', () => {
      expect(name('User123').isValid).toBe(false);
      expect(name('<script>').isValid).toBe(false);
    });
  });

  describe('dateISO', () => {
    it('should pass for valid ISO dates', () => {
      expect(dateISO('2025-01-15').isValid).toBe(true);
    });

    it('should fail for invalid dates', () => {
      expect(dateISO('15-01-2025').isValid).toBe(false);
      expect(dateISO('not-a-date').isValid).toBe(false);
    });
  });

  describe('qrToken', () => {
    it('should pass for valid QR tokens (payload.hash)', () => {
      const validToken = toBase64('{"schedule_id":1}') + '.validhash123abc';
      expect(qrToken(validToken).isValid).toBe(true);
    });

    it('should fail for invalid QR tokens', () => {
      expect(qrToken('nodot').isValid).toBe(false);
      expect(qrToken('too.many.dots').isValid).toBe(false);
    });

    it('should pass for empty (non-required)', () => {
      expect(qrToken('').isValid).toBe(true);
    });
  });

  describe('deviceId', () => {
    it('should pass for valid device IDs', () => {
      expect(deviceId('a1b2c3d4-e5f6-7890').isValid).toBe(true);
      expect(deviceId('VALID-DEVICE-ID-12345').isValid).toBe(true);
    });

    it('should fail for IDs with invalid characters', () => {
      expect(deviceId('<script>alert(1)</script>').isValid).toBe(false);
    });
  });

  describe('uuid', () => {
    it('should pass for valid UUID v4', () => {
      expect(uuid('a1b2c3d4-e5f6-4890-abcd-ef1234567890').isValid).toBe(true);
    });

    it('should fail for invalid UUIDs', () => {
      expect(uuid('not-a-uuid').isValid).toBe(false);
    });
  });

  describe('locationForAttendance', () => {
    it('should pass for valid location data', () => {
      const result = locationForAttendance({
        latitude: -6.2088,
        longitude: 106.8456,
        accuracy: 15,
      });
      expect(result.isValid).toBe(true);
    });

    it('should fail for poor accuracy (>100m)', () => {
      const result = locationForAttendance({
        latitude: -6.2088,
        longitude: 106.8456,
        accuracy: 150,
      });
      expect(result.isValid).toBe(false);
    });

    it('should fail for null location', () => {
      expect(locationForAttendance(null).isValid).toBe(false);
    });

    it('should fail for out-of-range latitude', () => {
      const result = locationForAttendance({latitude: 100, longitude: 0});
      expect(result.isValid).toBe(false);
    });

    it('should fail for out-of-range longitude', () => {
      const result = locationForAttendance({latitude: 0, longitude: 200});
      expect(result.isValid).toBe(false);
    });
  });

  describe('attendanceScanPayload', () => {
    const validPayload = {
      qr_token: toBase64('{"schedule_id":1}') + '.validhash123abc',
      latitude: -6.2088,
      longitude: 106.8456,
      device_id: 'valid-device-id-12345678',
    };

    it('should pass for valid scan payload', () => {
      expect(attendanceScanPayload(validPayload).isValid).toBe(true);
    });

    it('should fail when qr_token is missing', () => {
      const result = attendanceScanPayload({
        ...validPayload,
        qr_token: undefined,
      });
      expect(result.isValid).toBe(false);
    });

    it('should fail for invalid QR token in payload', () => {
      const result = attendanceScanPayload({
        ...validPayload,
        qr_token: 'invalid',
      });
      expect(result.isValid).toBe(false);
    });

    it('should fail for out-of-range coordinates', () => {
      const result = attendanceScanPayload({...validPayload, latitude: 100});
      expect(result.isValid).toBe(false);
    });
  });

  describe('checkDangerousInput', () => {
    it('should pass for clean input', () => {
      expect(checkDangerousInput('Hello World').isValid).toBe(true);
      expect(checkDangerousInput('Normal text 123').isValid).toBe(true);
    });

    it('should fail for XSS patterns', () => {
      expect(checkDangerousInput('<script>alert(1)</script>').isValid).toBe(
        false,
      );
      expect(checkDangerousInput('javascript:void(0)').isValid).toBe(false);
    });
  });

  describe('sanitizeInput', () => {
    it('should escape HTML characters', () => {
      expect(sanitizeInput('<b>bold</b>')).toBe('&lt;b&gt;bold&lt;/b&gt;');
      expect(sanitizeInput('"quoted"')).toBe('&quot;quoted&quot;');
    });

    it('should return empty string as-is', () => {
      expect(sanitizeInput('')).toBe('');
    });
  });

  describe('composite validators', () => {
    it('requiredEmail should enforce required + email format', () => {
      expect(requiredEmail('').isValid).toBe(false);
      expect(requiredEmail('notanemail').isValid).toBe(false);
      expect(requiredEmail('valid@test.com').isValid).toBe(true);
    });

    it('requiredUsername should enforce required + username format', () => {
      expect(requiredUsername('').isValid).toBe(false);
      expect(requiredUsername('ab').isValid).toBe(false);
      expect(requiredUsername('teacher01').isValid).toBe(true);
    });

    it('requiredPassword should enforce required + strong password', () => {
      expect(requiredPassword('').isValid).toBe(false);
      expect(requiredPassword('weak').isValid).toBe(false);
      expect(requiredPassword('SecurePass1').isValid).toBe(true);
    });

    it('requiredName should enforce required + min 2 chars + name format', () => {
      expect(requiredName('').isValid).toBe(false);
      expect(requiredName('A').isValid).toBe(false);
      expect(requiredName('John Doe').isValid).toBe(true);
    });
  });
});

describe('patterns', () => {
  it('should have QR token pattern', () => {
    const validToken = toBase64('payload') + '.hashvalue';
    expect(patterns.qrToken.test(validToken)).toBe(true);
    expect(patterns.qrToken.test('invalid')).toBe(false);
  });

  it('should have device ID pattern', () => {
    expect(patterns.deviceId.test('valid-device-12345')).toBe(true);
  });

  it('should have email pattern', () => {
    expect(patterns.email.test('user@test.com')).toBe(true);
    expect(patterns.email.test('invalid')).toBe(false);
  });
});

describe('errorMessages', () => {
  it('should have Indonesian error messages', () => {
    expect(errorMessages.required).toContain('wajib');
    expect(errorMessages.email).toContain('email');
    expect(errorMessages.password).toContain('Password');
  });

  it('should have dynamic length messages', () => {
    expect(errorMessages.minLength(5)).toContain('5');
    expect(errorMessages.maxLength(100)).toContain('100');
  });
});

describe('default export (validation object)', () => {
  it('should aggregate all validators', () => {
    expect(typeof validation.required).toBe('function');
    expect(typeof validation.email).toBe('function');
    expect(typeof validation.minLength).toBe('function');
    expect(typeof validation.maxLength).toBe('function');
    expect(typeof validation.locationForAttendance).toBe('function');
    expect(typeof validation.attendanceScanPayload).toBe('function');
    expect(typeof validation.checkDangerousInput).toBe('function');
    expect(typeof validation.sanitizeInput).toBe('function');
  });

  it('should include patterns and errorMessages', () => {
    expect(validation.patterns).toBeDefined();
    expect(validation.errorMessages).toBeDefined();
  });
});
