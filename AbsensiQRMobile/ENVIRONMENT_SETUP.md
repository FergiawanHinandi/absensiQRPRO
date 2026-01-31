# Mobile App Environment Configuration Guide

## 📱 AbsensiQR Mobile - API Configuration

This guide explains how to configure the mobile app to connect to different environments (development, staging, production).

---

## 🔧 Quick Start

### 1. Copy Environment Template

```bash
cd AbsensiQRMobile
cp .env.example .env
```

### 2. Edit `.env` File

Choose the appropriate configuration based on your setup:

#### For Android Emulator (Default)
```env
API_BASE_URL=http://10.0.2.2:8000/api/v1
API_TIMEOUT=30000
```

#### For iOS Simulator
```env
API_BASE_URL=http://localhost:8000/api/v1
API_TIMEOUT=30000
```

#### For Physical Device
```env
# Replace with your computer's IP address
API_BASE_URL=http://192.168.1.100:8000/api/v1
API_TIMEOUT=30000
```

#### For Production
```env
API_BASE_URL=https://api.absensi.yourdomain.com/api/v1
API_TIMEOUT=30000
```

### 3. Restart Metro Bundler

After changing `.env`:
```bash
# Stop the current Metro bundler (Ctrl+C)
# Clear cache and restart
npm start -- --reset-cache
```

### 4. Rebuild the App

**Android:**
```bash
cd android
./gradlew clean
cd ..
npm run android
```

**iOS:**
```bash
cd ios
pod install
cd ..
npm run ios
```

---

## 🌐 Environment Variables

### Required Variables

| Variable | Description | Example |
|----------|-------------|---------|
| `API_BASE_URL` | Backend API base URL | `http://10.0.2.2:8000/api/v1` |
| `API_TIMEOUT` | Request timeout in milliseconds | `30000` |

### Optional Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_NAME` | Application name | `AbsensiQR Pro` |
| `APP_VERSION` | App version | `1.0.0` |
| `ENABLE_DEV_TOOLS` | Enable developer tools | `false` |
| `ENABLE_LOGS` | Enable console logging | `false` |

---

## 🔍 How It Works

The app uses a smart fallback system:

1. **First Priority:** Check for `API_BASE_URL` in `.env` file
2. **Development Fallback:** If not found, use platform-specific localhost:
   - **Android Emulator:** `http://10.0.2.2:8000/api/v1`
   - **iOS Simulator:** `http://localhost:8000/api/v1`
3. **Warning:** Shows console warning when using fallback

### Code Example

```typescript
const getApiBaseUrl = (): string => {
    // 1. Try environment config first
    if (Config.API_BASE_URL) {
        return Config.API_BASE_URL;
    }

    // 2. Show warning
    console.warn('⚠️  API_BASE_URL not configured, using fallback');
    
    // 3. Platform-specific fallback
    if (Platform.OS === 'android') {
        return 'http://10.0.2.2:8000/api/v1';
    }
    
    return 'http://localhost:8000/api/v1';
};
```

---

## 📱 Platform-Specific Notes

### Android Emulator

**Why `10.0.2.2`?**
- Android Emulator creates a virtual network
- `10.0.2.2` is a special alias to the host machine's `localhost`
- This allows the emulator to access your development server

**Finding Your API in Logs:**
```bash
# In development mode, the app logs the API URL
adb logcat | grep "API Base URL"
```

### iOS Simulator

**Why `localhost`?**
- iOS Simulator shares the same network as the host machine
- Can directly use `localhost` or `127.0.0.1`

**Debugging:**
```bash
# Check console logs
xcrun simctl spawn booted log stream --predicate 'processImagePath contains "AbsensiQR"'
```

### Physical Devices

**Requirements:**
1. Phone and computer must be on the **same Wi-Fi network**
2. Backend server must be accessible from the network
3. Use your computer's **local IP address**, not `localhost`

**Finding Your IP Address:**

**Windows:**
```powershell
ipconfig
# Look for "IPv4 Address" under your Wi-Fi adapter
```

**macOS/Linux:**
```bash
ifconfig | grep "inet "
# Or
ip addr show
```

**Common Issues:**
- ❌ **Firewall blocking:** Ensure your firewall allows connections on port 8000
- ❌ **Wrong IP:** Make sure you're using your local network IP (192.168.x.x or 10.0.x.x)
- ❌ **Different networks:** Phone and computer must be on same network

