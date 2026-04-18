/* Operations landing — review queues with adaptive polling. */
layui.use(['table'], function () {
  var table = layui.table;
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Operations review queue '
    + '<small class="muted" id="op-poll-status" style="margin-left:8px"></small></div><div class="layui-card-body">'
    + '<h3>Reimbursements awaiting decision</h3><table id="op-reimb"></table>'
    + '<h3 style="margin-top:24px">Attendance corrections to review</h3><table id="op-corr"></table>'
    + '<h3 style="margin-top:24px">Schedule adjustments to review</h3><table id="op-adj"></table>'
    + '</div></div>';

  function render(elem, data, cols) { table.render({ elem: elem, data: data, cols: [cols] }); }

  function refreshReimbursements() {
    return StudioApi.get('/api/v1/reimbursements?status=submitted&size=20').then(function (r) {
      render('#op-reimb', (r.data && r.data.data) || [], [
        { field: 'reimbursement_no', title: 'No', width: 200 },
        { field: 'submitter_user_id', title: 'By', width: 80 },
        { field: 'amount', title: 'Amount', width: 120, templet: function (d) { return '<span class="money">' + d.amount + '</span>'; } },
        { field: 'merchant', title: 'Merchant' },
        { field: 'submitted_at', title: 'Submitted', width: 170 },
        { fixed: 'right', title: 'Open', width: 90, templet: function () { return '<a href="#reimbursements" data-route="reimbursements" class="layui-btn layui-btn-xs">Review</a>'; } },
      ]);
      return r;
    });
  }
  function refreshCorrections() {
    return StudioApi.get('/api/v1/attendance/corrections?status=submitted&size=20').then(function (r) {
      render('#op-corr', (r.data && r.data.data) || [], [
        { field: 'id', title: 'ID', width: 80 },
        { field: 'requested_by_user_id', title: 'By', width: 80 },
        { field: 'reason', title: 'Reason' },
        { field: 'created_at', title: 'Submitted', width: 170 },
      ]);
      return r;
    });
  }
  function refreshAdjustments() {
    return StudioApi.get('/api/v1/schedule/adjustments?status=submitted&size=20').then(function (r) {
      render('#op-adj', (r.data && r.data.data) || [], [
        { field: 'id', title: 'ID', width: 80 },
        { field: 'requested_by_user_id', title: 'By', width: 80 },
        { field: 'reason', title: 'Reason' },
        { field: 'created_at', title: 'Submitted', width: 170 },
      ]);
      return r;
    });
  }

  // Initial fetch
  refreshReimbursements(); refreshCorrections(); refreshAdjustments();

  // MEDIUM fix audit-3 #4: adaptive polling — base 5 s, doubles on failure
  // up to 60 s, snaps back to base on the next clean refresh. Live cadence
  // is shown in the header so reviewers can tell when the dashboard slows
  // down because the backend is unhealthy.
  var statusEl = document.getElementById('op-poll-status');
  var poller = StudioApi.schedulePoll(function () {
    return Promise.all([refreshReimbursements(), refreshCorrections(), refreshAdjustments()])
      .then(function () { return { code: 0 }; })
      .catch(function () { return { code: 50000 }; });
  }, { baseMs: 5000, maxMs: 60000 });
  poller.onTick(function (s) {
    statusEl.textContent = '· refresh ' + Math.round(s.intervalMs / 1000) + 's' + (s.failure ? ' (degraded)' : '');
  });

  // Stop polling on navigation away.
  window.addEventListener('hashchange', function () { poller.cancel(); }, { once: true });
});
