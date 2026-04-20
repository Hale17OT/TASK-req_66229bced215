# Test Coverage Audit

Static inspection scope:
- Endpoints: `route/api.php`, `route/web.php`
- Tests: `tests/api/*.php`, `tests/unit/*.php`, `tests/integration/*.php`, `tests/api/ApiTestCase.php`
- Test config/run entry: `phpunit.xml.dist`, `scripts/run_tests.sh`

## Backend Endpoint Inventory

Resolved API endpoints (prefixes and nested groups included): **78** from `route/api.php:15-144`.

Non-API web routes (excluded from API denominator):
- `GET /` (`route/web.php:6`)
- `GET /healthz` (`route/web.php:10`)

## API Test Mapping Table

Legend:
- Covered = `yes` only when exact `METHOD + PATH` is requested in tests.
- Test type here is based on static evidence in `tests/api/ApiTestCase.php:122-261` (dispatch via `$app->http->run($request)` with no mocks).

| Endpoint | Covered | Test type | Test files | Evidence |
|---|---|---|---|---|
| `POST /api/v1/auth/login` | yes | true no-mock HTTP | `tests/api/AuthApiTest.php` | `test_post_login_rejects_bad_credentials` |
| `POST /api/v1/auth/logout` | yes | true no-mock HTTP | `tests/api/AuthApiTest.php` | `test_post_logout_destroys_session` |
| `GET /api/v1/auth/me` | yes | true no-mock HTTP | `tests/api/AuthApiTest.php` | `test_get_me_requires_auth` |
| `POST /api/v1/auth/password/change` | yes | true no-mock HTTP | `tests/api/AuthApiTest.php` | `test_post_password_change_succeeds_with_valid_current` |
| `GET /api/v1/sessions` | yes | true no-mock HTTP | `tests/api/SessionApiTest.php` | `test_get_sessions_returns_current_session_list` |
| `DELETE /api/v1/sessions/:id` | yes | true no-mock HTTP | `tests/api/SessionApiTest.php` | `test_delete_session_revokes_own_session` |
| `GET /api/v1/admin/users` | yes | true no-mock HTTP | `tests/api/UserAdminApiTest.php` | `test_index_returns_paginated_list` |
| `POST /api/v1/admin/users` | yes | true no-mock HTTP | `tests/api/UserAdminApiTest.php` | `test_create_then_show_new_user` |
| `GET /api/v1/admin/users/:id` | yes | true no-mock HTTP | `tests/api/UserAdminApiTest.php` | `test_show_returns_user_with_scope_and_permissions` |
| `PUT /api/v1/admin/users/:id` | yes | true no-mock HTTP | `tests/api/UserAdminApiTest.php` | `test_update_changes_display_name` |
| `POST /api/v1/admin/users/:id/reset-password` | yes | true no-mock HTTP | `tests/api/UserAdminApiTest.php` | `test_reset_password_issues_temp_credential` |
| `POST /api/v1/admin/users/:id/lock` | yes | true no-mock HTTP | `tests/api/UserAdminApiTest.php` | `test_lock_and_unlock_cycle` |
| `POST /api/v1/admin/users/:id/unlock` | yes | true no-mock HTTP | `tests/api/UserAdminApiTest.php` | `test_lock_and_unlock_cycle` |
| `DELETE /api/v1/admin/users/:id/sessions` | yes | true no-mock HTTP | `tests/api/UserAdminApiTest.php` | `test_revoke_all_sessions_returns_count` |
| `GET /api/v1/admin/roles` | yes | true no-mock HTTP | `tests/api/RoleAdminApiTest.php` | `test_index_lists_all_roles_with_permissions` |
| `POST /api/v1/admin/roles` | yes | true no-mock HTTP | `tests/api/RoleAdminApiTest.php` | `test_create_update_delete_cycle` |
| `PUT /api/v1/admin/roles/:id` | yes | true no-mock HTTP | `tests/api/RoleAdminApiTest.php` | `test_create_update_delete_cycle` |
| `DELETE /api/v1/admin/roles/:id` | yes | true no-mock HTTP | `tests/api/RoleAdminApiTest.php` | `test_create_update_delete_cycle` |
| `GET /api/v1/admin/permissions` | yes | true no-mock HTTP | `tests/api/PermissionApiTest.php` | `test_index_returns_full_permission_catalog` |
| `GET /api/v1/admin/locations` | yes | true no-mock HTTP | `tests/api/LocationDepartmentApiTest.php` | `test_get_locations_returns_seeded_rows` |
| `POST /api/v1/admin/locations` | yes | true no-mock HTTP | `tests/api/LocationDepartmentApiTest.php` | `test_post_location_creates_new_row` |
| `GET /api/v1/admin/departments` | yes | true no-mock HTTP | `tests/api/LocationDepartmentApiTest.php` | `test_get_departments_returns_seeded_rows` |
| `POST /api/v1/admin/departments` | yes | true no-mock HTTP | `tests/api/LocationDepartmentApiTest.php` | `test_post_department_creates_new_row` |
| `GET /api/v1/locations` | yes | true no-mock HTTP | `tests/api/Audit3FindingsApiTest.php` | `test_scope_aware_locations_endpoint_returns_only_in_scope`, `test_scope_aware_locations_endpoint_returns_all_for_global` |
| `GET /api/v1/departments` | yes | true no-mock HTTP | `tests/api/Audit3FindingsApiTest.php` | `test_scope_aware_departments_endpoint_works` |
| `GET /api/v1/attendance/records` | yes | true no-mock HTTP | `tests/api/AttendanceApiTest.php` | `test_get_records_returns_paginated_list_for_admin` |
| `POST /api/v1/attendance/records` | yes | true no-mock HTTP | `tests/api/AttendanceApiTest.php` | `test_post_records_creates_row_and_writes_audit` |
| `GET /api/v1/attendance/corrections` | yes | true no-mock HTTP | `tests/api/AttendanceCorrectionApiTest.php` | `test_get_corrections_lists_rows` |
| `POST /api/v1/attendance/corrections` | yes | true no-mock HTTP | `tests/api/AttendanceCorrectionApiTest.php` | `test_post_corrections_submits_with_valid_payload` |
| `POST /api/v1/attendance/corrections/:id/approve` | yes | true no-mock HTTP | `tests/api/AttendanceCorrectionApiTest.php` | `test_post_corrections_approve_applies_correction` |
| `POST /api/v1/attendance/corrections/:id/reject` | yes | true no-mock HTTP | `tests/api/AttendanceCorrectionApiTest.php` | `test_post_corrections_reject_requires_comment` |
| `POST /api/v1/attendance/corrections/:id/withdraw` | yes | true no-mock HTTP | `tests/api/AttendanceCorrectionApiTest.php` | `test_post_corrections_withdraw_by_requester` |
| `GET /api/v1/schedule/entries` | yes | true no-mock HTTP | `tests/api/ScheduleApiTest.php` | `test_get_entries_as_coach_sees_only_own` |
| `GET /api/v1/schedule/adjustments` | yes | true no-mock HTTP | `tests/api/ScheduleApiTest.php` | `test_get_adjustments_index` |
| `POST /api/v1/schedule/adjustments` | yes | true no-mock HTTP | `tests/api/ScheduleApiTest.php` | `test_post_adjustment_submits_and_reviewer_approves` |
| `POST /api/v1/schedule/adjustments/:id/approve` | yes | true no-mock HTTP | `tests/api/ScheduleApiTest.php` | `test_post_adjustment_submits_and_reviewer_approves` |
| `POST /api/v1/schedule/adjustments/:id/reject` | yes | true no-mock HTTP | `tests/api/ScheduleApiTest.php` | `test_post_adjustment_reject_requires_comment` |
| `POST /api/v1/schedule/adjustments/:id/withdraw` | yes | true no-mock HTTP | `tests/api/ScheduleApiTest.php` | `test_post_adjustment_withdraw_by_requester` |
| `GET /api/v1/settlements` | yes | true no-mock HTTP | `tests/api/SettlementApiTest.php` | `test_index_returns_list` |
| `POST /api/v1/settlements` | yes | true no-mock HTTP | `tests/api/SettlementApiTest.php` | `test_record_creates_and_show_fetches` |
| `POST /api/v1/settlements/:id/confirm` | yes | true no-mock HTTP | `tests/api/SettlementApiTest.php` | `test_confirm_posts_ledger` |
| `POST /api/v1/settlements/:id/refund` | yes | true no-mock HTTP | `tests/api/SettlementApiTest.php` | `test_refund_enforces_cumulative_cap` |
| `POST /api/v1/settlements/:id/exception` | yes | true no-mock HTTP | `tests/api/SettlementApiTest.php` | `test_mark_exception_transitions_state` |
| `GET /api/v1/settlements/:id` | yes | true no-mock HTTP | `tests/api/SettlementApiTest.php` | `test_record_creates_and_show_fetches` |
| `GET /api/v1/budget/categories` | yes | true no-mock HTTP | `tests/api/BudgetApiTest.php` | `test_get_categories_returns_list` |
| `POST /api/v1/budget/categories` | yes | true no-mock HTTP | `tests/api/BudgetApiTest.php` | `test_post_categories_rejects_duplicate_name` |
| `PUT /api/v1/budget/categories/:id` | yes | true no-mock HTTP | `tests/api/BudgetApiTest.php` | `test_put_categories_updates_name` |
| `GET /api/v1/budget/allocations` | yes | true no-mock HTTP | `tests/api/BudgetApiTest.php` | `test_get_allocations_returns_paginated` |
| `POST /api/v1/budget/allocations` | yes | true no-mock HTTP | `tests/api/BudgetApiTest.php` | `test_post_allocation_creates_row` |
| `PUT /api/v1/budget/allocations/:id` | yes | true no-mock HTTP | `tests/api/BudgetApiTest.php` | `test_put_allocation_supersedes_old_version` |
| `GET /api/v1/budget/utilization` | yes | true no-mock HTTP | `tests/api/BudgetApiTest.php` | `test_get_utilization_returns_shape` |
| `GET /api/v1/budget/commitments` | yes | true no-mock HTTP | `tests/api/BudgetApiTest.php` | `test_get_commitments_returns_paginated` |
| `GET /api/v1/budget/precheck` | yes | true no-mock HTTP | `tests/api/BudgetApiTest.php` | `test_get_precheck_returns_within_cap` |
| `GET /api/v1/reimbursements` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_index_returns_paginated` |
| `POST /api/v1/reimbursements` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_post_creates_draft` |
| `GET /api/v1/reimbursements/duplicate-check` | yes | true no-mock HTTP | `tests/api/Audit5FindingsApiTest.php` | `test_duplicate_check_returns_ok_when_no_conflict`, `test_duplicate_check_flags_reserved_receipt`, `test_duplicate_check_requires_core_fields`, `test_duplicate_check_requires_reimbursement_permission` |
| `POST /api/v1/reimbursements/:id/submit` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_submit_advances_status_and_freezes_commitment` |
| `POST /api/v1/reimbursements/:id/withdraw` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_withdraw_releases_freeze` |
| `POST /api/v1/reimbursements/:id/approve` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_approve_advances_to_settlement_pending` |
| `POST /api/v1/reimbursements/:id/reject` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_reject_requires_min_reason` |
| `POST /api/v1/reimbursements/:id/needs-revision` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_needs_revision_returns_to_user_and_releases_freeze` |
| `POST /api/v1/reimbursements/:id/override` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_override_admin_path_unlocks_review` |
| `GET /api/v1/reimbursements/:id/history` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_get_history_returns_workflow_trace` |
| `POST /api/v1/reimbursements/:id/attachments` | yes | true no-mock HTTP | `tests/api/AttachmentApiTest.php` | `test_upload_then_download_round_trip` |
| `GET /api/v1/reimbursements/attachments/:id` | yes | true no-mock HTTP | `tests/api/AttachmentApiTest.php` | `test_upload_then_download_round_trip` |
| `GET /api/v1/reimbursements/:id` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_get_show_returns_single_reimbursement` |
| `PUT /api/v1/reimbursements/:id` | yes | true no-mock HTTP | `tests/api/ReimbursementApiTest.php` | `test_put_update_draft_changes_fields` |
| `GET /api/v1/ledger` | yes | true no-mock HTTP | `tests/api/LedgerReconciliationApiTest.php` | `test_ledger_returns_paginated_list` |
| `GET /api/v1/reconciliation/runs` | yes | true no-mock HTTP | `tests/api/LedgerReconciliationApiTest.php` | `test_reconciliation_runs_index_returns_list` |
| `POST /api/v1/reconciliation/runs` | yes | true no-mock HTTP | `tests/api/LedgerReconciliationApiTest.php` | `test_reconciliation_start_creates_run` |
| `GET /api/v1/audit` | yes | true no-mock HTTP | `tests/api/AuditExportDraftApiTest.php` | `test_audit_search_returns_paginated_entries` |
| `POST /api/v1/exports` | yes | true no-mock HTTP | `tests/api/AuditExportDraftApiTest.php` | `test_exports_end_to_end_audit_csv` |
| `GET /api/v1/exports` | yes | true no-mock HTTP | `tests/api/AuditExportDraftApiTest.php` | `test_exports_index_lists_jobs` |
| `GET /api/v1/exports/:id/download` | yes | true no-mock HTTP | `tests/api/AuditExportDraftApiTest.php` | `test_exports_end_to_end_audit_csv` |
| `GET /api/v1/exports/:id` | yes | true no-mock HTTP | `tests/api/AuditExportDraftApiTest.php` | `test_exports_end_to_end_audit_csv` |
| `GET /api/v1/drafts/:token` | yes | true no-mock HTTP | `tests/api/AuditExportDraftApiTest.php` | `test_draft_upsert_roundtrip` |
| `PUT /api/v1/drafts/:token` | yes | true no-mock HTTP | `tests/api/AuditExportDraftApiTest.php` | `test_draft_upsert_roundtrip` |
| `DELETE /api/v1/drafts/:token` | yes | true no-mock HTTP | `tests/api/AuditExportDraftApiTest.php` | `test_draft_upsert_roundtrip` |

