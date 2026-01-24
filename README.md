# absensiQRPRO

absensiQRPRO adalah solusi absensi modern yang memudahkan sekolah dalam mengelola kehadiran siswa dan guru menggunakan teknologi QR Code. Sistem ini tersedia dalam dua platform: Website dan Mobile App untuk maksimum fleksibilitas.

## 🌟 Fitur Utama

### Website
- **Dashboard Admin** - Statistik dan monitoring absensi real-time
- **Manajemen Siswa** - Database lengkap siswa dengan QR code
- **Laporan Absensi** - Riwayat kehadiran per tanggal
- **Generate QR Code** - Buat dan cetak kartu pelajar dengan QR code

### Mobile App (untuk Guru)
- **QR Scanner** - Scan kartu pelajar siswa dengan cepat
- **Mark Attendance** - Catat kehadiran dengan status (Hadir, Terlambat, Izin)
- **Riwayat Absensi** - Lihat data absensi per tanggal
- **Offline Support** - Data tersimpan lokal untuk sinkronisasi

## 📁 Struktur Proyek

```
absensiQRPRO/
├── website/
│   ├── backend/
│   │   ├── api/              # REST API endpoints
│   │   ├── config/           # Database & CORS configuration
│   │   ├── models/           # Data models (Student, Teacher, Attendance)
│   │   └── database/         # SQL schema
│   └── frontend/
│       ├── css/              # Stylesheets
│       ├── js/               # JavaScript files
│       └── *.html            # HTML pages
├── mobile-app/
│   ├── src/
│   │   ├── screens/          # React Native screens
│   │   ├── components/       # Reusable components
│   │   └── api.js            # API integration
│   ├── App.js                # Main app component
│   └── package.json          # Dependencies
└── docs/
    ├── INSTALLATION.md       # Setup guide
    └── API.md                # API documentation

```

## 🚀 Quick Start

### Prerequisites
- PHP 7.4+
- MySQL 5.7+
- Node.js 16+
- React Native CLI (for mobile app)

### Installation

1. **Clone repository:**
```bash
git clone https://github.com/FergiawanHinandi/absensiQRPRO.git
cd absensiQRPRO
```

2. **Setup database:**
```bash
mysql -u root -p < website/backend/database/schema.sql
```

3. **Configure database connection:**
Edit `website/backend/config/database.php` with your credentials.

4. **Setup mobile app:**
```bash
cd mobile-app
npm install
```

5. **Update API URL in mobile app:**
Edit `mobile-app/src/api.js` with your server IP.

## 📖 Documentation

- [Installation Guide](docs/INSTALLATION.md) - Detailed setup instructions
- [API Documentation](docs/API.md) - Complete API reference

## 🔐 Default Credentials

### Teacher Login (Mobile App):
- Email: `budi@school.com`
- Password: `teacher123`

### Sample Students:
- NIS001 - Ahmad Fauzi (10-A) - QR: `QR-NIS001-2024`
- NIS002 - Dewi Lestari (10-A) - QR: `QR-NIS002-2024`
- NIS003 - Eko Prasetyo (10-B) - QR: `QR-NIS003-2024`

## 🛠️ Technology Stack

### Backend
- PHP 7.4+
- MySQL
- REST API

### Frontend Website
- HTML5
- CSS3
- JavaScript (Vanilla)
- QRCode.js

### Mobile App
- React Native
- React Navigation
- Axios (HTTP client)
- react-native-qrcode-scanner
- AsyncStorage

## 📱 Mobile App Features

### Login Screen
- Teacher authentication
- Remember login session

### Home Screen
- Today's attendance statistics
- Quick access to scanner
- View attendance history

### Scanner Screen
- Real-time QR code scanning
- Student information display
- Multiple attendance status options

### History Screen
- View attendance by date
- Filter by status
- Export reports

## 🌐 Website Features

### Dashboard
- Real-time statistics
- Today's attendance summary
- Quick overview

### Student Management
- List all students
- Search and filter by class
- Generate QR codes
- Print student cards

### Attendance Records
- View by date
- Export reports
- Status tracking

## 📝 License

This project is open source and available under the [MIT License](LICENSE).

## 👥 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📧 Support

For questions or issues, please open an issue on GitHub.

## 🎯 Future Enhancements

- [ ] Push notifications for attendance
- [ ] Parent mobile app
- [ ] SMS notifications
- [ ] Face recognition backup
- [ ] Advanced analytics and reports
- [ ] Multi-school support
- [ ] Cloud synchronization

---

**Made with ❤️ for Indonesian Schools**
