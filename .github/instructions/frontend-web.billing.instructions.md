---
description: "Use when editing frontend-web billing pages, package management, payments, or invoice flows; keep billing logic consistent and presentation clean."
applyTo: "frontend-web/src/pages/SuperAdmin/{SubscriptionPackages.tsx,PackageLimits.tsx,PaymentHistory.tsx,InvoiceManagement.tsx}"
---

# Frontend-web billing guidance

- Treat billing pages as operational UIs around packages, limits, payments, and invoice data.
- Reuse the existing billing fetch and print flows instead of recreating package or invoice logic inside components.
- Keep currency formatting, invoice rendering, payment history tables, and package limit controls consistent across billing screens.
- Avoid duplicating the same billing calculations in multiple pages; centralize shared logic where it already exists.
- Keep loading, empty, error, and print/export states aligned with the surrounding super-admin UX.
- Prefer the current source tree and frontend docs over README wording when they conflict.
- Before editing, inspect the target billing page and any shared helpers or services it depends on.
- Validate with the narrowest relevant billing test, lint, or typecheck path first.