## API Test Classification

1. **True No-Mock HTTP**
   - All files under `tests/api/*.php` using `ApiTestCase::request()`.
   - Evidence: `tests/api/ApiTestCase.php:122-261` creates `request`, runs `$app->http->run($request)`, and does not use mocking APIs.

2. **HTTP with Mocking**
   - None found.

3. **Non-HTTP (unit/integration without HTTP)**
   - Unit: `tests/unit/*.php` (Money, password policy, state machines, duplicate normalization, idempotency key format).
   - Integration: `tests/integration/AuthFlowTest.php` (direct `Db::table` interactions, no route dispatch).

## Mock Detection

Search scope: `tests/**/*.php`.

- `jest.mock`, `vi.mock`, `sinon.stub`, `Mockery`, `createMock`, PHPUnit mock expectation patterns: **not detected**.
- DI override/mocked provider patterns: **not detected**.
- Direct controller invocation replacing HTTP dispatch in API suite: **not detected**.

## Coverage Summary

- Total API endpoints: **78** (`route/api.php:15-144`).
- Endpoints with HTTP tests: **78**.
- Endpoints with TRUE no-mock tests: **78**.

Computed metrics:
- HTTP coverage = **100.00%** (78/78)
- True API coverage = **100.00%** (78/78)

## Unit Test Summary

