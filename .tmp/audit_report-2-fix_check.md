# Re-check Results (Round 3, Static-Only)

Reviewed the 4 previously reported issues against current repository state.

## 1) High — Reconciliation endpoints not data-scope constrained
- **Status:** Fixed
- **Evidence:**
  - Reconciliation list applies scoped filtering for non-global users: `app/controller/api/v1/ReconciliationController.php:23`
  - Reconciliation scan queries now apply caller scope clauses (orphans + unbalanced): `app/service/settlement/ReconciliationService.php:69`, `app/service/settlement/ReconciliationService.php:93`
- **Conclusion:** Static code indicates reconciliation data is now scope-constrained.

## 2) High — Idempotency keys stuck `in_flight` on exception paths
- **Status:** Fixed
- **Evidence:**
  - Middleware now catches downstream exceptions and writes terminal cached response: `app/middleware/Idempotency.php:54`, `app/middleware/Idempotency.php:57`
  - Success path still writes completion: `app/middleware/Idempotency.php:64`
- **Conclusion:** Prior `in_flight`-forever risk is addressed in current implementation.

## 3) Medium — Weak client-side attachment/duplicate enforcement
- **Status:** Fixed
- **Evidence:**
  - Duplicate pre-check endpoint exists and is routed: `app/controller/api/v1/ReimbursementController.php:164`, `route/api.php:113`
  - UI calls duplicate pre-check before create: `public/static/js/pages/reimbursements.js:115`
  - UI validates attachment file type/size pre-upload: `public/static/js/pages/reimbursements.js:180`, `public/static/js/pages/reimbursements.js:186`
  - UI validates count cap using server-provided `attachment_count`: `public/static/js/pages/reimbursements.js:196`
  - Reimbursement show now returns `attachment_count`: `app/controller/api/v1/ReimbursementController.php:61`, `app/controller/api/v1/ReimbursementController.php:70`
- **Conclusion:** The previously missing client-side checks are now present with server-backed count data.

## 4) Medium — Reconciliation scope-isolation tests missing
- **Status:** Fixed
- **Evidence:**
  - Dedicated scope tests added for reconciliation start/index (non-global and global behavior): `tests/api/Audit5FindingsApiTest.php:86`, `tests/api/Audit5FindingsApiTest.php:107`, `tests/api/Audit5FindingsApiTest.php:127`, `tests/api/Audit5FindingsApiTest.php:153`
  - Legacy basic test file still exists but is now complemented by targeted scope tests: `tests/api/LedgerReconciliationApiTest.php:30`
- **Conclusion:** Coverage gap has been closed by new reconciliation scope regression tests.

## Final Summary
- **Issue #1:** Fixed
- **Issue #2:** Fixed
- **Issue #3:** Fixed
- **Issue #4:** Fixed
