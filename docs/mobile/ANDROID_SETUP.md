# Android Development Setup Guide

Panduan lengkap untuk setup React Native Android development environment di Windows.

## Langkah-langkah Setup

### 1. Install Java Development Kit (JDK)

1. **Download JDK 17 atau 20:**
   - Kunjungi: https://www.oracle.com/java/technologies/downloads/
   - Download JDK untuk Windows (x64)
   - Install dengan pengaturan default

2. **Set JAVA_HOME Environment Variable:**
   ```
   Control Panel > System > Advanced System Settings > Environment Variables
   
   System Variables > New:
   Variable name: JAVA_HOME
   Variable value: C:\Program Files\Java\jdk-17 (sesuaikan dengan versi yang diinstall)
   ```

3. **Tambahkan Java ke PATH:**
   ```
   Edit PATH variable dan tambahkan:
   %JAVA_HOME%\bin
   ```

4. **Verifikasi instalasi:**
   ```bash
   java -version
   javac -version
   ```

### 2. Install Android Studio

1. **Download Android Studio:**
   - Kunjungi: https://developer.android.com/studio
   - Download dan install dengan semua komponen default

2. **Setup Android SDK:**
   - Buka Android Studio
   - Go to: File > Settings > Appearance & Behavior > System Settings > Android SDK
   - Install komponen berikut:
     - Android SDK Platform 34
     - Android SDK Build-Tools 34.0.0
     - Android Emulator
     - Android SDK Platform-Tools

### 3. Setup Android Environment Variables

1. **Set ANDROID_HOME:**
   ```
   System Variables > New:
   Variable name: ANDROID_HOME
   Variable value: C:\Users\%USERNAME%\AppData\Local\Android\Sdk
   ```

2. **Set ANDROID_SDK_ROOT:**
   ```
   System Variables > New:
   Variable name: ANDROID_SDK_ROOT
   Variable value: C:\Users\%USERNAME%\AppData\Local\Android\Sdk
   ```

3. **Tambahkan Android tools ke PATH:**
   ```
   Edit PATH variable dan tambahkan:
   %ANDROID_HOME%\platform-tools
   %ANDROID_HOME%\tools
   %ANDROID_HOME%\tools\bin
   %ANDROID_HOME%\emulator
   ```

### 4. Create Android Virtual Device (AVD)

1. **Buka AVD Manager:**
   - Android Studio > Tools > AVD Manager
   - Atau klik icon AVD Manager di toolbar

2. **Create Virtual Device:**
   - Click "Create Virtual Device"
   - Pilih device (recommended: Pixel 4 atau Pixel 6)
   - Pilih system image (API Level 34 - Android 14)
   - Click "Next" dan "Finish"

3. **Start Emulator:**
   - Click tombol play (▶️) pada AVD yang dibuat
   - Tunggu emulator boot up

### 5. Verifikasi Setup

1. **Restart Command Prompt/PowerShell**

2. **Test ADB:**
   ```bash
   adb version
   ```

3. **Test Java:**
   ```bash
   java -version
   ```

4. **Test Android SDK:**
   ```bash
   # List connected devices/emulators
   adb devices
   ```

5. **Run React Native Doctor:**
   ```bash
   cd AbsensiQRMobile
   npx react-native doctor
   ```

## Menjalankan React Native App

### 1. Start Metro Bundler
```bash
cd AbsensiQRMobile
npm start
```

### 2. Run on Android (terminal baru)
```bash
# Pastikan emulator sudah berjalan atau device terhubung
npm run android
```

## Troubleshooting

### Error: 'adb' is not recognized
**Solusi:**
1. Pastikan ANDROID_HOME sudah diset
2. Pastikan platform-tools ada di PATH
3. Restart command prompt
4. Test dengan: `adb version`

### Error: JAVA_HOME is not set
**Solusi:**
1. Install JDK 17 atau 20
2. Set JAVA_HOME environment variable
3. Tambahkan %JAVA_HOME%\bin ke PATH
4. Restart command prompt
5. Test dengan: `java -version`

### Error: No emulators found
**Solusi:**
1. Buka Android Studio > AVD Manager
2. Create new virtual device
3. Start emulator sebelum run `npm run android`
4. Atau hubungkan physical device dengan USB debugging enabled

### Error: Android SDK not found
**Solusi:**
1. Install Android Studio dan SDK
2. Set ANDROID_HOME dan ANDROID_SDK_ROOT
3. Tambahkan Android tools ke PATH
4. Restart command prompt

### Error: Gradle build failed
**Solusi:**
1. Pastikan JDK version 17-20
2. Clean project: `cd android && ./gradlew clean`
3. Rebuild: `npm run android`

### Error: Metro bundler issues
**Solusi:**
1. Stop Metro: Ctrl+C
2. Clear cache: `npm run clean`
3. Start fresh: `npm start -- --reset-cache`

## Tips Development

### 1. Enable Developer Options di Android Device
1. Settings > About phone
2. Tap "Build number" 7 kali
3. Settings > Developer options
4. Enable "USB debugging"

### 2. Hot Reload
- Shake device atau press Ctrl+M di emulator
- Enable "Fast Refresh" untuk auto-reload

### 3. Debug dengan Chrome DevTools
- Shake device > Debug
- Buka Chrome: chrome://inspect

### 4. View Logs
```bash
# View React Native logs
npx react-native log-android

# View system logs
adb logcat
```

## Environment Variables untuk Mobile App

File: `AbsensiQRMobile/.env`
```env
API_URL=http://10.0.2.2:8000/api/v1
APP_NAME=AbsensiQR Pro
```

**Note:** 
- `10.0.2.2` adalah IP address untuk mengakses localhost dari Android emulator
- Untuk physical device, gunakan IP address komputer di network yang sama

## Perintah Berguna

```bash
# List connected devices
adb devices

# Install APK manually
adb install app-debug.apk

# Uninstall app
adb uninstall com.absensiQRMobile

# Clear app data
adb shell pm clear com.absensiQRMobile

# View device logs
adb logcat

# Restart ADB server
adb kill-server && adb start-server
```

Setelah mengikuti panduan ini, Anda seharusnya bisa menjalankan React Native app di Android emulator atau device.