Test files:
- API: `tests/api/*.php` (15 files + base case)
- Unit: `tests/unit/*.php`
- Integration: `tests/integration/AuthFlowTest.php`

Modules covered (evidence by route and direct class tests):
- Controllers/API surface: all controllers referenced by `route/api.php` are exercised via API tests (see mapping table).
- Services/domain logic:
  - `app/service/money/Money.php` via `tests/unit/MoneyTest.php`
  - `app/service/auth/PasswordPolicy.php` via `tests/unit/PasswordPolicyTest.php`
  - workflow transitions via `tests/unit/StateMachineTest.php`, `tests/unit/SettlementMachineTest.php`
  - reimbursement duplicate normalization via `tests/unit/DuplicateRegistryNormalizationTest.php`
- Auth/guards/middleware:
  - Auth, CSRF, permission, idempotency, audit are exercised through API requests and auth/permission negative tests (e.g., `tests/api/AuthApiTest.php::test_post_password_change_requires_csrf`, `tests/api/BudgetApiTest.php::test_get_commitments_requires_perm`, `tests/api/AttendanceApiTest.php::test_post_records_requires_permission`).

Important modules not clearly/explicitly asserted (despite pipeline traversal):
- `app/middleware/RequestLogger.php` (no targeted assertions)
- `app/middleware/SecurityHeaders.php` (no explicit response-header assertions)
- Frontend pages (`public/pages/*.html`) have no automated FE test evidence.

