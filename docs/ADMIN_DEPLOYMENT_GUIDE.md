# School Admin Dashboard - Deployment Guide

## 🚀 Deployment Overview

This guide covers the deployment process for the School Admin dashboard integration.

## 📋 Pre-Deployment Checklist

### Code Quality
- [ ] All TypeScript errors resolved
- [ ] No ESLint warnings
- [ ] All imports are correct
- [ ] No unused variables/imports
- [ ] Code is properly formatted

### Testing
- [ ] All unit tests passing
- [ ] Integration tests passing
- [ ] Manual testing completed
- [ ] User acceptance testing done
- [ ] Performance testing passed

### Documentation
- [ ] API documentation updated
- [ ] User guide created
- [ ] Technical documentation complete
- [ ] Changelog updated

### Security
- [ ] Environment variables secured
- [ ] API keys not in code
- [ ] CORS configured correctly
- [ ] CSRF protection enabled
- [ ] Rate limiting configured

---

## 🔧 Environment Setup

### 1. Production Environment Variables

Create `.env.production` file:

```bash
# API Configuration
VITE_API_URL=https://api.yourschool.com/api/v1

# Application Configuration
VITE_APP_NAME=AbsensiQRPro
VITE_APP_VERSION=1.0.0

# Feature Flags
VITE_ENABLE_ANALYTICS=true
VITE_ENABLE_ERROR_TRACKING=true

# Analytics (if enabled)
VITE_GA_TRACKING_ID=UA-XXXXXXXXX-X

# Error Tracking (if enabled)
VITE_SENTRY_DSN=https://xxxxx@sentry.io/xxxxx

# Production
VITE_DEBUG_MODE=false
```

### 2. Backend Configuration

Ensure backend `.env` has:

```bash
# CORS
CORS_ALLOWED_ORIGINS=https://yourschool.com

# Session
SESSION_DOMAIN=.yourschool.com
SESSION_SECURE_COOKIE=true

# API Rate Limiting
API_RATE_LIMIT=60
API_RATE_LIMIT_WINDOW=1

# File Upload
MAX_UPLOAD_SIZE=5120  # 5MB in KB
```

---

## 📦 Build Process

### 1. Install Dependencies

```bash
cd frontend-web
npm ci  # Use ci for production (faster, more reliable)
```

### 2. Run Build

```bash
npm run build
```

This will:
- Compile TypeScript to JavaScript
- Bundle all files with Vite
- Minify CSS and JS
- Optimize images
- Generate source maps (if configured)
- Output to `dist/` folder

### 3. Verify Build

```bash
# Check build output
ls -lh dist/

# Preview production build locally
npm run preview
```

Expected output structure:
```
dist/
├── assets/
│   ├── index-[hash].js
│   ├── index-[hash].css
│   └── [other assets]
├── index.html
└── favicon.ico
```

---

## 🌐 Deployment Options

### Option 1: Static Hosting (Recommended)

#### Vercel
```bash
# Install Vercel CLI
npm i -g vercel

# Deploy
cd frontend-web
vercel --prod
```

#### Netlify
```bash
# Install Netlify CLI
npm i -g netlify-cli

# Deploy
cd frontend-web
netlify deploy --prod --dir=dist
```

#### AWS S3 + CloudFront
```bash
# Build
npm run build

# Upload to S3
aws s3 sync dist/ s3://your-bucket-name --delete

# Invalidate CloudFront cache
aws cloudfront create-invalidation --distribution-id YOUR_DIST_ID --paths "/*"
```

### Option 2: Docker Deployment

Create `Dockerfile`:

```dockerfile
# Build stage
FROM node:20-alpine AS builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# Production stage
FROM nginx:alpine
COPY --from=builder /app/dist /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
```

Create `nginx.conf`:

```nginx
server {
    listen 80;
    server_name _;
    root /usr/share/nginx/html;
    index index.html;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;

    # Cache static assets
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # SPA routing
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

Build and run:

```bash
# Build image
docker build -t absensi-frontend .

