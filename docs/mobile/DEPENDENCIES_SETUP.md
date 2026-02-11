# Mobile Dependencies Update Guide

## Required Dependencies

Add these dependencies to your `package.json`:

```json
{
  "dependencies": {
    "uuid": "^9.0.1",
    "crypto-js": "^4.2.0",
    "react-native-device-info": "^10.13.1",
    "@react-native-async-storage/async-storage": "^1.21.0"
  },
  "devDependencies": {
    "@types/uuid": "^9.0.7",
    "@types/crypto-js": "^4.2.1"
  }
}
```

## Installation Commands

### Using npm
```bash
cd AbsensiQRMobile

# Install runtime dependencies
npm install uuid crypto-js react-native-device-info @react-native-async-storage/async-storage

# Install type definitions
npm install --save-dev @types/uuid @types/crypto-js

# Link native modules (if using React Native < 0.60)
npx react-native link react-native-device-info
npx react-native link @react-native-async-storage/async-storage

# For iOS, install pods
cd ios && pod install && cd ..
```

### Using yarn
```bash
cd AbsensiQRMobile

# Install runtime dependencies
yarn add uuid crypto-js react-native-device-info @react-native-async-storage/async-storage

# Install type definitions
yarn add --dev @types/uuid @types/crypto-js

# For iOS, install pods
cd ios && pod install && cd ..
```

## Dependency Details

### 1. **uuid** (^9.0.1)
**Purpose:** Generate UUID v4 for request idempotency

**Usage:**
```typescript
import { v4 as uuidv4 } from 'uuid';

const requestId = uuidv4();
// Output: "550e8400-e29b-41d4-a716-446655440000"
```

### 2. **crypto-js** (^4.2.0)
**Purpose:** AES-256 encryption for offline queue

**Usage:**
```typescript
import CryptoJS from 'crypto-js';

// Encrypt
const encrypted = CryptoJS.AES.encrypt(plaintext, key).toString();

// Decrypt
const decrypted = CryptoJS.AES.decrypt(ciphertext, key);
const plaintext = decrypted.toString(CryptoJS.enc.Utf8);
```

### 3. **react-native-device-info** (^10.13.1)
**Purpose:** Get device-specific identifiers for encryption keys

**Usage:**
```typescript
import DeviceInfo from 'react-native-device-info';

const deviceId = await DeviceInfo.getUniqueId();
const bundleId = DeviceInfo.getBundleId();
const buildNumber = DeviceInfo.getBuildNumber();
```

### 4. **@react-native-async-storage/async-storage** (^1.21.0)
**Purpose:** Secure local storage for offline queue

**Usage:**
```typescript
import AsyncStorage from '@react-native-async-storage/async-storage';

// Store
await AsyncStorage.setItem(key, value);

// Retrieve
const value = await AsyncStorage.getItem(key);

// Remove
await AsyncStorage.removeItem(key);
```

## Platform-Specific Setup

### Android

#### 1. Update `android/app/build.gradle`
```gradle
dependencies {
    // ... other dependencies
    implementation "com.google.android.gms:play-services-base:18.2.0"
}
```

#### 2. Update `AndroidManifest.xml`
```xml
<manifest>
    <!-- Add permissions if needed -->
    <uses-permission android:name="android.permission.READ_PHONE_STATE" />
</manifest>
```

### iOS

#### 1. Update `ios/Podfile`
```ruby
platform :ios, '13.0'

target 'AbsensiQRMobile' do
  # ... other pods
  
  # Required for device-info
  permissions_path = '../node_modules/react-native-permissions/ios'
  pod 'Permission-AppTrackingTransparency', :path => "#{permissions_path}/AppTrackingTransparency"
end
```

#### 2. Run pod install
```bash
cd ios
pod install
cd ..
```

## Verification

### Test Installation
```bash
# Check if packages are installed
npm list uuid crypto-js react-native-device-info @react-native-async-storage/async-storage

# Build and run
npm run android
# or
npm run ios
```

### Test Functionality

Create a test file `src/__tests__/dependencies.test.ts`:

```typescript
import { v4 as uuidv4 } from 'uuid';
import CryptoJS from 'crypto-js';
import DeviceInfo from 'react-native-device-info';
import AsyncStorage from '@react-native-async-storage/async-storage';

describe('Dependencies Test', () => {
  test('uuid generates valid UUID', () => {
    const id = uuidv4();
    expect(id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
  });

  test('crypto-js encrypts and decrypts', () => {
    const plaintext = 'test data';
    const key = 'secret';
    
    const encrypted = CryptoJS.AES.encrypt(plaintext, key).toString();
    const decrypted = CryptoJS.AES.decrypt(encrypted, key);
    const result = decrypted.toString(CryptoJS.enc.Utf8);
    
    expect(result).toBe(plaintext);
  });

  test('device-info returns device ID', async () => {
    const deviceId = await DeviceInfo.getUniqueId();
    expect(deviceId).toBeTruthy();
    expect(typeof deviceId).toBe('string');
  });

  test('async-storage stores and retrieves data', async () => {
    const key = 'test-key';
    const value = 'test-value';
    
    await AsyncStorage.setItem(key, value);
    const retrieved = await AsyncStorage.getItem(key);
    
    expect(retrieved).toBe(value);
    
    await AsyncStorage.removeItem(key);
  });
});
```

Run tests:
```bash
npm test
```

## Troubleshooting

### Issue: "Cannot find module 'uuid'"
**Solution:**
```bash
npm install uuid @types/uuid
```

### Issue: "Cannot find module 'crypto-js'"
**Solution:**
```bash
npm install crypto-js @types/crypto-js
```

### Issue: "Native module cannot be null (DeviceInfo)"
**Solution:**
```bash
# Android
cd android && ./gradlew clean && cd ..
npm run android

# iOS
cd ios && pod install && cd ..
npm run ios
```

### Issue: "AsyncStorage is null"
**Solution:**
```bash
# Reinstall and link
npm install @react-native-async-storage/async-storage
npx react-native link @react-native-async-storage/async-storage

# For iOS
cd ios && pod install && cd ..
```

## Next Steps

After installing dependencies:

1. ✅ Verify all packages installed correctly
2. ✅ Run test suite to confirm functionality
3. ✅ Update mobile app code to use new APIs
4. ✅ Test on both Android and iOS devices
5. ✅ Update app version in `package.json`

## References

- [uuid Documentation](https://github.com/uuidjs/uuid)
- [crypto-js Documentation](https://github.com/brix/crypto-js)
- [react-native-device-info Documentation](https://github.com/react-native-device-info/react-native-device-info)
- [AsyncStorage Documentation](https://react-native-async-storage.github.io/async-storage/)
