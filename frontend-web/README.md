# AbsensiQR Pro — Frontend Web (Admin Dashboard)

React 19 + TypeScript admin dashboard for the AbsensiQR Pro multi-tenant attendance system.

## Tech Stack

- **React 19** with TypeScript
- **Vite** — build tool & dev server
- **TailwindCSS** — utility-first styling
- **Zustand** — lightweight state management (`useAuthStore`)
- **TanStack Query** (React Query) — server-state with 5-min staleTime
- **Axios** — HTTP client with auto-injected bearer token & 401 redirect

## Getting Started

```bash
npm install
cp .env.example .env     # set VITE_API_URL
npm run dev               # http://localhost:5173
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `VITE_API_URL` | `http://localhost:8000/api/v1` | Backend API base URL |

## Project Structure

```
src/
├── lib/api.ts                 # Axios instance (bearer, 401, 503 interceptors)
├── modules/
│   ├── auth/                  # Login, stores, guards
│   ├── dashboard/             # Role-based dashboards
│   ├── students/              # Student CRUD
│   ├── teachers/              # Teacher management
│   ├── schedules/             # Schedule management
│   ├── attendance/            # Attendance reports
│   └── settings/              # School settings
├── utils/toast.ts             # showToast.success / .error / .warning
├── App.tsx                    # Role-based routing
└── main.tsx                   # React Query provider, router
```

## Role-Based Access

| Role | Dashboard Path |
|------|---------------|
| `super_admin` | `/super-admin/dashboard` |
| `admin` / `school_admin` | `/admin/dashboard` |
| `teacher` / `homeroom_teacher` | `/teacher/dashboard` |

## Scripts

| Command | Description |
|---------|-------------|
| `npm run dev` | Start Vite dev server |
| `npm run build` | Production build to `dist/` |
| `npm test` | Run Vitest |
| `npm run lint` | ESLint check |

## Key Conventions

- **Auth tokens** stored in `sessionStorage` (not localStorage) via `tokenStore`
- **Toast notifications** via `showToast` — never use `alert()`
- **API errors**: 401 → auto-redirect to login; 503 → maintenance mode page