---

## 🚀 Production Deployment

### 1. Create Production `.env`

```env
# Production Configuration
API_BASE_URL=https://api.absensi.yourdomain.com/api/v1
API_TIMEOUT=30000

APP_NAME=AbsensiQR Pro
APP_VERSION=1.0.0

ENABLE_DEV_TOOLS=false
ENABLE_LOGS=false
```

### 2. Build Production App

**Android:**
```bash
cd android
./gradlew assembleRelease
# APK will be in: android/app/build/outputs/apk/release/
```

**iOS:**
```bash
# Use Xcode to create Archive
# Product > Archive
```

### 3. Verify Configuration

Before releasing:
1. Check console logs show correct API URL
2. Test API connectivity
3. Verify no development URLs are hardcoded
4. Test on physical device with production API

---

## 🛠️ Troubleshooting

### "Cannot connect to server"

**Check 1: Verify API URL**
```typescript
// The app logs the API URL on startup in dev mode
// Look for: "🌐 API Base URL: http://..."
```

**Check 2: Test API manually**
```bash
# From your computer
curl http://10.0.2.2:8000/api/v1/health

# From your phone (using phone's browser)
# Navigate to: http://YOUR_COMPUTER_IP:8000/api/v1/health
```

**Check 3: Firewall**
```bash
# Windows - Allow port 8000
# macOS - System Preferences > Security & Privacy > Firewall
```

### Environment variables not loading

**Solution 1: Restart Metro with cache clear**
```bash
npm start -- --reset-cache
```

**Solution 2: Clean build**
```bash
# Android
cd android && ./gradlew clean && cd ..

# iOS
cd ios && pod install && cd ..
```

**Solution 3: Verify `.env` file**
```bash
# Check file exists and has no typos
cat .env

# Verify no extra spaces or special characters
```

### "API_BASE_URL not configured" warning

This warning appears when:
1. `.env` file doesn't exist
2. `.env` file exists but `API_BASE_URL` is not set
3. Environment variables not loaded properly

**Solutions:**
1. Create/check `.env` file
2. Restart Metro bundler
3. Clean build and reinstall app

---

## 📋 Environment Checklist

### Development Setup
- [ ] `.env` file created from `.env.example`
- [ ] `API_BASE_URL` configured for your platform
- [ ] Metro bundler restarted after config changes
- [ ] App can connect to backend API
- [ ] Console shows correct API URL
- [ ] No fallback warnings in production

### Testing
- [ ] Tested on Android Emulator
- [ ] Tested on iOS Simulator (if applicable)
- [ ] Tested on physical Android device
- [ ] Tested on physical iOS device (if applicable)
- [ ] Verified API calls work correctly

### Production
- [ ] Production `.env` configured with HTTPS
- [ ] Development settings disabled
- [ ] Logs disabled in production
- [ ] API URL verified on production server
- [ ] Tested production build on physical devices

---

## 📚 Additional Resources

### Files Modified
- `src/api/client.ts` - Main API client with environment config
- `src/legacy_expo/services/api.ts` - Legacy Expo API client
- `src/api/core.ts` - Core API configuration

### Environment Files
- `.env` - Your local configuration (not in git)
- `.env.example` - Template for team members
- `.gitignore` - Ensures `.env` is not committed

### Documentation
- [react-native-config](https://github.com/luggit/react-native-config)
- [Android Emulator Networking](https://developer.android.com/studio/run/emulator-networking)
- [iOS Simulator Networking](https://developer.apple.com/documentation/xcode/running-your-app-in-the-simulator-or-on-a-device)

---

## 💡 Best Practices

1. **Never commit `.env`** - It's in `.gitignore` for a reason
2. **Use `.env.example`** - Keep it updated as a template
3. **Different configs per environment** - Have separate `.env` files for dev/staging/prod
4. **Log API URL in development** - Helps debugging
5. **Validate environment on startup** - App logs show configuration
6. **Test on real devices** - Emulators can hide networking issues

---

## 🆘 Need Help?

If you encounter issues:

1. **Check console logs** - Look for API URL and any warnings
2. **Verify network connectivity** - Ping your backend server
3. **Test API manually** - Use curl or Postman
4. **Review this guide** - Follow troubleshooting steps
5. **Clean build** - Sometimes fixing environment requires clean build

---

**Last Updated:** 2026-01-27  
**Version:** 1.0.0
