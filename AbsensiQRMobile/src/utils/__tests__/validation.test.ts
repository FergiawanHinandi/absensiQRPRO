import {
  validators,
  validateAll,
  ValidationPatterns,
  ValidationMessages,
  compose,
} from '../validation';

describe('Mobile Validation Utilities', () => {
  describe('required validator', () => {
    it('should fail for empty values', () => {
      expect(validators.required('')).not.toBeNull();
      expect(validators.required(null as any)).not.toBeNull();
      expect(validators.required(undefined as any)).not.toBeNull();
    });

    it('should pass for non-empty values', () => {
      expect(validators.required('hello')).toBeNull();
      expect(validators.required(123)).toBeNull();
    });
  });

  describe('email validator', () => {
    it('should pass for valid emails', () => {
      expect(validators.email('user@example.com')).toBeNull();
      expect(validators.email('admin@school.edu')).toBeNull();
    });

    it('should fail for invalid emails', () => {
      expect(validators.email('notanemail')).not.toBeNull();
      expect(validators.email('missing@domain')).not.toBeNull();
    });

    it('should allow empty values', () => {
      expect(validators.email('')).toBeNull();
    });
  });

  describe('minLength validator', () => {
    it('should pass for values meeting minimum', () => {
      const minLen8 = validators.minLength(8);
      expect(minLen8('12345678')).toBeNull();
      expect(minLen8('longer than 8')).toBeNull();
    });

    it('should fail for values below minimum', () => {
      const minLen8 = validators.minLength(8);
      expect(minLen8('short')).not.toBeNull();
    });
  });

  describe('maxLength validator', () => {
    it('should pass for values under maximum', () => {
      const maxLen50 = validators.maxLength(50);
      expect(maxLen50('short')).toBeNull();
    });

    it('should fail for values over maximum', () => {
      const maxLen50 = validators.maxLength(50);
      expect(maxLen50('a'.repeat(51))).not.toBeNull();
    });
  });

  describe('password validator', () => {
    it('should pass for strong passwords', () => {
      expect(validators.password('SecureP@ss123')).toBeNull();
      expect(validators.password('MyP4ssw0rd!')).toBeNull();
    });

    it('should fail for weak passwords', () => {
      expect(validators.password('short')).not.toBeNull();
      expect(validators.password('nouppercase1!')).not.toBeNull();
    });
  });

  describe('deviceId validator', () => {
    it('should pass for valid device IDs', () => {
      expect(validators.deviceId('a1b2c3d4-e5f6-7890-abcd-ef1234567890')).toBeNull();
      expect(validators.deviceId('VALID-DEVICE-ID-12345')).toBeNull();
    });

    it('should fail for invalid device IDs', () => {
      expect(validators.deviceId('')).not.toBeNull();
      expect(validators.deviceId('ab')).not.toBeNull(); // too short
      expect(validators.deviceId('<script>alert(1)</script>')).not.toBeNull(); // XSS attempt
    });
  });

  describe('qrToken validator', () => {
    it('should pass for valid QR tokens', () => {
      // Valid base64 pattern with dot separator
      const validToken = btoa('{"schedule_id":1}') + '.validhash123abc';
      expect(validators.qrToken(validToken)).toBeNull();
    });

    it('should fail for invalid QR tokens', () => {
      expect(validators.qrToken('')).not.toBeNull();
      expect(validators.qrToken('nodot')).not.toBeNull();
      expect(validators.qrToken('too.many.dots')).not.toBeNull();
    });
  });

  describe('latitude validator', () => {
    it('should pass for valid latitudes', () => {
      expect(validators.latitude(-6.2088)).toBeNull(); // Jakarta
      expect(validators.latitude(0)).toBeNull();
      expect(validators.latitude(90)).toBeNull();
      expect(validators.latitude(-90)).toBeNull();
    });

    it('should fail for invalid latitudes', () => {
      expect(validators.latitude(91)).not.toBeNull();
      expect(validators.latitude(-91)).not.toBeNull();
    });
  });

  describe('longitude validator', () => {
    it('should pass for valid longitudes', () => {
      expect(validators.longitude(106.8456)).toBeNull(); // Jakarta
      expect(validators.longitude(0)).toBeNull();
      expect(validators.longitude(180)).toBeNull();
      expect(validators.longitude(-180)).toBeNull();
    });

    it('should fail for invalid longitudes', () => {
      expect(validators.longitude(181)).not.toBeNull();
      expect(validators.longitude(-181)).not.toBeNull();
    });
  });

  describe('locationForAttendance validator', () => {
    it('should pass for valid location data', () => {
      const validLocation = {
        latitude: -6.2088,
        longitude: 106.8456,
        accuracy: 15,
      };
      expect(validators.locationForAttendance(validLocation)).toBeNull();
    });

    it('should fail for poor accuracy', () => {
      const poorAccuracy = {
        latitude: -6.2088,
        longitude: 106.8456,
        accuracy: 150, // > 100m threshold
      };
      expect(validators.locationForAttendance(poorAccuracy)).not.toBeNull();
    });

    it('should fail for missing location data', () => {
      expect(validators.locationForAttendance(null)).not.toBeNull();
      expect(validators.locationForAttendance(undefined)).not.toBeNull();
    });

    it('should fail for mock location flag', () => {
      const mockLocation = {
        latitude: -6.2088,
        longitude: 106.8456,
        accuracy: 15,
        isMock: true,
      };
      expect(validators.locationForAttendance(mockLocation)).not.toBeNull();
    });
  });

  describe('attendanceScanPayload validator', () => {
    const validPayload = {
      qr_token: btoa('{"schedule_id":1}') + '.validhash123abc',
      latitude: -6.2088,
      longitude: 106.8456,
      device_id: 'valid-device-id-12345678',
    };

    it('should pass for valid scan payload', () => {
      expect(validators.attendanceScanPayload(validPayload)).toBeNull();
    });

    it('should fail for invalid QR token in payload', () => {
      const invalid = { ...validPayload, qr_token: 'invalid' };
      expect(validators.attendanceScanPayload(invalid)).not.toBeNull();
    });

    it('should fail for invalid coordinates', () => {
      const invalid = { ...validPayload, latitude: 100 }; // Out of range
      expect(validators.attendanceScanPayload(invalid)).not.toBeNull();
    });

    it('should fail for missing device_id', () => {
      const invalid = { ...validPayload, device_id: '' };
      expect(validators.attendanceScanPayload(invalid)).not.toBeNull();
    });
  });

  describe('nisn validator', () => {
    it('should pass for valid NISN', () => {
      expect(validators.nisn('1234567890')).toBeNull();
    });

    it('should fail for invalid NISN', () => {
      expect(validators.nisn('12345')).not.toBeNull(); // too short
      expect(validators.nisn('12345678901')).not.toBeNull(); // too long
      expect(validators.nisn('123456789a')).not.toBeNull(); // has letter
    });
  });

  describe('noXss validator', () => {
    it('should pass for clean input', () => {
      expect(validators.noXss('Hello World')).toBeNull();
      expect(validators.noXss('Normal text 123')).toBeNull();
    });

    it('should fail for XSS patterns', () => {
      expect(validators.noXss('<script>alert(1)</script>')).not.toBeNull();
      expect(validators.noXss('onclick="evil()"')).not.toBeNull();
      expect(validators.noXss('javascript:void(0)')).not.toBeNull();
    });
  });
});

