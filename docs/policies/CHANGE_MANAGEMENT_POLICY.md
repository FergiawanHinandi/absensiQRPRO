# Change Management Policy

## 1. Purpose
To ensure that changes to the production environment are managed systematically to minimize risk and disruption.

## 2. Types of Changes
- **Standard Change:** Low risk, pre-authorized (e.g., minor UI text update).
- **Normal Change:** Moderate risk, requires review (e.g., new feature, DB migration).
- **Emergency Change:** Critical fix for outage or security vulnerability (e.g., Hotfix).

## 3. Change Procedure (Normal)

1. **Request:** Developer creates a Ticket (Jira/Linear) describing the change and rollback plan.
2. **Development:** Code is written in a feature branch.
3. **Review:** 
    - Peer Code Review (GitHub PR).
    - Automated CI/CD checks (Linting, Tests, Security Scan).
4. **Approval:** Tech Lead approves the PR.
5. **Staging:** Deployed to Staging environment for QA.
6. **Deployment:** Deployed to Production during maintenance window (if downtime required).
7. **Verification:** Smoke tests performed post-deployment.

## 4. Emergency Change Procedure
1. **Authorization:** Verbal/Chat approval from CTO/Incident Commander is sufficient.
2. **Execution:** Fix deployed immediately (Hotfix branch).
3. **Documentation:** Ticket and formal Code Review must be completed *retroactively* within 24 hours.

## 5. Rollback Plan
- All changes must include a reversion strategy (e.g., `php artisan migrate:rollback`, Revert Git Commit).
- Database backups must be verified *before* applying Schema changes.

## 6. Audit Trail
- All deployments are logged in the CI/CD system.
- Significant configuration changes in Production must be logged in `ImmutableSecurityLog`.
