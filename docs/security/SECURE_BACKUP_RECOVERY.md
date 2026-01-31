# Secure Database Backup & Recovery

This document details the security procedures for backing up and recovering the AbsensiQRPro database.

## Backup Security Architecture

1.  **Encryption at Rest**:
    - All backups are encrypted **before** writing to disk or uploading to cloud.
    - Algorithm: **AES-256** via GPG (GNU Privacy Guard).
    - Mode: Symmetric encryption (Passphrase protected).
    - No raw `.sql` files are ever stored.

2.  **Storage**:
    - Local retention: 7 days.
    - Cloud upload (Optional): S3-compatible storage.

3.  **Command Path**:
    `pg_dump` (stdout) -> `gpg` (encryption) -> `.sql.gpg` file

## Configuration

Ensure the following variables are set in `.env`:

```env
# Database Credentials
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=absensi
DB_USERNAME=postgres
DB_PASSWORD=your-secure-password

# Backup Security
BACKUP_ENCRYPTION_KEY="your-strong-random-passphrase-here"
```

> **WARNING**: Loss of `BACKUP_ENCRYPTION_KEY` results in permanent data loss of all backups. Store this key securely (e.g., usage password manager).

## Creating a Backup

Run the scheduled command manually:

```bash
php artisan backup:database
```

To upload to configured S3 storage:

```bash
php artisan backup:database --upload
```

## Recovery / Restoration Process

To restore the database, you must decrypt the backup file first.

### Prerequisites
- `gpg` installed on the target machine.
- The `BACKUP_ENCRYPTION_KEY` used to create the backup.
- PostgreSQL client (`psql`).

### Step 1: Decrypt the Backup

Use GPG to decrypt the file. You will be prompted for the passphrase.

```bash
# Interactive (Prompt for password)
gpg --decrypt -o restored_dump.sql backup_2024-01-29_100000.sql.gpg

# Non-interactive (Automated)
gpg --decrypt --batch --passphrase "your-key" -o restored_dump.sql backup_...sql.gpg
```

### Step 2: Restore to Database

**WARNING**: This will overwrite existing data.

```bash
# Drop existing DB (Optional/Dangerous)
dropdb -h localhost -U postgres absensi
createdb -h localhost -U postgres absensi

# Import Data
psql -h localhost -U postgres -d absensi < restored_dump.sql
```

### Step 3: Cleanup

Delete the decrypted file immediately after restoration:

```bash
rm restored_dump.sql
```

## Troubleshooting

**Error: `BACKUP_ENCRYPTION_KEY is not set`**
- Defined the key in `.env` file.

**Error: `gpg: decryption failed: No secret key`**
- Verified the correct password/phrase is being used.

**Error: `pg_dump: command not found`**
- Ensure postgresql-client tools are installed and in system PATH.
