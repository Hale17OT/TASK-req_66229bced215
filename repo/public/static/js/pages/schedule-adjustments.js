/* Schedule adjustments page — permission-aware buttons + draft persistence. */
layui.use(['table', 'form', 'layer'], function () {
  var table = layui.table, form = layui.form, layer = layui.layer;

  StudioApi.get('/api/v1/auth/me').then(function (me) {
    var caps = (me.data && me.data.capabilities && me.data.capabilities.schedule) || {};
    var canRequest = !!caps.request_adjustment;
    var canReview  = !!caps.review_adjustment;

    document.getElementById('page-root').innerHTML =
      '<div class="layui-card"><div class="layui-card-header">Schedule adjustments</div><div class="layui-card-body">'
      + (canRequest
        ? '<form id="sa-form" class="layui-form" lay-filter="newAdj">'
          + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Schedule entry ID</label><div class="layui-input-inline"><input class="layui-input" name="target_entry_id" required/></div></div>'
          + '<div class="layui-form-item layui-inline"><label class="layui-form-label">New starts_at</label><div class="layui-input-inline"><input class="layui-input" name="starts_at"/></div></div>'
          + '<div class="layui-form-item layui-inline"><label class="layui-form-label">New ends_at</label><div class="layui-input-inline"><input class="layui-input" name="ends_at"/></div></div>'
          + '<div class="layui-form-item"><label class="layui-form-label">Reason</label><div class="layui-input-block"><textarea class="layui-textarea" name="reason" placeholder="Min 10 chars" required lay-verify="required"></textarea></div></div>'
          + '<div class="layui-form-item"><div class="layui-input-block"><button class="layui-btn" lay-submit lay-filter="newAdjGo">Submit</button>'
          + '<span class="muted" id="sa-draft-hint" style="margin-left:12px"></span></div></div>'
          + '</form>'
        : '')
      + '<table id="adj-list"></table></div></div>';

    var draftBinding = null;
    if (canRequest) {
      var draftToken = 'schedule-adjustment-new-u' + (me.data && me.data.id);
      draftBinding = StudioApi.Drafts.bindForm(document.getElementById('sa-form'), draftToken);
    }

    function reload() {
      StudioApi.get('/api/v1/schedule/adjustments?size=100').then(function (res) {
        if (res.code !== 0) return layer.msg(res.message);
        var rows = (res.data && res.data.data) || res.data || [];
        table.render({elem:'#adj-list', data:rows, cols:[[
          {field:'id', width:60, title:'ID'},
          {field:'target_entry_id', width:120, title:'Target'},
          {field:'requested_by_user_id', width:100, title:'Requester'},
          {field:'status', width:120, title:'Status'},
          {field:'reason', title:'Reason'},
          {fixed:'right', title:'Action', width:240, templet: function (d) {
            if (d.status !== 'submitted') return '';
            var html = '';
            if (canReview) {
              html += '<a class="layui-btn layui-btn-xs" data-act2="approve" data-id="'+d.id+'">Approve</a>';
              html += '<a class="layui-btn layui-btn-xs layui-btn-danger" data-act2="reject" data-id="'+d.id+'">Reject</a>';
            }
            if (canRequest && (me.data && d.requested_by_user_id === me.data.id)) {
              html += '<a class="layui-btn layui-btn-xs layui-btn-warm" data-act2="withdraw" data-id="'+d.id+'">Withdraw</a>';
            }
            return html;
          }}
        ]]});
      });
    }

    if (canRequest) {
      form.on('submit(newAdjGo)', function (data) {
        var f = data.field;
        var changes = {};
        if (f.starts_at) changes.starts_at = f.starts_at;
        if (f.ends_at)   changes.ends_at   = f.ends_at;
        var opts = draftBinding ? {idempotencyKey: draftBinding.idemKey()} : null;
        StudioApi.post('/api/v1/schedule/adjustments', {
          target_entry_id: parseInt(f.target_entry_id, 10),
          reason: f.reason, proposed_changes: changes
        }, opts).then(function (res) {
          if (res.code !== 0) return layer.alert(res.message);
          if (draftBinding) {
            draftBinding.clear();
            draftBinding.rotateIdemKey();
            document.getElementById('sa-form').reset();
          }
          layer.msg('submitted'); reload();
        });
        return false;
      });
    }

    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-act2]'); if (!b) return;
      var id = b.getAttribute('data-id'), act = b.getAttribute('data-act2');
      // Decision actions go through idempotentAction so a reconnect-retry
      // after a network drop replays the same Idempotency-Key (server
      // returns the cached response — no duplicate side effects).
      var go = function (cmt) {
        StudioApi.idempotentAction('POST', '/api/v1/schedule/adjustments/'+id+'/'+act, {comment: cmt || ''}).then(function (res) {
          if (res.code !== 0) return layer.alert(res.message); layer.msg('done'); reload();
        });
      };
      if (act === 'reject') layer.prompt({title:'Rejection comment (min 10)', formType:2}, function (c, i) { layer.close(i); go(c); });
      else go('');
    });
    reload();
  });
});
