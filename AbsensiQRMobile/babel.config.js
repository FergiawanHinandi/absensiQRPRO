module.exports = function(api) {
  const isProduction = api.env('production');
  
  return {
    presets: ['module:@react-native/babel-preset'],
    plugins: isProduction ? [
      // SECURITY: Strip console.log in production builds
      ['transform-remove-console', { exclude: ['error', 'warn'] }]
    ] : [],
  };
};
