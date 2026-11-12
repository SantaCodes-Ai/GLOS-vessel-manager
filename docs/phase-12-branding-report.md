# Phase 12 — Company branding, Staff terminology and dashboard greetings

Implemented 9 November 2026.

## Branding and exact asset

The supplied project-root GLOS logo.jpg was inspected and copied unchanged to public/assets/branding/GLOS logo.jpg. No replacement was generated/downloaded, and the JPEG was not cropped, recompressed or stretched. A regression test compares the two files' SHA-256 hashes.

The shared logo partial supplies the company's full name as alt text, intrinsic 772 × 228 dimensions and responsive width/automatic height. It appears in Administrator and Staff navigation and the shared authentication layout (login, registration, registration notice/verification). A white logo panel keeps the original company lettering readable.

## Exact colour palette

| Purpose | Colour |
| --- | --- |
| Primary navigation, actions, headings and links | #31539A |
| Primary action/navigation hover | #27437D |
| Restrained logo/card border accents | #ED7137 |
| Light page background | #F6F8FB |
| Cards/panels and logo background | #FFFFFF |
| Dark neutral text | #172C43 |

Blue against white has a calculated WCAG contrast ratio of 7.41:1; hover blue against white is 9.61:1; dark text against the neutral background is 13.36:1. Orange is used for non-text accents, not small text on white. Sidebar focus and current-page indicators use white. Existing green success messages and blue/green/yellow/orange/red/unknown certificate-expiry badges were retained; expiry CSS was compared with the pre-change snapshot and is unchanged.

Existing responsive layouts and collapsible navigation remain. No gradients, animations, new frontend dependencies or changes to the Vite build are needed; shared inline core styles load after the existing Vite styles.

## Terminology and personalised greetings

Visible Client/Clients labels now read Staff/Staff Members on the Staff dashboard/workspace, administrator account listing/forms/details, navigation, registration role selection, current role displays, registration review details/listing, deletion review metadata, success/error messages and new-registration notification copy.

Internal client role values, enum cases, route names/URLs, controller names, model methods, CSS hooks, request field names and queued requested-role data remain unchanged. Existing historical role values/audit reasons are not rewritten. The only ManagePrivileges change is a user-visible validation message; its permissions, transactions and auditing remain unchanged.

Both active dashboards render “Welcome back, [first name]!” using a shared presentation partial. It obtains the authenticated user's name, trims/splits whitespace, escapes output through Blade and uses “there” for a missing/blank name. There is no name update or database migration.

## Automated verification

- Targeted suite: **117 tests passed, 743 assertions**, zero failures.
- Full regression suite: **374 tests passed, 2,196 assertions**, zero failures/errors/skips reported.
- Changed-file Laravel Pint: **passed**, eight PHP files checked; Blade files are not formatted by Pint.
- PHP syntax: all 98 PHP files under app, routes, config, database and tests passed php -l.
- Logo identity and unchanged expiry CSS checks passed.

Targeted command:

    php artisan test tests/Feature/BrandingTest.php tests/Feature/AdminDashboardTest.php tests/Feature/ClientAccessTest.php tests/Feature/AuthenticationTest.php tests/Feature/RegistrationTest.php tests/Feature/ClientManagementTest.php

Full suite command:

    php artisan test

The new BrandingTest covers exact logo identity, logo rendering/accessibility on shared authentication pages and both dashboards, first names/whitespace/Unicode/missing names/escaping, Staff terminology on management and registration screens, unchanged client role data and notification labels. Existing tests were updated only for the requested new greetings and visible Staff labels. Existing account creation/password hashing, assignment checks, registration, role restrictions, approvals, deletion, expiry/renewal and private/current/historical PDF security tests remain in the full suite.

All feature tests use the existing fail-closed, in-memory SQLite guard with isolated test data. No existing MySQL records, migrations, credentials, departments, audit records, PDF files or email transport settings were changed. No real email was sent.

## Browser checks still requiring manual verification

No interactive browser or visual device checks were performed during this phase. Before release, verify:

1. Login, registration and verification/notice pages show the entire official logo at desktop and narrow mobile widths.
2. Admin and Staff dashboards show the signed-in first name, correct company branding and readable statistics/expiry badges.
3. Mobile Menu and submenu expansion, active-page indicators, keyboard focus/Enter/Escape, deletion/rejection dialogs and sign-out continue to work.
4. Staff management forms, requested-role labels, validation messages and approval details show Staff; internal URLs may still contain client by design.
5. Preview/download of current and historical authorised PDFs and access revocation still behave as expected in the browser.

The supplied JPEG has its original raster resolution/quality; it is intentionally not enlarged beyond the authentication logo size or replaced with another asset.

## Files created

- `public/assets/branding/GLOS logo.jpg`
- `resources/views/shared/brand-styles.blade.php`
- `resources/views/shared/company-logo.blade.php`
- `resources/views/shared/dashboard-greeting.blade.php`
- `resources/views/shared/role-label.blade.php`
- `tests/Feature/BrandingTest.php`
- `docs/phase-12-branding-report.md`

## Files modified

- `app/Http/Controllers/Admin/ClientController.php`
- `app/Notifications/RegistrationMessage.php`
- `app/Support/ManagePrivileges.php`
- `resources/views/admin/clients/form.blade.php`
- `resources/views/admin/clients/index.blade.php`
- `resources/views/admin/clients/show.blade.php`
- `resources/views/admin/dashboard.blade.php`
- `resources/views/admin/styles.blade.php`
- `resources/views/admin/users/index.blade.php`
- `resources/views/auth/register.blade.php`
- `resources/views/client/dashboard.blade.php`
- `resources/views/layouts/admin.blade.php`
- `resources/views/layouts/auth.blade.php`
- `resources/views/layouts/client.blade.php`
- `resources/views/super-admin/change.blade.php`
- `resources/views/super-admin/delete-user.blade.php`
- `resources/views/super-admin/registration.blade.php`
- `resources/views/super-admin/registrations.blade.php`
- `tests/Feature/AdminDashboardTest.php`
- `tests/Feature/ClientAccessTest.php`
- `tests/Feature/ClientManagementTest.php`
- `tests/Feature/RegistrationTest.php`
