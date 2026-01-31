# Security Guidelines - AbsensiQRPro

## 🔒 Overview

This document outlines security best practices for developing and deploying AbsensiQRPro.

---

## 🚨 Critical Rules

### ❌ NEVER Commit These:

1. **Environment Files**
   - `.env` (production or development)
   - Any file containing real credentials

2. **Secret Keys**
   - `APP_KEY` with actual base64 values
   - `QR_SECRET_KEY` with real keys
   - `DB_PASSWORD` with actual passwords
   - `JWT_SECRET` with real values

3. **API Credentials**
   - Bearer tokens
   - Sanctum tokens
   - Third-party API keys
   - OAuth client secrets

4. **Certificates & Keys**
   - `.pem` files
   - `.key` files
   - `.p12` or `.pfx` files
   - Private SSH keys

5. **Cloud Credentials**
   - AWS access keys
   - Azure credentials
   - Google Cloud service accounts

---

## ✅ Safe Practices

### 1. Use Environment Variables

**❌ BAD:**
```php
$apiKey = 'sk_live_1234567890abcdef';
```

**✅ GOOD:**
```php
$apiKey = config('services.payment.api_key');
```

### 2. Use .env.example as Template

**`.env.example`** should contain:
```env
APP_KEY=
DB_PASSWORD=your-secure-password
QR_SECRET_KEY=your-secret-key-here
```

**`.env`** (NEVER commit):
```env
APP_KEY=base64:your-app-key-placeholder
DB_PASSWORD=your-secure-password
QR_SECRET_KEY=your-secret-key-here
```

### 3. Rotate Secrets Regularly

- Change `APP_KEY` when team members leave
- Rotate API keys every 90 days
- Update database passwords quarterly

### 4. Use Separate Credentials Per Environment

```env
# Development
DB_PASSWORD=your-secure-password

# Staging
DB_PASSWORD=your-secure-password

# Production
DB_PASSWORD=your-secure-password
```

---

## 🛡️ Pre-commit Hook Protection

### Setup

```bash
# Enable pre-commit hook (REQUIRED)
git config core.hooksPath .githooks

# Verify
git config core.hooksPath
# Output: .githooks
```

### What It Blocks

The pre-commit hook scans for:

1. **APP_KEY patterns**
   ```
   APP_KEY=base64:[A-Za-z0-9+/]{40,}
   ```

2. **Database passwords**
   ```
   DB_PASSWORD=your-secure-password
   ```

3. **QR secret keys**
   ```
   QR_SECRET_KEY=[actual key]
   ```

4. **API tokens**
   ```
   SANCTUM_TOKEN=...
   API_TOKEN=...
   BEARER_TOKEN=...
   ```

5. **Private keys**
   ```
   -----BEGIN PRIVATE KEY-----
   ```

6. **AWS credentials**
   ```
   AWS_ACCESS_KEY_ID=...
   AWS_SECRET_ACCESS_KEY=...
   ```

7. **Certificate files**
   ```
   *.pem, *.key, *.p12, *.pfx
   ```

### Testing the Hook

```bash
# Create a test file with a secret
echo "APP_KEY=base64:testkey123456789012345678901234567890" > test.txt
git add test.txt
git commit -m "test"

# Expected output:
# ❌ BLOCKED: APP_KEY with actual value detected!
# Never commit APP_KEY from .env file
```

### Bypass (Emergency Only)

```bash
# Only use if absolutely necessary
git commit --no-verify -m "emergency fix"

# ⚠️ WARNING: This bypasses ALL security checks
# Use only for:
# - Fixing broken builds
# - Emergency hotfixes
# - After manual verification
```

---

## 🔍 CI/CD Secret Scanning

### GitHub Actions Workflow

Location: `.github/workflows/secret-scan.yml`

**Runs on:**
- Every push to `main`, `develop`, `staging`
- Every pull request

