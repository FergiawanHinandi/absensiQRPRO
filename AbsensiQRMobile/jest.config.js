module.exports = {
  preset: 'react-native',

  // Allow babel-jest to transform RN ecosystem packages (shipped as ESM/JSX)
  transformIgnorePatterns: [
    'node_modules/(?!(react-native' +
      '|@react-native' +
      '|@react-navigation' +
      '|react-native-config' +
      '|react-native-encrypted-storage' +
      '|react-native-device-info' +
      '|react-native-keychain' +
      '|react-native-permissions' +
      '|react-native-safe-area-context' +
      '|react-native-screens' +
      '|react-native-vector-icons' +
      '|react-native-vision-camera' +
      '|react-native-ssl-pinning' +
      '|@react-native-community/geolocation' +
      '|jail-monkey' +
      '|vision-camera-code-scanner' +
      '|uuid' +
      ')/)',
  ],

  // Mock native modules that have no JS fallback
  moduleNameMapper: {
    '\\.(jpg|jpeg|png|gif|webp|svg)$': '<rootDir>/__mocks__/fileMock.js',
  },

  // Global setup: mock native modules before each test suite
  setupFiles: ['<rootDir>/jest.setup.js'],

  moduleFileExtensions: ['ts', 'tsx', 'js', 'jsx', 'json', 'node'],

  // Collect coverage from src/ only (exclude __tests__ dirs)
  collectCoverageFrom: [
    'src/**/*.{ts,tsx}',
    '!src/**/*.d.ts',
    '!src/**/__tests__/**',
  ],
};
