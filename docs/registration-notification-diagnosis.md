# Registration notification and notice-page correction

## Diagnosis

Read-only checks of local configuration and queue/account metadata found:

- Queue connection: `database`; database queue: `default`; mail driver: `smtp`.
- No cached configuration. The reviewer-ID restriction is empty, so all active, access-eligible Super Admins are selected.
- One eligible Super Admin was found. No email addresses or SMTP credentials were printed.
- At inspection time there were zero pending jobs and zero failed jobs. No `artisan queue:work`/`queue:listen` process was visible in the local process snapshot.

The implementation triggers reviewer notification at **initial registration submission, before email verification**, alongside the applicant verification notification. Verification and resend do not trigger reviewer mail. Duplicate signup does not send it again. The existing recipient/dispatch code was not found to omit this eligible Super Admin, and real-dispatcher/fake-queue tests confirm that a reviewer job is dispatched.

The missing historical email's delivery cause cannot be reconstructed from empty live queue tables. Completed jobs are removed; there is no delivery receipt/history in this module. The absent worker is a current operational gap, not proof of what happened to the previous email. A queue could have been processed earlier by a temporary worker, and SMTP acceptance/mailbox delivery was not independently inspected. No speculative extra notification trigger or replay was added, which avoids duplicate reviewer mail.

Safe dispatch telemetry now records eligible reviewer count, accepted dispatch count and queue connection. No eligible recipient generates a warning. Existing exception handling still catches dispatch failures without changing registration or approval state and logs only recipient ID/exception class. Dispatch acceptance is not an SMTP delivery acknowledgement. Worker/transport failures after dispatch must be checked through protected failed-job operations.

The notice-page root cause was confirmed in source: its resend form rendered unconditionally, and its generic notice action did not reload an applicant. The signed verification handler provided only a transient `verified` flag. The page therefore still requested an email/resend even after successful verification.

## Notice behaviour and security

The signed, expiring verification route still validates the signature and current email hash under a row lock. It now establishes server-side session context containing applicant ID and email hash. The notice action reloads that account from the database on every visit; status is never cached or accepted from a URL ID/status/verified flag. An email change invalidates the old session identity. An authenticated public registrant can also view their own authoritative account status.

Without trusted identity, the page stays generic and offers verification/resend without disclosing an account's existence. It cannot safely show arbitrary status based on an unauthenticated email address.

- Unverified/unknown registration: verification-required explanation and the existing throttled resend form.
- Verified pending: **Email Verified Successfully** and **Awaiting Administrator Approval**, without the email/resend form.
- Approved and verified/active: approval message and login link, without resend.
- Approved but unverified: re-verification explanation and resend; approved but inactive: contact/support explanation, not a promise that login will succeed.
- Rejected: rejection message and no internal decision reason or resend form. A valid original signed link may establish status-view context for a rejected account, but does **not** mark it verified or change its status.
- Invalid/expired signed link: existing 403 explanation and generic resend.

Viewing a notice does not sign a user in or change roles, active state or decisions. Normal verification still marks only email verification. Existing approval, password, throttling, vessel and PDF controls are unchanged.

## Queue operation for manual testing

No worker was started, job retried, SMTP message sent, account edited or database migration run during this task. The live checks used SELECT queries and redacted metadata only. Tests use in-memory SQLite and fake notifications/queues.

When ready to process actual queued mail, the operator can run:

```bash
php artisan queue:work database --queue=default --tries=3 --timeout=60
```

This command can send real emails for queued jobs; it was not executed by the implementation. Keep the worker running while testing a fresh eligible registration and supervise it for sustained operation. Restart long-lived workers through the deployment process after code changes so they load current notification classes. Avoid starting competing unintended workers or replaying old registration notices just to diagnose delivery.

For the next manual test, correlate the safe `Registration reviewer notification dispatch completed.` counts with queue processing, then inspect delivery in the intended reviewer's mailbox/spam folder. If dispatch count is zero, check active/eligible Super Admin state and the reviewer-ID filter; if jobs remain waiting, check worker connection/queue; if jobs fail, inspect failure metadata privately without copying payloads, credentials or verification links into reports. A processed job is not proof of inbox delivery.

## Verification and file manifest

- Registration/notice/approval targeted tests: **75 passed, 361 assertions**.
- Full regression suite: **308 passed, 1,731 assertions**.
- All four changed/new PHP files pass syntax checks and Laravel Pint.
- Repository-wide Pint still reports the same nine existing formatting failures: six historical domain migrations and the `Vessel`, `CertificateType`, `CertificateVersion` models. They were left untouched.
- Real SMTP delivery and sustained worker operation were **not** tested.

Modified:

- `app/Support/RegistrationNotifications.php`
- `app/Http/Controllers/Auth/RegistrationController.php`
- `resources/views/auth/registration-notice.blade.php`
- `tests/Feature/RegistrationTest.php`

Created:

- `tests/Feature/RegistrationNoticeTest.php`
- `docs/registration-notification-diagnosis.md`

New tests cover eligible/inactive reviewer selection, actual notification-to-queue dispatch using a fake queue, after-commit/retry metadata, nonduplicated reviewer dispatch, all notice states, stale identity, invalid signatures, untrusted URL parameters and read-only rendering. Existing resend throttling, dispatch-failure and approval tests also pass. The queue fake verifies dispatch intent and metadata, not worker execution or real database-queue delivery.