# Run container
docker run -d -p 80:80 absensi-frontend
```

### Option 3: Traditional Server (Apache/Nginx)

#### Nginx Configuration

```nginx
server {
    listen 80;
    server_name yourschool.com;
    root /var/www/absensi/dist;
    index index.html;

    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourschool.com;
    root /var/www/absensi/dist;
    index index.html;

    # SSL Configuration
    ssl_certificate /etc/ssl/certs/yourschool.crt;
    ssl_certificate_key /etc/ssl/private/yourschool.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Gzip
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;

    # Cache
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # SPA routing
    location / {
        try_files $uri $uri/ /index.html;
    }

    # API proxy (optional)
    location /api/ {
        proxy_pass http://localhost:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Deploy:

```bash
# Copy build files
scp -r dist/* user@server:/var/www/absensi/

# Restart Nginx
ssh user@server 'sudo systemctl restart nginx'
```

---

## 🔍 Post-Deployment Verification

### 1. Smoke Tests

```bash
# Check if site is accessible
curl -I https://yourschool.com

# Check API connectivity
curl https://yourschool.com/api/v1/health
```

### 2. Manual Verification

- [ ] Homepage loads correctly
- [ ] Login works
- [ ] Dashboard displays data
- [ ] All admin pages accessible
- [ ] API calls succeed
- [ ] Export functionality works
- [ ] Images load correctly
- [ ] No console errors

### 3. Performance Check

Use tools:
- **Lighthouse** (Chrome DevTools)
- **GTmetrix**
- **WebPageTest**

Target metrics:
- First Contentful Paint: <1.5s
- Largest Contentful Paint: <2.5s
- Time to Interactive: <3.5s
- Cumulative Layout Shift: <0.1

### 4. Security Check

- [ ] HTTPS enabled
- [ ] Security headers present
- [ ] No sensitive data in console
- [ ] API keys not exposed
- [ ] CORS configured correctly

---

## 📊 Monitoring Setup

### 1. Error Tracking (Sentry)

Install Sentry:

```bash
npm install @sentry/react @sentry/tracing
```

Configure in `main.tsx`:

```typescript
import * as Sentry from "@sentry/react";

if (import.meta.env.PROD) {
  Sentry.init({
    dsn: import.meta.env.VITE_SENTRY_DSN,
    integrations: [new Sentry.BrowserTracing()],
    tracesSampleRate: 1.0,
  });
}
```

### 2. Analytics (Google Analytics)

Install GA:

```bash
npm install react-ga4
```

Configure:

```typescript
import ReactGA from 'react-ga4';

if (import.meta.env.VITE_ENABLE_ANALYTICS === 'true') {
  ReactGA.initialize(import.meta.env.VITE_GA_TRACKING_ID);
}
```

### 3. Performance Monitoring

Use built-in browser APIs:

```typescript
// Log performance metrics
window.addEventListener('load', () => {
  const perfData = window.performance.timing;
  const pageLoadTime = perfData.loadEventEnd - perfData.navigationStart;
  console.log('Page load time:', pageLoadTime, 'ms');
});
```

---

## 🔄 CI/CD Pipeline

### GitHub Actions Example

Create `.github/workflows/deploy.yml`:

```yaml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup Node.js
        uses: actions/setup-node@v3
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: frontend-web/package-lock.json
      
      - name: Install dependencies
        working-directory: frontend-web
        run: npm ci
      
      - name: Run tests
        working-directory: frontend-web
        run: npm test
      
      - name: Build
        working-directory: frontend-web
        run: npm run build
        env:
          VITE_API_URL: ${{ secrets.VITE_API_URL }}
      
      - name: Deploy to Vercel
        uses: amondnet/vercel-action@v20
        with:
          vercel-token: ${{ secrets.VERCEL_TOKEN }}
          vercel-org-id: ${{ secrets.VERCEL_ORG_ID }}
          vercel-project-id: ${{ secrets.VERCEL_PROJECT_ID }}
          working-directory: frontend-web
```

---

## 🆘 Rollback Procedure

If deployment fails:

### Quick Rollback

```bash
# Vercel
vercel rollback

# Netlify
netlify rollback

# Manual (restore previous build)
aws s3 sync s3://backup-bucket/previous-build/ s3://your-bucket-name --delete
```

### Full Rollback

1. Revert Git commit
2. Rebuild from previous commit
3. Redeploy

```bash
git revert HEAD
git push origin main
# CI/CD will auto-deploy
```

---

## 📝 Deployment Checklist

### Pre-Deployment
- [ ] Code reviewed and approved
- [ ] All tests passing
- [ ] Documentation updated
- [ ] Environment variables configured
- [ ] Backup created

### Deployment
- [ ] Build successful
- [ ] Files uploaded
- [ ] Cache cleared
- [ ] DNS configured (if needed)
- [ ] SSL certificate valid

### Post-Deployment
- [ ] Smoke tests passed
- [ ] Manual verification done
- [ ] Performance acceptable
- [ ] Monitoring active
- [ ] Team notified

### Rollback Plan
- [ ] Previous build backed up
- [ ] Rollback procedure documented
- [ ] Team knows rollback process

---

## 📞 Support Contacts

| Issue Type | Contact | Response Time |
|------------|---------|---------------|
| Critical (site down) | DevOps Team | 15 minutes |
| High (feature broken) | Frontend Team | 1 hour |
| Medium (minor bug) | Support Team | 4 hours |
| Low (enhancement) | Product Team | 1 day |

---

**Last Updated**: February 2026  
**Version**: 1.0.0  
**Status**: Ready for Deployment
