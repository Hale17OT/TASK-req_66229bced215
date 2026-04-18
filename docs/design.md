# Fitness Studio Operations & Finance Console - Design

## Overview

This project is an offline-first operations console for fitness studios. It combines:

- ThinkPHP 6.1 REST backend (`app/controller/api/v1/*`, `route/api.php`)
- Layui + vanilla JS frontend (`public/pages/*.html`, `public/static/js/*`)
- MySQL persistence with phased migrations (`database/migrations/*`)

Primary business domains implemented are identity/RBAC, attendance/schedule operations, budget controls, reimbursement workflow, settlement/ledger/reconciliation, exports, and auditability.

## Architecture

### 1) Backend layers

- **Routing:** Versioned under `/api/v1` with route patterns and grouped middleware (`route/api.php`).
- **Controllers:** Resource controllers per domain under `app/controller/api/v1`.
- **Services:** Business logic in `app/service/*` (auth, reimbursement, settlement, budget, export, security).
- **Middleware:** `auth`, `csrf`, `audit`, `idempotent`, and request logging/security headers (`app/middleware/*`).
- **Models:** ORM-backed entities under `app/model/*`.

### 2) Frontend layers

- **Shell layout + role navigation:** `public/pages/shell.html`, `public/static/js/shell.js`.
- **Page modules:** one JS module per workspace/feature (attendance, schedule, reimbursements, budgets, settlements, dashboards).
- **Transport and resilience helper:** `public/static/js/api.js` handles JSON envelopes, CSRF, idempotency keys, degraded network mode, adaptive polling, and draft persistence.

### 3) Data model

Schema is organized by migration phase:

- **Phase 1:** identity, RBAC, scopes, sessions, password history, login attempts, append-only audit logs, idempotency keys, draft recovery.
- **Phase 2:** attendance records/corrections and schedule entries/adjustments.
- **Phase 3:** budget categories/periods/allocations, commitments, overrides.
- **Phase 4:** reimbursements, attachments, duplicate-document registry, approval workflow.
- **Phase 5:** settlements, refunds, ledger entries, reconciliation runs/exceptions.
- **Phase 6:** export jobs and scheduled job registry.
- **Phase 7:** security hardening (encrypted-field support and blind indexes).

## Core Security Model

### Authentication and session controls

- Local username/password login (`AuthController@login`).
- Password policy: min 12 chars + upper/lower/digit/special + history + rotation (`PasswordPolicy`, `config/app.php`).
- Lockout: 5 failures in 15 min, 30 min cooldown (`LockoutTracker`, `config/app.php`).
- Session tracking and revocation via `user_sessions`, idle and absolute timeouts (`SessionService`).

### Authorization and data scope

- Permission checks are centralized through `Authorization` service.
- Controllers enforce function-level permissions for privileged operations.
- Scope-based filters apply location/department visibility on list/read flows where needed.
- Object-level guards exist for reimbursement and settlement reads/actions.

### Request integrity and replay safety

- CSRF required for unsafe methods via `X-CSRF-Token` (`CsrfTokenRequired`).
- `Idempotency-Key` required on unsafe writes (`Idempotency` middleware).
- Completed responses (success and handled failure) are cached for deterministic replay.

### Sensitive-data controls and auditability

- Field encryption/blind-index strategy for selected sensitive identifiers (phase 7 + security services).
- API masking via `FieldMasker` for restricted viewers.
- Audit trail captures privileged actions with immutable append-only protections at DB trigger level.

## Business Workflow Design

### Attendance and schedule

- Front Desk records attendance; correction requests require reason text and reviewer decision flow.
- Coaches view schedules and submit adjustment requests; approvers can approve/reject/withdraw with auditable transitions.

### Budget and commitments

- Finance maintains categories and period allocations.
- Submission/approval logic checks available cap and commitment state transitions.
- Admin override path requires explicit reason and emits audit records.

### Reimbursement and attachments

- Reimbursements progress through draft/submission/review/approval/revision/rejection/settlement statuses.
- Duplicate prevention is enforced server-side (registry + blind indexes) and supported by a pre-submit probe endpoint.
- Attachments enforce MIME and size/count rules server-side, with matching client-side pre-validation.

### Settlement and reconciliation

- Offline settlement methods include cash/check/terminal batch entry.
- Confirm/refund/exception actions drive ledger postings.
- Reconciliation runs compute unsettled-approved and unbalanced-ledger exceptions and are scope-clipped for non-global users.

## Offline and Weak-Network Adaptation

- Degraded-mode banner appears after repeated transport failures and supports manual reconnect.
- Adaptive polling expands interval on failure and contracts on recovery.
- Draft state is preserved locally (primary) and synced server-side (best effort) with tokenized recovery records.
- Decision actions use idempotent retries to avoid duplicated side effects on reconnect.

## Operational Jobs

Scheduled jobs include lockout expiry, password expiry marking, export expiry cleanup, orphan attachment cleanup, audit retention archival, session cleanup, idempotency cleanup, and draft cleanup (`scheduled_jobs` + `app/job/*`).
