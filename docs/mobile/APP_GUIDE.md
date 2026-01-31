# AbsensiQR Pro - Mobile App Development Guide

## Overview
Dokumen ini adalah panduan untuk mengembangkan aplikasi mobile Android/iOS menggunakan **React Native** atau **Flutter** yang akan terintegrasi dengan backend AbsensiQR Pro.

## Stack yang Direkomendasikan

### Option 1: React Native (JavaScript/TypeScript)
- **Framework**: React Native
- **Navigation**: React Navigation
- **State Management**: Zustand atau Redux Toolkit
- **QR Scanner**: `react-native-camera` atau `react-native-vision-camera`
- **HTTP Client**: Axios
- **Auth**: Secure Storage untuk token (AsyncStorage dengan encryption)

### Option 2: Flutter (Dart)
- **Framework**: Flutter
- **State Management**: Provider atau Riverpod
- **QR Scanner**: `qr_code_scanner` package
- **HTTP Client**: Dio atau HTTP package
- **Secure Storage**: `flutter_secure_storage`

---

## Fitur Utama Mobile App

### 1. **Login Siswa**
- Input: Username + Password
- Endpoint: `POST /api/v1/auth/login`
- Response: Token JWT + User Data
- Simpan token di Secure Storage

### 2. **Scan QR Code Absensi**
- Buka kamera untuk scan QR
- QR Code format: `ABSENSI-{schedule_id}-{timestamp}-{random_hash}`
- Kirim ke endpoint: `POST /api/v1/attendance/check-in`
- Body:
```json
{
  "qr_code": "ABSENSI-123-1234567890-abc123",
  "latitude": -6.2088,
  "longitude": 106.8456
}
```

### 3. **Riwayat Absensi**
- Tampilkan list absensi siswa
- Endpoint: `GET /api/v1/student/attendance-history`
- Filter by date range

### 4. **Profil Siswa**
- Lihat data diri
- Endpoint: `GET /api/v1/auth/me`

---

## API Integration

### Base URL
```
Production: https://api.absensigrpro.com/api/v1
Development: http://localhost:8000/api/v1
```

### Authentication
Semua request (kecuali login) harus menyertakan header:
```
Authorization: Bearer {token}
```

### Key Endpoints untuk Mobile

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /auth/login | Login siswa |
| GET | /auth/me | Get user profile |
| POST | /auth/logout | Logout |
| POST | /attendance/check-in | Submit attendance via QR |
| GET | /student/attendance-history | Get attendance history |
| GET | /student/schedule | Get today's schedule |

---

## Sample Code

### React Native - QR Scanner Component
```typescript
import { Camera, useCameraDevice } from 'react-native-vision-camera';
import { useScanBarcodes, BarcodeFormat } from 'vision-camera-code-scanner';

export const QRScannerScreen = () => {
  const device = useCameraDevice('back');
  const [frameProcessor, barcodes] = useScanBarcodes([BarcodeFormat.QR_CODE]);

  useEffect(() => {
    if (barcodes && barcodes.length > 0) {
      const qrCode = barcodes[0].rawValue;
      submitAttendance(qrCode);
    }
  }, [barcodes]);

  const submitAttendance = async (qrCode: string) => {
    try {
      const response = await apiClient.post('/attendance/check-in', {
        qr_code: qrCode,
        latitude: currentLocation.latitude,
        longitude: currentLocation.longitude,
      });
      Alert.alert('Sukses', 'Absensi berhasil dicatat!');
    } catch (error) {
      Alert.alert('Gagal', error.message);
    }
  };

  return <Camera device={device} frameProcessor={frameProcessor} />;
};
```

### Flutter - QR Scanner
```dart
import 'package:qr_code_scanner/qr_code_scanner.dart';

class QRScannerScreen extends StatefulWidget {
  @override
  _QRScannerScreenState createState() => _QRScannerScreenState();
}

class _QRScannerScreenState extends State<QRScannerScreen> {
  final GlobalKey qrKey = GlobalKey(debugLabel: 'QR');
  QRViewController? controller;

  void _onQRViewCreated(QRViewController controller) {
    this.controller = controller;
    controller.scannedDataStream.listen((scanData) {
      submitAttendance(scanData.code);
    });
  }

  Future<void> submitAttendance(String qrCode) async {
    final response = await dio.post('/attendance/check-in', data: {
      'qr_code': qrCode,
      'latitude': currentLocation.latitude,
      'longitude': currentLocation.longitude,
    });
    
    if (response.statusCode == 200) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Absensi berhasil!')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return QRView(
      key: qrKey,
      onQRViewCreated: _onQRViewCreated,
    );
  }
}
```

---

## UI/UX Guidelines

### Color Scheme
- Primary: `#3B82F6` (Blue)
- Success: `#10B981` (Green)
- Warning: `#F59E0B` (Orange)
- Error: `#EF4444` (Red)

### Screens Minimum
1. **Splash Screen** - Logo + animasi loading
2. **Login Screen** - Username, Password, Remember Me
3. **Dashboard** - Ringkasan absensi hari ini, Quick scan QR button
4. **QR Scanner** - Fullscreen camera dengan overlay guide
5. **History** - List riwayat absensi dengan filter
6. **Profile** - Data siswa, Logout button

---

## Testing

### Test Accounts (Development)
```
Username: student1
Password: password

Username: student2  
Password: password
```

### Sample QR Code (for testing)
Generate from teacher dashboard atau buat manual:
```
ABSENSI-1-1737612000-test123
```

---

## Deployment

### Android
1. Build APK: `npx react-native build-android` atau `flutter build apk`
2. Upload ke Google Play Console
3. Internal Testing → Closed Testing → Production

### iOS
1. Build IPA: `npx react-native build-ios` atau `flutter build ios`
2. Upload ke App Store Connect via Xcode
3. TestFlight → App Review → Release

---

## Support & Resources

- **Backend API Docs**: See `/docs/api-documentation.md`
- **Postman Collection**: Import from `/postman/absensiQR.json`
- **Support**: developer@absensigrpro.com

---

**Last Updated**: 2026-01-23
**Version**: 1.0.0
