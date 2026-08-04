/**
 * Jest Setup — mock native modules that crash in a pure-JS test environment.
 *
 * Keep this list in sync with native dependencies in package.json.
 * Each mock returns the minimum surface the app relies on.
 */

// react-native-encrypted-storage
jest.mock('react-native-encrypted-storage', () => ({
  default: {
    setItem: jest.fn(() => Promise.resolve()),
    getItem: jest.fn(() => Promise.resolve(null)),
    removeItem: jest.fn(() => Promise.resolve()),
    clear: jest.fn(() => Promise.resolve()),
  },
}));

// react-native-config
jest.mock('react-native-config', () => ({
  API_URL: 'http://localhost:8000/api',
  ENV: 'test',
}));

// App-level modules used by the render smoke test
jest.mock('./src/contexts/AuthContext', () => ({
  __esModule: true,
  AuthProvider: ({children}) => children,
  useAuth: jest.fn(() => ({
    isAuthenticated: false,
    isLoading: false,
    user: null,
  })),
}));

jest.mock('./src/navigation/RootNavigator', () => ({
  __esModule: true,
  RootNavigator: () => null,
}));

jest.mock('./src/services/NotificationService', () => ({
  __esModule: true,
  NotificationService: {
    registerDevice: jest.fn(() => Promise.resolve(null)),
    setupListeners: jest.fn(() => jest.fn()),
  },
}));

jest.mock('./src/services/OfflineSyncService', () => ({
  __esModule: true,
  offlineSyncService: {
    init: jest.fn(),
    destroy: jest.fn(),
  },
  default: {
    init: jest.fn(),
    destroy: jest.fn(),
  },
}));

// react-native-device-info
jest.mock('react-native-device-info', () => ({
  getUniqueId: jest.fn(() => Promise.resolve('test-device-id')),
  getDeviceId: jest.fn(() => 'test-device'),
  getSystemName: jest.fn(() => 'TestOS'),
  getSystemVersion: jest.fn(() => '1.0'),
  getBrand: jest.fn(() => 'TestBrand'),
  getModel: jest.fn(() => 'TestModel'),
  isEmulator: jest.fn(() => Promise.resolve(true)),
}));

// react-native-keychain
jest.mock('react-native-keychain', () => ({
  setGenericPassword: jest.fn(() => Promise.resolve(true)),
  getGenericPassword: jest.fn(() => Promise.resolve(false)),
  resetGenericPassword: jest.fn(() => Promise.resolve(true)),
  SECURITY_LEVEL: {
    ANY: 'ANY',
    SECURE_SOFTWARE: 'SECURE_SOFTWARE',
    SECURE_HARDWARE: 'SECURE_HARDWARE',
  },
}));

// react-native-permissions
jest.mock('react-native-permissions', () => ({
  check: jest.fn(() => Promise.resolve('granted')),
  request: jest.fn(() => Promise.resolve('granted')),
  PERMISSIONS: {ANDROID: {}, IOS: {}},
  RESULTS: {
    UNAVAILABLE: 'unavailable',
    DENIED: 'denied',
    GRANTED: 'granted',
    BLOCKED: 'blocked',
  },
}));

// @react-native-community/geolocation
jest.mock('@react-native-community/geolocation', () => ({
  getCurrentPosition: jest.fn(),
  watchPosition: jest.fn(),
  clearWatch: jest.fn(),
  setRNConfiguration: jest.fn(),
}));

// react-native-geolocation-service
jest.mock('react-native-geolocation-service', () => ({
  __esModule: true,
  default: {
    getCurrentPosition: jest.fn(),
    watchPosition: jest.fn(),
    clearWatch: jest.fn(),
  },
}));

// react-native-vision-camera
jest.mock('react-native-vision-camera', () => ({
  Camera: 'Camera',
  useCameraDevices: jest.fn(() => ({back: {id: 'back'}, front: {id: 'front'}})),
  useCodeScanner: jest.fn(),
}));

// react-native-qrcode-svg
jest.mock('react-native-qrcode-svg', () => 'QRCode');

// @react-native-async-storage/async-storage
jest.mock('@react-native-async-storage/async-storage', () => ({
  __esModule: true,
  default: {
    getItem: jest.fn(() => Promise.resolve(null)),
    setItem: jest.fn(() => Promise.resolve()),
    removeItem: jest.fn(() => Promise.resolve()),
    clear: jest.fn(() => Promise.resolve()),
  },
}));

// @react-native-community/netinfo
jest.mock('@react-native-community/netinfo', () => ({
  __esModule: true,
  default: {
    addEventListener: jest.fn(() => jest.fn()),
    fetch: jest.fn(() => Promise.resolve({isConnected: false, isInternetReachable: false})),
  },
}));

// react-native-background-fetch
jest.mock('react-native-background-fetch', () => ({
  __esModule: true,
  default: {
    configure: jest.fn(() => Promise.resolve()),
    finish: jest.fn(),
  },
}));

// @react-native-firebase/messaging
jest.mock('@react-native-firebase/messaging', () => {
  const messagingFn = jest.fn(() => ({
    requestPermission: jest.fn(() => Promise.resolve(1)),
    getToken: jest.fn(() => Promise.resolve('test-fcm-token')),
    onMessage: jest.fn(() => jest.fn()),
    onNotificationOpenedApp: jest.fn(),
    getInitialNotification: jest.fn(() => Promise.resolve(null)),
    onTokenRefresh: jest.fn(() => jest.fn()),
  }));

  messagingFn.AuthorizationStatus = {
    AUTHORIZED: 1,
    PROVISIONAL: 2,
  };

  return {
    __esModule: true,
    default: messagingFn,
    AuthorizationStatus: messagingFn.AuthorizationStatus,
  };
});

// vision-camera-code-scanner
jest.mock('vision-camera-code-scanner', () => ({
  useScanBarcodes: jest.fn(() => [[], {}]),
  BarcodeFormat: {QR_CODE: 'QR_CODE'},
}));

// react-native-ssl-pinning
jest.mock('react-native-ssl-pinning', () => ({
  fetch: jest.fn(() => Promise.resolve({json: () => Promise.resolve({})})),
}));

// jail-monkey (root/jailbreak detection)
jest.mock('jail-monkey', () => ({
  isJailBroken: jest.fn(() => false),
  isDebuggedMode: jest.fn(() => false),
  canMockLocation: jest.fn(() => false),
  trustFall: jest.fn(() => false),
}));

// react-native-safe-area-context
jest.mock('react-native-safe-area-context', () => ({
  SafeAreaProvider: ({children}) => children,
  SafeAreaView: ({children}) => children,
  useSafeAreaInsets: jest.fn(() => ({top: 0, bottom: 0, left: 0, right: 0})),
}));

// react-native-screens
jest.mock('react-native-screens', () => ({
  enableScreens: jest.fn(),
}));

// Suppress noisy RN warnings in test output
jest.spyOn(console, 'warn').mockImplementation((...args) => {
  const msg = typeof args[0] === 'string' ? args[0] : '';
  if (msg.includes('Animated') || msg.includes('useNativeDriver')) {
    return;
  }
  return;
});
