# Project Structure & Organization

## Repository Layout

```
absensiQRPro/
├── backend/                    # Laravel 11 API Backend
├── frontend-web/               # React TypeScript Frontend  
├── AbsensiQRMobile/           # React Native Mobile App
├── docs/                      # Project Documentation
├── start-dev.bat             # Development launcher script
└── README.md                 # Main project documentation
```

## Backend Structure (Laravel)

```
backend/
├── app/
│   ├── Core/                  # Domain layer
│   │   ├── Domain/
│   │   │   └── Repositories/  # Repository interfaces
│   │   └── Services/          # Core business domain services
│   │       ├── Attendance/    # Attendance domain services
│   │       ├── Payment/       # Payment domain services
│   │       └── RateLimit/     # Rate limiting domain services
│   │           ├── SlidingWindowCounter.php
│   │           ├── TenantKeyBuilder.php
│   │           ├── RateLimiterService.php
│   │           ├── AdminBypassService.php
│   │           └── RateLimitLogger.php
│   ├── Infrastructure/        # Infrastructure layer
│   │   └── Repositories/      # Repository implementations
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/          # API controllers (versioned)
│   │   │   └── Web/          # Web controllers
│   │   ├── Middleware/       # Custom middleware
│   │   └── Requests/         # Form request validation
│   ├── Models/               # Eloquent models
│   │   └── DR/               # Disaster recovery models
│   ├── Services/             # Application services
│   │   ├── DR/               # Disaster recovery services
│   │   │   ├── AlertManager.php
│   │   │   ├── AuditTrailSystem.php
│   │   │   ├── BackupEncryption.php
│   │   │   └── TenantSecurityValidator.php
│   │   └── Redis/            # Redis HA services
│   │       ├── CacheWarmingService.php
│   │       ├── QueueRecoveryService.php
│   │       └── ResilientRedisConnection.php
│   ├── Jobs/                 # Queue jobs
│   ├── Events/               # Domain events
│   ├── Listeners/            # Event listeners
│   ├── Policies/             # Authorization policies
│   └── Traits/               # Reusable traits
├── config/                   # Configuration files
│   ├── rate-limiting.php     # Rate limit endpoint config
│   ├── disaster_recovery.php # DR scenarios, RTO/RPO config
│   └── backup.php            # Spatie backup config
├── database/
│   ├── migrations/           # Database schema migrations
│   └── seeders/              # Database seeders
├── docs/                     # Backend-specific documentation
├── resources/
│   └── views/
│       └── reports/          # PDF report templates
├── routes/
│   └── api.php              # API routes (versioned)
└── tests/
    ├── Feature/             # Integration tests
    └── Unit/                # Unit tests
```

### Key Backend Patterns

- **Repository Pattern**: Data access abstraction in `Core/Domain/Repositories/`
- **Service Layer**: Business logic in `Services/` and `Core/Services/`
- **Domain Events**: Event-driven architecture in `Events/` and `Listeners/`
- **Multi-Tenant**: School-scoped models using `BelongsToSchool` trait
- **RBAC**: Role-based access control with Spatie Permission

## Frontend Structure (React)

```
frontend-web/
├── src/
│   ├── components/
│   │   ├── common/           # Reusable UI components
│   │   ├── dashboard/        # Dashboard-specific components
│   │   ├── layout/           # Layout components
│   │   ├── modals/           # Modal dialogs
│   │   └── ui/               # Base UI components
│   ├── config/
│   │   └── navigation.ts     # Navigation configuration
│   ├── lib/
│   │   ├── api.ts           # Axios configuration
│   │   └── echo.ts          # WebSocket configuration
│   ├── modules/             # Feature modules
│   │   ├── admin/           # Admin module
│   │   ├── auth/            # Authentication module
│   │   ├── billing/         # Billing/payment module
│   │   ├── parent/          # Parent portal module
│   │   └── teacher/         # Teacher module
│   ├── pages/               # Page components
│   │   ├── Admin/           # Admin pages
│   │   ├── Parent/          # Parent pages
│   │   ├── Principal/       # Principal pages
│   │   ├── SuperAdmin/      # Super admin pages
│   │   └── Teacher/         # Teacher pages
│   ├── store/               # Zustand stores
│   ├── types/               # TypeScript type definitions
│   └── utils/               # Utility functions
├── public/                  # Static assets
└── index.html              # Entry HTML file
```

### Key Frontend Patterns

- **Module-based Architecture**: Features organized in `modules/`
- **Component Composition**: Reusable components in `components/`
- **State Management**: Zustand stores for global state
- **Type Safety**: Comprehensive TypeScript types in `types/`
- **API Layer**: Centralized API client with interceptors

## Mobile Structure (React Native)

```
AbsensiQRMobile/
├── src/
│   ├── api/                 # API client and services
│   ├── constants/           # App constants
│   ├── legacy_expo/         # Legacy Expo components (to be migrated)
│   │   ├── components/
│   │   ├── hooks/
│   │   ├── navigation/
│   │   ├── screens/
│   │   ├── services/
│   │   └── store/
│   ├── navigation/          # New navigation structure
│   ├── screens/             # New screen components
│   │   ├── attendance/      # Attendance screens
│   │   ├── auth/           # Authentication screens
│   │   └── dashboard/      # Dashboard screens
│   └── utils/              # Utility functions
├── android/                # Android-specific code
├── ios/                    # iOS-specific code
└── __tests__/             # Test files
```

### Key Mobile Patterns

- **Screen-based Navigation**: React Navigation with stack navigators
- **Hook-based State**: Custom hooks for business logic
- **Platform Separation**: Platform-specific code in `android/` and `ios/`
- **Legacy Migration**: Gradual migration from `legacy_expo/` to new structure

## Documentation Structure

```
docs/
├── architecture/           # System architecture docs
├── core_design/           # Core design documents
├── implementation/        # Implementation guides
└── tasks/                # Task-specific documentation
```

## Configuration Files

### Backend Configuration
- `composer.json`: PHP dependencies and scripts
- `.env.example`: Environment template
- `config/`: Laravel configuration files
- `phpunit.xml`: Testing configuration

### Frontend Configuration  
- `package.json`: Node.js dependencies and scripts
- `vite.config.ts`: Vite build configuration
- `tsconfig.json`: TypeScript configuration
- `eslint.config.js`: ESLint rules

### Mobile Configuration
- `package.json`: React Native dependencies
- `metro.config.js`: Metro bundler configuration
- `android/`: Android build configuration
- `ios/`: iOS build configuration

## Naming Conventions

- **Files**: kebab-case for components, PascalCase for classes
- **Directories**: kebab-case for features, camelCase for utilities
- **API Routes**: RESTful with version prefix (`/api/v1/`)
- **Database**: snake_case for tables and columns
- **Models**: PascalCase singular (e.g., `User`, `AttendanceLog`)
- **Controllers**: PascalCase with suffix (e.g., `AttendanceController`)

## Development Guidelines

- **Backend**: Follow Laravel conventions and PSR-12 standards
- **Frontend**: Use TypeScript strict mode and React best practices
- **Mobile**: Follow React Native community guidelines
- **Testing**: Write tests for critical business logic
- **Documentation**: Keep README files updated in each module