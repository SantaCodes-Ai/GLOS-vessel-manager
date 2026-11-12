# Phase 13 — Interactive dashboard and certificate status navigation

Implemented 10 November 2026.

## Inspection findings

- Administrator DashboardController already counts all vessels and certificate records and delegates current-version status counts/attention records to CertificateDashboard.
- VesselController already supports name/IMO search, active/inactive filtering, latest-first stable ordering and 15-record pagination. No vessel-list change was needed.
- CertificateController already supports grouped vessel/type search, latest-first ordering and 15-record pagination, but had no expiry-status filter.
- Certificate::currentVersion selects the highest version_number, not the newest row ID. Certificate::expiry delegates to CertificateExpiry.
- CertificateExpiry already implements application-timezone calendar dates, the 14/15/30/31-day thresholds, permanent records and unknown/inconsistent expiry data. This class and all thresholds remain unchanged.
- Existing administrator routes and middleware already protect the dashboard and both destination lists. No route/middleware changes were needed.

## Dashboard destinations

All are existing named administrator routes; these links introduce no new endpoints.

| Dashboard card | Named route | Exact path/query |
| --- | --- | --- |
| Total Vessels | admin.vessels.index | /admin/vessels |
| Total Certificates | admin.certificates.index | /admin/certificates |
| Permanent | admin.certificates.index | /admin/certificates?status=permanent |
| Valid | admin.certificates.index | /admin/certificates?status=valid |
| Expiring Soon | admin.certificates.index | /admin/certificates?status=expiring-soon |
| Critical | admin.certificates.index | /admin/certificates?status=critical |
| Expired | admin.certificates.index | /admin/certificates?status=expired |
| Unknown | admin.certificates.index | /admin/certificates?status=unknown |

Total links contain no search/status/page parameters, so they open the full register. The existing Certificate Types statistic remains unchanged.

Cards use real, descriptively labelled anchors and a stretched-link CSS target covering the card. The definition-list structure and existing counts remain. Pointer cursor, restrained neutral/blue hover and a visible full-card focus outline support mouse, keyboard and touch navigation without JavaScript or animation. Existing logo, brand palette, responsive grid and expiry badge colours are preserved.

## Filter and count consistency

CertificateDashboard now shares a private current-version metadata scan between summary counts and filter matching. Both paths call Certificate::expiry, which calls the unchanged CertificateExpiry calculator.

The scan clones the input certificate query, loads currentVersion metadata in batches of 500 and classifies each certificate once. Historical version rows never add extra certificate counts. Absent or inconsistent current-version data stays unknown. Initial registration, renewal, expiry calculation and private document serving are not changed.

The new optional certificate-list status parameter accepts only permanent, valid, expiring-soon, critical, expired or unknown. Invalid values/arrays fail Laravel validation (JSON requests receive 422; normal web requests use the existing validation redirect/error flow). Empty/absent status means all records.

Search restrictions are applied before classification. Matching certificate IDs are applied through Laravel's integer-ID query API, followed by the existing eager loading, newest-first ordering and database pagination. No URL-supplied SQL or file path is accepted. Dropdown selection, an active-filter label, Clear expiry filter (preserves search), Reset (clears all filters/search) and helpful empty results make filter state visible. Pagination retains search and status parameters.

Counts and destination lists use the same all-certificate base query and highest-version relationship. Dashboard counts are not cached, and record changes/date changes between requests are reflected when revisiting the list.

## Verification

- Targeted suite: **101 passed, 539 assertions**.
- Full suite: **400 passed, 2,342 assertions**, no failures/errors/skips reported.
- Changed-file Laravel Pint: **passed** for CertificateController, CertificateDashboard and DashboardNavigationTest.
- PHP syntax: **99 PHP files checked, zero failures** under app, routes, config, database and tests.

Targeted command:

    php artisan test tests/Feature/DashboardNavigationTest.php tests/Feature/AdminDashboardTest.php tests/Feature/CertificateExpiryTest.php tests/Feature/CertificateManagementTest.php tests/Feature/VesselManagementTest.php

Full suite:

    php artisan test

Formatting check:

    vendor/bin/pint --test app/Http/Controllers/Admin/CertificateController.php app/Support/CertificateDashboard.php tests/Feature/DashboardNavigationTest.php

The new DashboardNavigationTest verifies actual card URLs and follows them, all six category totals/list results, highest version_number even when history is inserted later, calendar boundaries, missing/inconsistent expiry data, grouped searches, stable ordering, pagination/filter persistence, clearing, empty states, invalid filters and administrator-only access. It also checks a query budget against N+1 behaviour and consistency over a 501-certificate metadata batch boundary.

Existing tests were not changed. The full suite includes existing registration/approval/account/department, Staff assignment/access-revocation, vessel, certificate registration/renewal and current/historical private PDF regressions.

Tests use the existing fail-closed in-memory SQLite guard and isolated test data. No migrations or data changes were run against MySQL. No company records, role values, audit history, credentials or uploaded PDFs were modified.

## Files modified

- app/Http/Controllers/Admin/CertificateController.php — validated status filtering in the existing index action.
- app/Support/CertificateDashboard.php — shared current-version scanning and filter matching.
- resources/views/admin/dashboard.blade.php — eight existing statistics now have accessible links.
- resources/views/admin/certificates/index.blade.php — status selector, active filter, clear/reset and filtered empty states.
- resources/views/admin/styles.blade.php — scoped card hover, pointer and keyboard-focus styling.

## Files created

- tests/Feature/DashboardNavigationTest.php
- docs/phase-13-dashboard-navigation.md

## Manual browser checks and limitations

Interactive browser checks were not performed. After signing in as an administrator:

1. Click each of the eight cards; verify its path/query against the table above and its initial list total against the card.
2. Check that zero-count categories open useful empty results with a visible status selection.
3. Combine a status with vessel/type search, follow Next/Previous and confirm that both parameters remain. Clear expiry filter should preserve search; Reset should show all certificates.
4. Use Tab/Shift+Tab and Enter to navigate the cards, verifying a full-card focus indicator. Check pointer hover and narrow/mobile layouts without clipped labels or displaced logo.
5. Check a renewed certificate appears in its current category exactly once. Confirm historical PDFs remain available only through the existing authorised detail/document pages.
6. Verify Staff and logged-out users cannot reach these administrator destinations.

Status filtering deliberately reuses the existing PHP calculator rather than duplicating date/data-validity rules in database-specific SQL. A filtered request scans the search-matching current-version metadata, and matching-ID memory/SQL size grows with the result set. Classification is batched and avoids N+1 queries, but very large registers may require a separately profiled query optimisation. Production-scale MySQL performance/concurrency was not benchmarked. Unfiltered lists retain their existing direct database pagination.
