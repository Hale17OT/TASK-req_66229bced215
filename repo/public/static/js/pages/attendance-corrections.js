/* Attendance corrections page — permission-aware buttons + draft persistence. */
layui.use(['table', 'form', 'layer'], function () {
  var table = layui.table, form = layui.form, layer = layui.layer;

  StudioApi.get('/api/v1/auth/me').then(function (me) {
    var caps = (me.data && me.data.capabilities && me.data.capabilities.attendance) || {};
    var canRequest = !!caps.request_correction;
    var canReview  = !!caps.review_correction;

    var root = document.getElementById('page-root');
    root.innerHTML =
      '<div class="layui-card"><div class="layui-card-header">Attendance corrections</div><div class="layui-card-body">'
      + (canRequest
        ? '<form id="ac-form" class="layui-form" lay-filter="newCorr">'
          + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Target ID</label><div class="layui-input-inline"><input class="layui-input" name="target_attendance_id" required/></div></div>'
          + '<div class="layui-form-item"><label class="layui-form-label">Reason</label><div class="layui-input-block"><textarea name="reason" class="layui-textarea" placeholder="Min 10 characters" required lay-verify="required"></textarea></div></div>'
          + '<div class="layui-form-item"><label class="layui-form-label">New occurred_at</label><div class="layui-input-block"><input class="layui-input" name="payload_occurred_at"/></div></div>'
          + '<div class="layui-form-item"><div class="layui-input-block"><button class="layui-btn" lay-submit lay-filter="newCorrGo">Submit correction</button>'
          + '<span class="muted" id="ac-draft-hint" style="margin-left:12px"></span></div></div>'
          + '</form>'
        : '')
      + '<table id="corr-list" lay-filter="corr-list"></table></div></div>';

    var draftBinding = null;
    if (canRequest) {
      var draftToken = 'attendance-correction-new-u' + (me.data && me.data.id);
      draftBinding = StudioApi.Drafts.bindForm(document.getElementById('ac-form'), draftToken);
    }

    function reload() {
      StudioApi.get('/api/v1/attendance/corrections?size=100').then(function (res) {
        if (res.code !== 0) return layer.msg(res.message);
        var rows = (res.data && res.data.data) || res.data || [];
        table.render({elem:'#corr-list', data:rows, cols:[[
          {field:'id', width:60, title:'ID'},
          {field:'target_attendance_id', width:120, title:'Target'},
          {field:'requested_by_user_id', width:100, title:'Requester'},
          {field:'status', width:120, title:'Status'},
          {field:'reason', title:'Reason'},
          {fixed:'right', title:'Action', width:240, templet: function (d) {
            if (d.status !== 'submitted') return '';
            var html = '';
            // BLOCKER fix #5: gate review actions on review_correction perm.
            if (canReview) {
              html += '<a class="layui-btn layui-btn-xs" data-act="approve" data-id="'+d.id+'">Approve</a>';
              html += '<a class="layui-btn layui-btn-xs layui-btn-danger" data-act="reject" data-id="'+d.id+'">Reject</a>';
            }
            // Withdraw is owner-side; only show on rows the caller submitted.
            if (canRequest && (me.data && d.requested_by_user_id === me.data.id)) {
              html += '<a class="layui-btn layui-btn-xs layui-btn-warm" data-act="withdraw" data-id="'+d.id+'">Withdraw</a>';
            }
            return html;
          }}
        ]]});
      });
    }

    if (canRequest) {
      form.on('submit(newCorrGo)', function (data) {
        var f = data.field;
        var payload = {};
        if (f.payload_occurred_at) payload.occurred_at = f.payload_occurred_at;
        var opts = draftBinding ? {idempotencyKey: draftBinding.idemKey()} : null;
        StudioApi.post('/api/v1/attendance/corrections', {
          target_attendance_id: parseInt(f.target_attendance_id, 10),
          reason: f.reason,
          proposed_payload: payload,
        }, opts).then(function (res) {
          if (res.code !== 0) return layer.msg(res.message);
          if (draftBinding) {
            draftBinding.clear();
            draftBinding.rotateIdemKey();
            document.getElementById('ac-form').reset();
          }
          layer.msg('submitted');
          reload();
        });
        return false;
      });
    }

    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-act]'); if (!b) return;
      var id = b.getAttribute('data-id'), act = b.getAttribute('data-act');
      // Decision actions go through idempotentAction so a reconnect-retry
      // after a network drop replays the same Idempotency-Key and the
      // server returns the cached response without re-applying side effects.
      var fn = function (cmt) {
        StudioApi.idempotentAction('POST', '/api/v1/attendance/corrections/'+id+'/'+act, {comment: cmt || ''}).then(function (res) {
          if (res.code !== 0) return layer.alert(res.message);
          layer.msg('done'); reload();
        });
      };
      if (act === 'reject') {
        layer.prompt({title: 'Rejection comment (min 10 chars)', formType: 2}, function (cmt, idx) { layer.close(idx); fn(cmt); });
      } else if (act === 'approve') {
        layer.prompt({title: 'Approval comment (optional)', formType: 2, value: ''}, function (cmt, idx) { layer.close(idx); fn(cmt); });
      } else {
        fn('');
      }
    });
    reload();
  });
});
