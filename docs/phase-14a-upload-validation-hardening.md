# Phase 14A — PDF upload security and form validation hardening

Implemented 10 November 2026.

## Confirmed root causes

1. StoreCertificateRequest previously checked upload validity/size, MIME type, extension and only the first five bytes (%PDF-). Those checks could accept malformed documents with a plausible header. RenewCertificateRequest inherits these rules, and its old synthetic upload fixture had no valid page tree or cross-reference table yet passed the former rules.
2. Vessel and certificate-type POST validation rejects non-string inputs, but the Blade forms passed flashed old input directly into HTML escaping. Redisplaying arrays reproduced htmlspecialchars(): Argument #1 must be of type string, array given. These modules use server-rendered Blade/controller/form-request forms, not Livewire form components. The only existing Livewire component is authentication.

Inspection also covered private filesystem configuration, certificate initial/renewal persistence, version numbering, administrator/staff current and historical document controllers, and their shared document-response service. Storage/transaction compensation and object-level PDF authorisation already existed and were retained.

## Selected PDF validation approach

New uploads retain all prior checks: required uploaded file, maximum 10,240 KB (10 MB), PDF MIME detection and .pdf extension. The new ValidPdfStructure rule delegates to PdfStructure and is inherited by renewal validation.

The validator:
- Reads the uploaded temporary file only, checking the existing PDF header requirement and a final EOF marker followed only by PDF whitespace.
- Runs qpdf --suppress-recovery --check against the server-generated temporary path using Symfony Process argument arrays.
- Accepts only exit status 0; warnings, errors, missing tooling and timeouts fail closed.
- Requires a positive page count through a separate read-only --show-npages invocation.
- Gives the structure check a ten-second timeout and the page-count check five seconds.
- Discards check output and captures only a bounded 64-byte page-count result. No raw parser output, temporary paths or document contents are exposed/logged.
- Supplies no output filename, repair operation, shell command string or client-provided filename. No document is rewritten.

