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

// react-native-vision-camera
jest.mock('react-native-vision-camera', () => ({
  Camera: 'Camera',
  useCameraDevices: jest.fn(() => ({back: {id: 'back'}, front: {id: 'front'}})),
  useCodeScanner: jest.fn(),
}));

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

  console.warn.apply(console, args);
});