**Scans for:**
- `.env` files in repository
- `APP_KEY` in commit history
- Database passwords in commits
- QR secret keys
- Private keys
- API tokens
- AWS credentials
- Certificate files

**Failure Actions:**
- ❌ Fails the build
- 📧 Notifies team
- 🚫 Blocks merge

### Example CI Output

```
🔍 Scanning for secrets...
✅ No .env files found
✅ No APP_KEY patterns found
✅ No database passwords found
✅ No QR secret keys found
✅ No private keys found
✅ No API tokens found
✅ No AWS credentials found
✅ No certificate files found

╔══════════════════════════════════════════════════════════════╗
║  ✅ Security Scan PASSED                                     ║
║  No secrets or sensitive data detected                       ║
╚══════════════════════════════════════════════════════════════╝
```

---

## 🚑 What to Do If You Commit a Secret

### 1. **STOP** - Don't Push

If you haven't pushed yet:

```bash
# Remove the commit
git reset HEAD~1

# Remove the secret from files
# Edit the file to remove the secret

# Commit again (hook will verify)
git add .
git commit -m "fix: remove secret"
```

### 2. If Already Pushed - Immediate Actions

**⚠️ CRITICAL: The secret is now compromised**

1. **Rotate the secret immediately**
   ```bash
   # For APP_KEY
   php artisan key:generate --force
   
   # For database password
   # Change in database AND .env
   
   # For API keys
   # Revoke old key, generate new one
   ```

2. **Remove from Git history**
   ```bash
   # Use BFG Repo-Cleaner or git-filter-repo
   # WARNING: This rewrites history
   
   # Install BFG
   # Download from: https://rtyley.github.io/bfg-repo-cleaner/
   
   # Remove secrets
   bfg --replace-text secrets.txt
   
   # Force push (coordinate with team)
   git push --force
   ```

3. **Notify the team**
   - Inform all developers
   - Update credentials in production
   - Check access logs for unauthorized use

### 3. Prevention Checklist

- [ ] Rotate all exposed secrets
- [ ] Update production environment variables
- [ ] Check application logs for suspicious activity
- [ ] Review access logs
- [ ] Update `.env.example` if needed
- [ ] Document the incident
- [ ] Review security practices with team

---

## 📋 Security Checklist

### Before Committing

- [ ] No `.env` files staged
- [ ] No hardcoded passwords
- [ ] No API keys in code
- [ ] No private keys
- [ ] Pre-commit hook is active
- [ ] Secrets are in environment variables

### Before Deploying

- [ ] All secrets in environment variables
- [ ] `.env` is in `.gitignore`
- [ ] Production secrets are different from dev
- [ ] Secrets are rotated regularly
- [ ] Access logs are monitored
- [ ] Security scan passed in CI

### Regular Maintenance

- [ ] Rotate secrets every 90 days
- [ ] Review access logs monthly
- [ ] Update dependencies weekly
- [ ] Security audit quarterly
- [ ] Team training annually

---

## 🔗 Related Documentation

- [Security Update - System Monitor](./SECURITY_UPDATE_SYSTEM_MONITOR.md)
- [System Health Monitoring](./api/SYSTEM_HEALTH_MONITORING.md)
- [API Documentation](./api/)
- [Deployment Guide](./deployment/)

---

## 📞 Security Contacts

**Report Security Issues:**
- Email: security@absensiQRPro.com
- Slack: #security-alerts
- Emergency: +62 823-5253-8105

**Security Team:**
- Lead: Fergiawan Hinandi
- DevSecOps: [Your Name]

---

## 📚 Additional Resources

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [GitHub Secret Scanning](https://docs.github.com/en/code-security/secret-scanning)
- [Laravel Security Best Practices](https://laravel.com/docs/security)
- [Git Secrets Tool](https://github.com/awslabs/git-secrets)

---

**Last Updated:** January 28, 2026  
**Version:** 1.0.0  
**Status:** ✅ Active