qpdf is a maintained structural inspection tool; its official documentation defines the check/exit-status behaviour and explains that it does not fully validate page-content semantics: [qpdf CLI documentation](https://qpdf.readthedocs.io/en/stable/cli.html).

A genuine PDF fixture with a catalog, page tree, font/content stream, correct cross-reference offsets and trailer is shared between upload/renewal tests. Tests also generate and accept a modern object-stream PDF using the real qpdf binary.

### Dependencies and deployment requirement

**qpdf 12.4.2 was installed locally with the approved Homebrew command brew install qpdf.** Homebrew handled its package dependencies, including jpeg-turbo. No Composer/npm package was added, and neither dependency manifest/lockfile was modified. Symfony Process was already installed with Laravel.

**Each deployed PHP application worker must have qpdf available before this code is rolled out.** Local installation does not install it on another server. Install the maintained OS package and verify qpdf --version. Confirm the PHP service user can execute it.

config/pdf.php supports QPDF_BINARY (default qpdf). Normal PATH lookup also checks /opt/homebrew/bin and /usr/local/bin for Herd/macOS compatibility. An absolute path can be configured where worker PATH differs, for example QPDF_BINARY=/usr/bin/qpdf on an appropriate Linux deployment or /opt/homebrew/bin/qpdf on Apple Silicon Homebrew. Rebuild configuration/reload workers through the normal deployment process if setting it. The actual .env was not edited.

Missing/broken/timed-out validation produces a generic document validation error and creates no certificate/version/file. Operators receive a generic unavailable/error-type log entry without parser diagnostics or sensitive values. There is no insecure header-only fallback.

## Form hardening

Vessel and certificate-type requests now use bail before continuing field rules after a failure; vessel status explicitly requires a string. Existing required fields, maxima, uniqueness/update-ignore rules, IMO format/check digit, active/inactive status, optional values and positive validity-month requirements remain.

The forms render old values only when scalar, otherwise displaying an empty field. Blade escaping remains in place, and HTML structure, labels, branding, styles and layout were not changed.

Certificate upload metadata additionally stops after invalid integer/string/date types. This keeps malformed nested vessel/type identifiers out of database existence checks and malformed issuance arrays out of cross-field date comparison. Permanent-expiry date clearing and all existing date/expiry business rules remain.

## Storage, history and temporary-file safety

Validation finishes before existing controllers write private files or database records. Rejected uploads do not create a certificate or an incomplete version. The existing private disk, generated UUID filenames, maximum-version increment/locking, storage-write compensation, authenticated uploader attribution and current/historical PDF responses remain unchanged.

The validator creates no application staging files and never repairs input. Uploaded temporary files remain owned by PHP's normal upload lifecycle; the validator does not prematurely delete them. Existing controller cleanup still removes only newly stored files if persistence fails. Test-created conversion files and timeout fixtures are closed/unlinked in finally blocks.

No existing certificate PDFs were scanned, rewritten, deleted or reprocessed. Historical files remain subject to their existing authorisation/serving behaviour rather than retroactive upload validation.

## Automated results

- Targeted tests: **195 passed, 1,210 assertions**.
- Full suite: **500 passed, 3,017 assertions**, no failures/errors/skips reported.
- PHP syntax: **105 PHP files checked, zero failures**.
- Changed-file Laravel Pint: **passed**, eleven PHP files. Blade changes were exercised by HTTP rendering tests.

Commands:

    php artisan test tests/Feature/PdfUploadSecurityTest.php tests/Feature/FormInputHardeningTest.php tests/Feature/CertificateManagementTest.php tests/Feature/CertificateRenewalTest.php tests/Feature/VesselManagementTest.php tests/Feature/CertificateTypeManagementTest.php
    php artisan test

    vendor/bin/pint --test app/Http/Requests/StoreCertificateRequest.php app/Http/Requests/SaveVesselRequest.php app/Http/Requests/SaveCertificateTypeRequest.php app/Rules/ValidPdfStructure.php app/Support/PdfStructure.php config/pdf.php tests/Fixtures/ValidPdf.php tests/Feature/CertificateManagementTest.php tests/Feature/CertificateRenewalTest.php tests/Feature/FormInputHardeningTest.php tests/Feature/PdfUploadSecurityTest.php

New tests cover valid/plain and compressed PDFs, byte preservation/private storage, wrong extensions, renamed non-PDF content, header-only/fake-footer/truncated PDFs, missing EOF, broken cross references/streams/page trees, appended non-PDF executable text, oversized uploads, zero-page documents, validator absence/absolute paths/timeouts, invalid associations, registration/renewal authorisation and failed-write cleanup with history preservation.

Form tests cover create/edit arrays (including nested arrays), missing required fields, long strings, invalid IMO/check digits/status, invalid/oversized validity periods, duplicates and unknown route identifiers. They assert validation errors, safe redisplay and unchanged records.

Existing CertificateManagementTest and CertificateRenewalTest upload fixtures now use Tests\Fixtures\ValidPdf; their business/security scenarios and assertions were retained. Other existing tests were not modified. The full suite retains administrator/staff, authentication, approvals, account deletion, assignment/revocation, renewal, expiry/dashboard filtering, branding and current/historical PDF authorisation coverage.

All database tests use the existing fail-closed in-memory SQLite guard and isolated factories/Storage::fake(). No persistent MySQL migrations or record changes were performed. Real SMTP, credentials, account roles and company PDFs were untouched.

## Files modified

- app/Http/Requests/StoreCertificateRequest.php
- app/Http/Requests/SaveVesselRequest.php
- app/Http/Requests/SaveCertificateTypeRequest.php
- resources/views/admin/vessels/form.blade.php
- resources/views/admin/certificate-types/form.blade.php
- tests/Feature/CertificateManagementTest.php
- tests/Feature/CertificateRenewalTest.php

## Files created

- app/Rules/ValidPdfStructure.php
- app/Support/PdfStructure.php
- config/pdf.php
- tests/Fixtures/ValidPdf.php
- tests/Feature/FormInputHardeningTest.php
- tests/Feature/PdfUploadSecurityTest.php
- docs/phase-14a-upload-validation-hardening.md

## Limitations

Structural validity is not malware detection, document authenticity/signature verification or full PDF semantic validation. Valid PDFs can contain active content or attachments; this phase does not sanitise those features. Existing private storage, generated paths, authorisation and PDF security headers remain essential. No claim is made that arbitrary accepted content is harmless.

Strict warning/recovery rejection can reject damaged PDFs that permissive viewers repair. Users should re-export those documents from their source application. Documents requiring a password cannot be inspected without it and must be exported as a readable PDF; zero-page files are rejected.

Timeouts bound processing time, not the child process's operating-system memory consumption. Keep qpdf patched and use appropriate deployment process/container resource limits for hostile uploads. Production PHP-worker executable access, production MySQL behaviour and interactive browser behaviour require deployment verification.

## Manual browser checks

Use isolated test records/files rather than company data.

1. Verify qpdf is installed and executable by the actual PHP worker. If unavailable, confirm the upload gives a generic validation error and no new version.
2. As an administrator, register a legitimate PDF under 10 MB and renew it with another valid PDF. Preview/download both versions and verify original bytes/version history remain.
3. Try a renamed text file, truncated PDF, wrong extension and oversized upload. Expect a document validation error, no internal paths/parser messages and no additional certificate/version/file.
4. Submit vessel_name[] or certificate-type name[]/description[]/default_validity_months[] values through a local testing request or browser developer tools. The form should return normal validation errors without a server exception. Repeat on edit pages.
5. Check required/long/duplicate values, invalid IMO/check digits/status and valid edits retaining their existing unique identifiers.
6. As Staff, verify administrative upload/renewal access remains denied while authorised current/historical PDF access still works.
7. Check dashboard filtering, company logo/colours and ordinary form layouts remain unchanged.
