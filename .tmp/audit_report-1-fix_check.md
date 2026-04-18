# Reinspection Results (v4)

Static-only reinspection completed for the 6 issues you listed (no project run, no Docker, no tests executed).

## Overall
- Fixed: **6 / 6**
- Partially fixed: **0 / 6**
- Not fixed: **0 / 6**

## Per-Issue Status

### 1) High — Missing authorization on admin location/department list endpoints
- Status: **Fixed**
- Evidence:
  - `app/controller/api/v1/LocationController.php:25` (`auth.manage_users` enforced in `index()`)
  - `app/controller/api/v1/DepartmentController.php:21` (`auth.manage_users` enforced in `index()`)
  - `tests/api/Audit3FindingsApiTest.php:51`
  - `tests/api/Audit3FindingsApiTest.php:59`

### 2) High — Schedule adjustment reviewer list leaks out-of-scope rows
- Status: **Fixed**
- Evidence:
  - `app/controller/api/v1/ScheduleAdjustmentController.php:35` (reviewer list applies scoped filter)
  - `app/service/auth/Authorization.php:261` (`applyScheduleAdjustmentScope`)
  - `tests/api/Audit3FindingsApiTest.php:116`
  - `tests/api/Audit3FindingsApiTest.php:149`

### 3) High — Budget allocation index not scope-filtered
- Status: **Fixed**
- Evidence:
  - `app/controller/api/v1/BudgetAllocationController.php:33` (`applyBudgetAllocationScope` applied)
  - `app/service/auth/Authorization.php:209` (scope helper implementation)
  - `tests/api/Audit3FindingsApiTest.php:176`
  - `tests/api/Audit3FindingsApiTest.php:204`

### 4) Medium — Weak-network adaptation only partially implemented
- Status: **Fixed (static evidence)**
- Evidence:
  - Adaptive polling utility and export in API layer:
    - `public/static/js/api.js:209`
    - `public/static/js/api.js:227`
    - `public/static/js/api.js:310`
  - Operations dashboard uses adaptive poller:
    - `public/static/js/pages/dashboard-operations.js:58`
  - Decision actions now use idempotent retries:
    - `public/static/js/pages/reimbursements.js:123`
    - `public/static/js/pages/settlements.js:41`
  - Documentation updated for adaptive polling + idempotent decisions:
    - `ASSUMPTIONS.md:153`
    - `ASSUMPTIONS.md:162`
- Boundary: real behavior under packet loss remains **Manual Verification Required** under static-only rules.

### 5) Medium — Audit before/after evidence incomplete for permission/rule changes
- Status: **Fixed**
- Evidence:
  - Role permission before/after + delta metadata:
    - `app/controller/api/v1/RoleAdminController.php:66`
    - `app/controller/api/v1/RoleAdminController.php:81`
  - User roles/scopes before/after + role delta metadata:
    - `app/controller/api/v1/UserAdminController.php:123`
    - `app/controller/api/v1/UserAdminController.php:160`
  - Regression tests:
    - `tests/api/Audit3FindingsApiTest.php:211`
    - `tests/api/Audit3FindingsApiTest.php:253`

### 6) Medium — Static documentation verifiability inconsistencies
- Status: **Fixed**
- Evidence:
  - Export behavior documented as synchronous and matches implementation:
    - `ASSUMPTIONS.md:132`
    - `app/service/export/ExportService.php:12`
  - README now points to an existing coverage mapping artifact:
    - `README.md:195` (now references `docs/coverage_report.md`)
    - `docs/coverage_report.md:1` (file exists)

## Final Conclusion
- Based on static evidence, all six previously reported issues are now addressed.
