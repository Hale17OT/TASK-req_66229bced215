# Fitness Studio Operations & Finance Console - Clarification Questions


## 1. Session Management Scope: Single Session vs Controlled Concurrency


**Question:** The prompt requires device/session management but does not prescribe an exact concurrency cap. Should each user be restricted to one active session, or should multiple active sessions be allowed with bounded control and revocation?


**My Understanding:** For an internal operations console, controlled concurrency is practical as long as sessions are auditable, revocable, and bounded by timeout and max-concurrent limits.


**Solution:** The implementation uses tracked server-side sessions in `user_sessions`, enforces idle timeout (30 min), absolute lifetime (12 h), and a max concurrent cap of 3 per user (`config/app.php`, `app/service/auth/SessionService.php`). On login, a hashed session fingerprint is recorded, and oldest excess sessions are revoked by `enforceConcurrentLimit()`.


---


## 2. Lockout Policy Semantics: Counting Window and Cooldown Release


**Question:** The prompt specifies lockout after 5 failed logins in 15 minutes with a 30-minute cooldown, but does not state whether unlock is automatic or admin-only.


**My Understanding:** Automatic release after cooldown best matches offline usability while still preserving brute-force resistance.


**Solution:** `LockoutTracker` records every attempt in `login_attempts`, computes failures in a rolling 15-minute window, and sets `users.status='locked'` with `locked_until`. `maybeReleaseLock()` auto-restores the account once cooldown elapses (`app/service/auth/LockoutTracker.php`, `app/controller/api/v1/AuthController.php`, `config/app.php`).


---


## 3. Password Rotation and Reuse: Enforcement Point


**Question:** The prompt requires 12+ complexity and 90-day rotation but does not specify whether rotation should be checked only during password-change calls or also during normal authenticated usage.


**My Understanding:** Rotation should be enforced continuously so expired credentials cannot continue regular operations.


**Solution:** `AuthRequired` promotes active users to `password_expired` mid-flight if age exceeds policy and blocks all protected routes except `/auth/password/change`, `/auth/logout`, and `/auth/me` (`app/middleware/AuthRequired.php`). Complexity and reuse checks are server-authoritative in `PasswordPolicy` (min length, class requirements, history window 5, Argon2id hashing, 90-day expiry).


---


## 4. Duplicate Receipt Policy: Hard Block vs Advisory Before Submit


**Question:** The prompt says duplicate receipt/invoice numbers must be blocked before submission, but UX detail is ambiguous: should users discover this only at final submit or earlier while drafting?


**My Understanding:** Final submission must hard-block duplicates, and draft flow should provide an earlier advisory probe to reduce wasted work/uploads.


**Solution:** The backend hard-block is implemented in `DuplicateRegistry::assertNoDuplicate()` and invoked by workflow paths. Additionally, a non-throwing probe endpoint `GET /api/v1/reimbursements/duplicate-check` is exposed for pre-submit warnings and called by the reimbursement page (`app/service/reimbursement/DuplicateRegistry.php`, `app/controller/api/v1/ReimbursementController.php`, `public/static/js/pages/reimbursements.js`, `route/api.php`).


---


## 5. Attachment Validation Ownership: UI Feedback vs Server Authority


**Question:** The prompt requires PDF/JPG/PNG, max 10 MB each, up to 5 files. Should this be enforced only in UI or also in server API?


**My Understanding:** UI checks improve usability, but the API must remain authoritative because clients can be bypassed.


**Solution:** Server-side validation in `AttachmentService` enforces MIME sniffing with `finfo`, max bytes, non-empty file, and max per reimbursement (`app/service/reimbursement/AttachmentService.php`). The frontend mirrors these checks (type/size/count) before upload and reads `attachment_count` from reimbursement detail for preflight user feedback (`public/static/js/pages/reimbursements.js`, `app/controller/api/v1/ReimbursementController.php`).


---


## 6. Weak-Network Behavior: Recovery Source of Truth


**Question:** The prompt calls for weak-network adaptation, manual reconnect, and state compensation after drops. Should draft state be server-first or local-first?


**My Understanding:** In unreliable LAN scenarios, local-first draft continuity is safest, with server sync as best effort.


**Solution:** `StudioApi.Drafts` stores drafts in `localStorage` as primary and performs best-effort `PUT /api/v1/drafts/:token` sync. Restore path is local-first, then remote fallback. Degraded-mode banner and manual reconnect are integrated in `api.js`; polling backoff is adaptive (`public/static/js/api.js`, `app/controller/api/v1/DraftController.php`).


---


## 7. Reconciliation Visibility for Non-Global Users


**Question:** Reconciliation is organization-level by nature, but the prompt requires data-scope enforcement where applicable. For non-global users, should run visibility and exception scanning be scope-clipped?


**My Understanding:** Yes. Non-global users should only see runs they started, and exception candidates should be filtered by their location/department/self scope.


**Solution:** Reconciliation index uses `applyReconciliationRunScope()` so non-global users see their own runs only. The reconciliation scan query builder applies scope clauses for unsettled reimbursements and unbalanced settlements (`app/controller/api/v1/ReconciliationController.php`, `app/service/auth/Authorization.php`, `app/service/settlement/ReconciliationService.php`).


---


## Assumptions Imported


No `ASUMPTIONS.md` / `ASUMPTIONS.md` file was present in the repository at the time these clarifications were compiled, so there were no standalone assumption items to merge.
