# Technology Stack & Build System

## Architecture Overview

**Multi-platform system** with separate backend API, web frontend, and mobile app:

```
Mobile App (React Native) ←→ Backend API (Laravel) ←→ Web Frontend (React)
                                      ↓
                              PostgreSQL + Redis
```

## Backend (Laravel 12)

### Core Technologies
- **Framework**: Laravel 12 (PHP 8.2+)
- **Database**: PostgreSQL 15+ (SQLite for development)
- **Cache**: Redis (optional for development)
- **Authentication**: Laravel Sanctum (JWT tokens)
- **WebSocket**: Laravel Reverb
- **Queue**: Laravel Queue (Redis/Database driver)

### Key Dependencies
- **spatie/laravel-permission**: Role-based access control
- **spatie/laravel-activitylog**: Audit logging
- **maatwebsite/excel**: Excel export functionality
- **barryvdh/laravel-dompdf**: PDF generation
- **bacon/bacon-qr-code**: QR code generation
- **midtrans/midtrans-php**: Payment gateway integration

### Common Commands
```bash
# Development setup
composer install
php artisan key:generate
php artisan migrate --seed

# Development server
php artisan serve                    # API server (port 8000)
php artisan reverb:start            # WebSocket server (port 8080)
php artisan queue:listen            # Queue worker

# Testing
php artisan test
php artisan test --coverage

# Database
php artisan migrate:fresh --seed    # Reset database
php artisan db:seed --class=DevelopmentSeeder

# Code quality
./vendor/bin/pint                   # Code formatting (Laravel Pint)
```

## Frontend Web (React + TypeScript)

### Core Technologies
- **Framework**: React 19 + TypeScript
- **Build Tool**: Vite 7
- **Styling**: TailwindCSS 4
- **State Management**: Zustand
- **HTTP Client**: Axios + TanStack Query
- **Routing**: React Router DOM 7
- **WebSocket**: Laravel Echo + Pusher

### Key Dependencies
- **@tanstack/react-query**: Server state management
- **laravel-echo**: WebSocket client
- **qrcode.react**: QR code display
- **recharts**: Charts and analytics
- **lucide-react**: Icon library

### Common Commands
```bash
# Development
npm install
npm run dev                         # Dev server (port 5173)

# Build
npm run build                       # Production build
npm run preview                     # Preview build

# Code quality
npm run lint                        # ESLint
```

## Mobile App (React Native)

### Core Technologies
- **Framework**: React Native 0.73
- **Navigation**: React Navigation 7
- **Camera**: React Native Vision Camera
- **QR Scanning**: vision-camera-code-scanner
- **Location**: @react-native-community/geolocation
- **Permissions**: react-native-permissions

### Common Commands
```bash
# Development
npm install
npm start                           # Metro bundler
npm run android                     # Android development
npm run ios                         # iOS development

# Code quality
npm run lint                        # ESLint
npm test                           # Jest tests
```

## Development Workflow

### Quick Start (All Services)
```bash
# Use the provided batch script (Windows)
start-dev.bat

# Or manually start each service:
# Terminal 1: Backend API
cd backend && php artisan serve

# Terminal 2: WebSocket
cd backend && php artisan reverb:start

# Terminal 3: Queue Worker
cd backend && php artisan queue:listen

# Terminal 4: Frontend
cd frontend-web && npm run dev
```

### Environment Configuration

**Backend (.env)**:
```env
DB_CONNECTION=pgsql
DB_DATABASE=absensi_qr_pro
REVERB_APP_KEY=your_websocket_key
MIDTRANS_SERVER_KEY=your_midtrans_key
WHATSAPP_API_KEY=your_whatsapp_key
```

**Frontend (.env)**:
```env
VITE_API_URL=http://localhost:8000/api/v1
VITE_REVERB_APP_KEY=your_websocket_key
VITE_MIDTRANS_CLIENT_KEY=your_midtrans_client_key
```

## Code Quality Standards

- **PHP**: PSR-12 coding standard (Laravel Pint)
- **TypeScript**: Strict mode enabled
- **ESLint**: React and TypeScript rules
- **Testing**: PHPUnit (backend), Jest (mobile)
- **Git**: Conventional commits preferred

## Deployment

- **Backend**: Laravel deployment (Nginx + PHP-FPM)
- **Frontend**: Static build deployment (Vercel/Netlify)
- **Mobile**: React Native build for iOS/Android stores
- **Database**: PostgreSQL with regular backups
- **WebSocket**: Laravel Reverb in production mode