describe('validateAll function', () => {
  it('should validate multiple fields', () => {
    const loginValidators = {
      username: [validators.required, validators.minLength(3)],
      password: [validators.required, validators.password],
    };

    const validData = {
      username: 'teacher01',
      password: 'SecureP@ss123',
    };

    const errors = validateAll(validData, loginValidators);
    expect(errors.username).toBeNull();
    expect(errors.password).toBeNull();
  });

  it('should return errors for invalid data', () => {
    const loginValidators = {
      username: [validators.required, validators.minLength(3)],
      password: [validators.required, validators.password],
    };

    const invalidData = {
      username: '',
      password: 'weak',
    };

    const errors = validateAll(invalidData, loginValidators);
    expect(errors.username).not.toBeNull();
    expect(errors.password).not.toBeNull();
  });
});

describe('compose function', () => {
  it('should combine multiple validators', () => {
    const composed = compose([
      validators.required,
      validators.minLength(5),
      validators.maxLength(20),
    ]);

    expect(composed('valid')).toBeNull();
    expect(composed('')).not.toBeNull(); // fails required
    expect(composed('abc')).not.toBeNull(); // fails minLength
    expect(composed('x'.repeat(25))).not.toBeNull(); // fails maxLength
  });
});

describe('ValidationPatterns', () => {
  it('should have QR token pattern', () => {
    const validToken = btoa('payload') + '.hashvalue';
    expect(ValidationPatterns.QR_TOKEN.test(validToken)).toBe(true);
    expect(ValidationPatterns.QR_TOKEN.test('invalid')).toBe(false);
  });

  it('should have device ID pattern', () => {
    expect(ValidationPatterns.DEVICE_ID.test('valid-device-12345')).toBe(true);
    expect(ValidationPatterns.DEVICE_ID.test('ab')).toBe(false);
  });
});

describe('ValidationMessages', () => {
  it('should have Indonesian error messages', () => {
    expect(ValidationMessages.REQUIRED).toContain('wajib');
    expect(ValidationMessages.EMAIL_INVALID).toContain('email');
    expect(ValidationMessages.PASSWORD_WEAK).toContain('password');
  });
});
