# Access Control Policy

## 1. Overview
This policy defines the standards for user authentication, authorization, and access management within the AbsensiQRPro system. It ensures that only authorized individuals have access to sensitive attendance data and system administrative functions.

## 2. Scope
This policy applies to all users (Super Admin, School Admin, Teacher, Student, Parent) and system components (Production, Staging, Database).

## 3. Principles
- **Least Privilege:** Users are granted only the minimum access necessary to perform their job.
- **Role-Based Access Control (RBAC):** Access is assigned based on job function, not individual identity.
- **Segregation of Duties:** Critical functions (e.g., modifying attendance records vs. approving them) are separated.

## 4. User Registration & Modification
- **User Creation:** Only Super Admins can create School Admin accounts. School Admins can create Teachers/Students.
- **Deactivation:** Access must be revoked within 24 hours of termination (Employee) or graduation (Student).
- **Modification:** Role changes require approval from a Super Admin and are logged in the `ImmutableSecurityLog`.

## 5. Password & Authentication
- **Strong Passwords:** Minimum 12 characters, including uppercase, lowercase, numbers, and symbols.
- **Hashing:** Passwords are never stored in plain text. Use bcrypt/Argon2.
- **Multi-Factor Authentication (MFA):** Mandatory for all Admins (Super & School). Optional for Teachers.
- **Session Management:** Sessions expire after 2 hours of inactivity.

## 6. Access Reviews
- **Frequency:** Monthly (Automated Report).
- **Reviewer:** Compliance Officer / Admin.
- **Process:** Verify all active accounts are still valid employees/students. Revoke unnecessary permissions.

## 7. API Access
- **API Keys:** Issued only for system-to-system integration.
- **Rotation:** Keys must be rotated every 90 days.
- **Logging:** All API requests are logged with IP address and response status.

## 8. Enforcement
Violations of this policy may result in disciplinary action, up to and including termination.
