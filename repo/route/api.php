<?php
// REST API routes — versioned under /api/v1
use think\facade\Route;

// Every `:id` across the API is a positive integer. Constraining it here
// prevents `:id` from greedily matching multi-segment paths like `1/history`
// (which would otherwise shadow more-specific routes).
Route::pattern([
    'id'    => '\d+',
    'token' => '[A-Za-z0-9_\-]{1,64}',
]);

// ---- Auth endpoints ----
// `login` is public (no session yet, no csrf). The others require auth + csrf.
Route::group('/api/v1/auth', function () {
    Route::post('/login',           'api.v1.AuthController/login');
    Route::post('/logout',          'api.v1.AuthController/logout')->middleware(['auth', 'csrf']);
    Route::get ('/me',              'api.v1.AuthController/me')->middleware(['auth']);
    Route::post('/password/change', 'api.v1.AuthController/changePassword')->middleware(['auth', 'csrf']);
});

// ---- Authenticated endpoints (wrap the whole /api/v1 tree below in auth+csrf+audit) ----
Route::group('/api/v1', function () {

    // Sessions / devices
    Route::get   ('/sessions',       'api.v1.SessionController/listMine');
    Route::delete('/sessions/:id',   'api.v1.SessionController/revoke');

    // User / role / permission admin — list more-specific `:id/<action>`
    // routes BEFORE the bare `:id` so TP6's dispatcher matches them first.
    Route::group('/admin', function () {
        // Users: specific actions first
        Route::post  ('/users/:id/reset-password',     'api.v1.UserAdminController/resetPassword');
        Route::post  ('/users/:id/lock',               'api.v1.UserAdminController/lock');
        Route::post  ('/users/:id/unlock',             'api.v1.UserAdminController/unlock');
        Route::delete('/users/:id/sessions',           'api.v1.UserAdminController/revokeAllSessions');
        Route::get   ('/users/:id',                    'api.v1.UserAdminController/show');
        Route::put   ('/users/:id',                    'api.v1.UserAdminController/update');
        Route::get   ('/users',                        'api.v1.UserAdminController/index');
        Route::post  ('/users',                        'api.v1.UserAdminController/create');

        Route::put   ('/roles/:id',                    'api.v1.RoleAdminController/update');
        Route::delete('/roles/:id',                    'api.v1.RoleAdminController/destroy');
        Route::get   ('/roles',                        'api.v1.RoleAdminController/index');
        Route::post  ('/roles',                        'api.v1.RoleAdminController/create');

        Route::get   ('/permissions',                  'api.v1.PermissionController/index');

        Route::get   ('/locations',                    'api.v1.LocationController/index');
        Route::post  ('/locations',                    'api.v1.LocationController/create');
        Route::get   ('/departments',                  'api.v1.DepartmentController/index');
        Route::post  ('/departments',                  'api.v1.DepartmentController/create');
    });

    // Scope-aware reference reads — non-admin endpoints used by Layui forms
    // that need a location/department dropdown without the privilege required
    // to enumerate the entire org chart (audit-3 #1).
    Route::get('/locations',   'api.v1.LocationController/referenceList');
    Route::get('/departments', 'api.v1.DepartmentController/referenceList');

    // Attendance — more-specific :id/<action> first, bare /corrections last
    Route::group('/attendance', function () {
        Route::post('/corrections/:id/approve',         'api.v1.AttendanceCorrectionController/approve');
        Route::post('/corrections/:id/reject',          'api.v1.AttendanceCorrectionController/reject');
        Route::post('/corrections/:id/withdraw',        'api.v1.AttendanceCorrectionController/withdraw');
        Route::get ('/corrections',                     'api.v1.AttendanceCorrectionController/index');
        Route::post('/corrections',                     'api.v1.AttendanceCorrectionController/submit')->middleware(['idempotent']);
        Route::get ('/records',                         'api.v1.AttendanceController/index');
        Route::post('/records',                         'api.v1.AttendanceController/record')->middleware(['idempotent']);
    });

    // Schedules — same ordering rule
    Route::group('/schedule', function () {
        Route::post('/adjustments/:id/approve',         'api.v1.ScheduleAdjustmentController/approve');
        Route::post('/adjustments/:id/reject',          'api.v1.ScheduleAdjustmentController/reject');
        Route::post('/adjustments/:id/withdraw',        'api.v1.ScheduleAdjustmentController/withdraw');
        Route::get ('/adjustments',                     'api.v1.ScheduleAdjustmentController/index');
        Route::post('/adjustments',                     'api.v1.ScheduleAdjustmentController/submit')->middleware(['idempotent']);
        Route::get ('/entries',                         'api.v1.ScheduleController/index');
    });

    // Settlements — same ordering rule
    Route::group('/settlements', function () {
        Route::get ('',                                 'api.v1.SettlementController/index');
        Route::post('',                                 'api.v1.SettlementController/record')->middleware(['idempotent']);
        Route::post('/:id/confirm',                     'api.v1.SettlementController/confirm');
        Route::post('/:id/refund',                      'api.v1.SettlementController/refund')->middleware(['idempotent']);
        Route::post('/:id/exception',                   'api.v1.SettlementController/markException');
        Route::get ('/:id',                             'api.v1.SettlementController/show');
    });

    // Budgets
    Route::group('/budget', function () {
        Route::get ('/categories',                      'api.v1.BudgetCategoryController/index');
        Route::post('/categories',                      'api.v1.BudgetCategoryController/create');
        Route::put ('/categories/:id',                  'api.v1.BudgetCategoryController/update');
        Route::get ('/allocations',                     'api.v1.BudgetAllocationController/index');
        Route::post('/allocations',                     'api.v1.BudgetAllocationController/create');
        Route::put ('/allocations/:id',                 'api.v1.BudgetAllocationController/update');
        Route::get ('/utilization',                     'api.v1.BudgetUtilizationController/index');
        Route::get ('/commitments',                     'api.v1.CommitmentController/index');
        Route::get ('/precheck',                        'api.v1.BudgetUtilizationController/precheck');
    });

    // Reimbursements — list the more-specific `:id/<action>` routes BEFORE the
    // bare `:id` catch so the dispatcher matches them first.
    Route::group('/reimbursements', function () {
        Route::get ('',                                 'api.v1.ReimbursementController/index');
        Route::post('',                                 'api.v1.ReimbursementController/createDraft');
        // Pre-submit duplicate probe (UI uses this to warn the user before
        // they waste an upload round trip). Non-id path must be declared
        // BEFORE the bare `:id` so TP6's dispatcher doesn't greedily match.
        Route::get ('/duplicate-check',                 'api.v1.ReimbursementController/duplicateCheck');
        Route::post('/:id/submit',                      'api.v1.ReimbursementController/submit')->middleware(['idempotent']);
        Route::post('/:id/withdraw',                    'api.v1.ReimbursementController/withdraw');
        Route::post('/:id/approve',                     'api.v1.ReimbursementController/approve')->middleware(['idempotent']);
        Route::post('/:id/reject',                      'api.v1.ReimbursementController/reject')->middleware(['idempotent']);
        Route::post('/:id/needs-revision',              'api.v1.ReimbursementController/needsRevision');
        Route::post('/:id/override',                    'api.v1.ReimbursementController/override')->middleware(['idempotent']);
        Route::get ('/:id/history',                     'api.v1.ReimbursementController/history');
        Route::post('/:id/attachments',                 'api.v1.AttachmentController/upload');
        Route::get ('/attachments/:id',                 'api.v1.AttachmentController/download');
        Route::get ('/:id',                             'api.v1.ReimbursementController/show');
        Route::put ('/:id',                             'api.v1.ReimbursementController/updateDraft');
    });

    // Ledger + reconciliation
    Route::get ('/ledger',                              'api.v1.LedgerController/index');
    Route::get ('/reconciliation/runs',                 'api.v1.ReconciliationController/index');
    Route::post('/reconciliation/runs',                 'api.v1.ReconciliationController/start');

    // Audit + exports (permission enforced inside the controllers).
    Route::get ('/audit',                               'api.v1.AuditController/search');
    Route::group('/exports', function () {
        Route::post('',              'api.v1.ExportController/create');
        Route::get ('',              'api.v1.ExportController/index');
        Route::get ('/<id>/download','api.v1.ExportController/download')->pattern(['id' => '\d+']);
        Route::get ('/<id>',         'api.v1.ExportController/show')->pattern(['id' => '\d+']);
    });

    // Drafts (weak-network state compensation)
    Route::get   ('/drafts/:token',                     'api.v1.DraftController/show');
    Route::put   ('/drafts/:token',                     'api.v1.DraftController/upsert');
    Route::delete('/drafts/:token',                     'api.v1.DraftController/destroy');

})->middleware(['auth', 'csrf', 'audit']);