## Tests Check

- Success paths: strong coverage across auth, admin CRUD, attendance/schedule flows, reimbursements, settlements, exports, drafts.
- Failure/validation paths: present and meaningful (401/403/422/404/409 checks across suites).
- Edge cases: present (duplicate constraints, stale version, invalid period, over-cap refunds, over-cap override path).
- Auth/permissions: clearly exercised with role changes and negative tests (`coach`/`finance` denial cases).
- Integration boundaries: real app dispatch + DB writes/reads asserted in API tests.
- Assertion quality: mostly meaningful status + envelope + body-field assertions; few tests assert status only.
- API observability: **strong** overall (explicit method/path, payloads, and response checks in test code).
- `run_tests.sh`: present and Docker-based (`scripts/run_tests.sh:25-51` uses `docker compose exec`; no local package manager install flow).
- End-to-end (fullstack FE↔BE): dedicated automated FE↔BE E2E tests not found. Compensated partially by complete API HTTP coverage and unit coverage, but still not true browser E2E automation.

## Test Coverage Score (0-100)

**96/100**

## Score Rationale

- Full endpoint coverage and full true no-mock HTTP API coverage (75/75) strongly improve score.
- Good depth on success/failure/permission/validation across critical domains.
- Remaining deduction is for missing automated FE↔BE E2E evidence and limited explicit assertions for some cross-cutting middleware concerns (security headers/request logging).

