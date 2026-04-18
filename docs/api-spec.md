# Fitness Studio Operations & Finance Console - API Specification

## 1. Overview

- **Backend:** ThinkPHP 6.1
- **Base path:** `/api/v1`
- **Auth model:** Cookie-backed session (`STUDIOSESSID`) + CSRF token (`studio_csrf` cookie, sent as `X-CSRF-Token`)
- **Envelope:**

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "meta": {
    "request_id": "...",
    "ts": "2026-04-18T00:00:00Z"
  }
}
```

`errors` is included for validation failures.

## 2. Required Headers / Cookies

### Cookies

- `STUDIOSESSID` (session)
- `studio_csrf` (token mirror)

### Headers

- `X-CSRF-Token`: required for unsafe methods (POST/PUT/DELETE/PATCH)
- `Idempotency-Key`: required for unsafe methods via middleware
- `Content-Type: application/json` for JSON bodies (multipart for attachments)

## 3. Authentication Endpoints

All paths below are prefixed with `/api/v1`.

| Method | Path | Auth | CSRF | Description |
|---|---|---|---|---|
| POST | `/auth/login` | No | No | Local username/password sign-in; issues session + CSRF cookie |
| POST | `/auth/logout` | Yes | Yes | Session logout |
| GET | `/auth/me` | Yes | No | Current user, roles, permissions, capability map |
| POST | `/auth/password/change` | Yes | Yes | Password change with policy enforcement |

## 4. Session / Device Management

| Method | Path | Description |
|---|---|---|
| GET | `/sessions` | List caller sessions |
| DELETE | `/sessions/:id` | Revoke a session |

## 5. Admin Endpoints

### Users

- `GET /admin/users`
- `POST /admin/users`
- `GET /admin/users/:id`
- `PUT /admin/users/:id`
- `POST /admin/users/:id/reset-password`
- `POST /admin/users/:id/lock`
- `POST /admin/users/:id/unlock`
- `DELETE /admin/users/:id/sessions`

### Roles / Permissions / Org dictionaries

- `GET /admin/roles`
- `POST /admin/roles`
- `PUT /admin/roles/:id`
- `DELETE /admin/roles/:id`
- `GET /admin/permissions`
- `GET /admin/locations`
- `POST /admin/locations`
- `GET /admin/departments`
- `POST /admin/departments`

### Scope-aware reference lists

- `GET /locations`
- `GET /departments`

## 6. Attendance and Schedule APIs

### Attendance

- `GET /attendance/records`
- `POST /attendance/records`
- `GET /attendance/corrections`
- `POST /attendance/corrections`
- `POST /attendance/corrections/:id/approve`
- `POST /attendance/corrections/:id/reject`
- `POST /attendance/corrections/:id/withdraw`

### Schedule

- `GET /schedule/entries`
- `GET /schedule/adjustments`
- `POST /schedule/adjustments`
- `POST /schedule/adjustments/:id/approve`
- `POST /schedule/adjustments/:id/reject`
- `POST /schedule/adjustments/:id/withdraw`

## 7. Budget and Commitment APIs

- `GET /budget/categories`
- `POST /budget/categories`
- `PUT /budget/categories/:id`
- `GET /budget/allocations`
- `POST /budget/allocations`
- `PUT /budget/allocations/:id`
- `GET /budget/utilization`
- `GET /budget/commitments`
- `GET /budget/precheck`

## 8. Reimbursement APIs

- `GET /reimbursements`
- `POST /reimbursements` (create draft)
- `GET /reimbursements/duplicate-check`
- `GET /reimbursements/:id`
- `PUT /reimbursements/:id`
- `POST /reimbursements/:id/submit`
- `POST /reimbursements/:id/withdraw`
- `POST /reimbursements/:id/approve`
- `POST /reimbursements/:id/reject`
- `POST /reimbursements/:id/needs-revision`
- `POST /reimbursements/:id/override`
- `GET /reimbursements/:id/history`
- `POST /reimbursements/:id/attachments` (multipart upload)
- `GET /reimbursements/attachments/:id` (download)

## 9. Settlement, Ledger, Reconciliation APIs

### Settlements

- `GET /settlements`
- `POST /settlements`
- `GET /settlements/:id`
- `POST /settlements/:id/confirm`
- `POST /settlements/:id/refund`
- `POST /settlements/:id/exception`

### Ledger / reconciliation

- `GET /ledger`
- `GET /reconciliation/runs`
- `POST /reconciliation/runs`

## 10. Audit and Export APIs

- `GET /audit` (search; date-range filters supported by controller/service)
- `POST /exports`
- `GET /exports`
- `GET /exports/:id`
- `GET /exports/:id/download`

Export jobs are requester-private (only creator can read/download their generated file).

## 11. Draft Recovery APIs (Weak-Network Compensation)

- `GET /drafts/:token`
- `PUT /drafts/:token`
- `DELETE /drafts/:token`

## 12. Middleware and Runtime Rules

- Entire `/api/v1` tree (except `/auth/login`) is behind `auth`; unsafe writes require `csrf`.
- `/api/v1` group also includes `audit` middleware hook.
- Many mutating routes additionally rely on idempotency handling and controller-level permission checks.
- IDs are constrained to positive integers by global route pattern.

## 13. Common Status / Error Conventions

- `HTTP 200` + `code:0` for success
- `HTTP 401` authentication failures (`Authentication required`, expired/revoked session)
- `HTTP 403` authorization/permission/scope failures
- `HTTP 404` not found (`code` family includes `40400`)
- `HTTP 409` conflict (including idempotency in-flight conflict)
- `HTTP 422` validation/business-rule failures