## Key Gaps

- No automated frontend end-to-end tests for the fullstack UI workflows.
- No explicit test assertions for security header behavior and request logging output.

## Confidence & Assumptions

- Confidence: **high** for route coverage and README gate status from static file evidence.
- Assumptions:
  - `ApiTestCase::request()` pipeline dispatch (`$app->http->run`) is treated as real HTTP-layer route execution for strict static classification.
  - No hidden dynamic route registration outside inspected route files.

**Test Coverage Audit Verdict:** **PASS (with non-blocking gaps)**

---

# README Audit

Inspection target: `README.md` at repository root.

## Project Type Detection

- Required explicit type declaration at top: **present**.
  - `README.md:1` includes `fullstack` in title.
  - `README.md:3` includes `Project type: fullstack`.
- Inferred type from lightweight structure check: **fullstack** (web + API + DB in `docker-compose.yml`, UI + API descriptions in README).

## Hard Gate Evaluation

| Gate | Result | Evidence |
|---|---|---|
| `repo/README.md` exists | PASS | File present at `repo/README.md` (verified by directory listing of `repo/`) |
| Markdown formatting/readability | PASS | Structured headings/tables/code blocks throughout `README.md:1-248` |
| Backend/fullstack startup includes `docker-compose up` | PASS | Literal command present at `README.md:15` |
| Access method (URL + port) | PASS | `http://localhost:8080` at `README.md:22` |
| Verification method is explicit | PASS | API curl flow (`README.md:45-125`) + UI smoke flow (`README.md:128-149`) |
| Environment rules: Docker-contained, no runtime installs/manual DB setup | PASS | Docker-only statement (`README.md:11-12`), no `npm install`/`pip install`/`apt-get` or manual DB setup steps |
| Demo credentials for auth include all roles + password | PASS | Full role table with username/email/password/role at `README.md:26-37` |

## High Priority Issues

- None.

## Medium Priority Issues

- None.

## Low Priority Issues

- The troubleshooting section includes rollback guidance (`README.md:201`) but does not define rollback safety constraints; informational only, not a gate issue.

## Hard Gate Failures

- None.

## README Verdict

**PASS**
