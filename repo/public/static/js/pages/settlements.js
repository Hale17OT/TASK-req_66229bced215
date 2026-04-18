/* Settlements: list + record + confirm/refund/exception (idempotent retries). */
layui.use(['table', 'form', 'layer'], function () {
  var table = layui.table, form = layui.form, layer = layui.layer;
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Settlements</div><div class="layui-card-body">'
    + '<form class="layui-form" lay-filter="sf">'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Reimbursement ID</label><div class="layui-input-inline"><input class="layui-input" name="reimbursement_id" required/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Method</label><div class="layui-input-inline"><select name="method"><option value="cash">cash</option><option value="check">check</option><option value="terminal_batch_entry">terminal batch</option></select></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Amount</label><div class="layui-input-inline"><input class="layui-input" name="gross_amount" placeholder="125.00"/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Check #</label><div class="layui-input-inline"><input class="layui-input" name="check_number"/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Batch ref</label><div class="layui-input-inline"><input class="layui-input" name="terminal_batch_ref"/></div></div>'
    + '<div class="layui-form-item"><div class="layui-input-block"><button class="layui-btn" lay-submit lay-filter="sfGo">Record settlement</button></div></div>'
    + '</form><table id="s-list"></table></div></div>';

  function reload() {
    StudioApi.get('/api/v1/settlements?size=100').then(function (res) {
      var rows = (res.data && res.data.data) || res.data || [];
      table.render({elem:'#s-list', data:rows, cols:[[
        {field:'settlement_no', title:'No', width:200},
        {field:'reimbursement_id', title:'R#', width:80},
        {field:'method', title:'Method', width:160},
        {field:'gross_amount', title:'Amount', width:140, templet: function (d) { return '<span class="money">'+d.gross_amount+'</span>'; }},
        {field:'status', title:'Status', width:180},
        {fixed:'right', title:'Actions', width:300, templet: function (d) {
          var html = '';
          if (d.status === 'recorded_not_confirmed') html += '<a class="layui-btn layui-btn-xs" data-sx="confirm" data-id="'+d.id+'">Confirm</a>';
          if (d.status === 'confirmed' || d.status === 'partially_refunded') html += '<a class="layui-btn layui-btn-xs layui-btn-warm" data-sx="refund" data-id="'+d.id+'">Refund</a>';
          html += '<a class="layui-btn layui-btn-xs layui-btn-danger" data-sx="exception" data-id="'+d.id+'">Exception</a>';
          return html;
        }}
      ]]});
    });
  }

  // All mutating settlement actions go through idempotentAction so a
  // mid-request network drop can be safely retried. The server's
  // idempotency middleware caches the first response under the per-call
  // Idempotency-Key and replays it on retry — confirm/refund/exception
  // side effects fire exactly once.
  function decision(url, body, okMsg) {
    return StudioApi.idempotentAction('POST', url, body || {}).then(function (r) {
      if (r.code !== 0) return layer.alert(r.message);
      layer.msg(okMsg);
      reload();
    });
  }

  form.on('submit(sfGo)', function (data) {
    decision('/api/v1/settlements', data.field, 'recorded');
    return false;
  });

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-sx]'); if (!b) return;
    var act = b.getAttribute('data-sx'), id = b.getAttribute('data-id');
    if (act === 'confirm') {
      decision('/api/v1/settlements/'+id+'/confirm', {}, 'confirmed');
    } else if (act === 'refund') {
      layer.prompt({title:'Refund amount', formType:0}, function (amt, idx) {
        layer.close(idx);
        layer.prompt({title:'Reason', formType:2}, function (rsn, idx2) {
          layer.close(idx2);
          decision('/api/v1/settlements/'+id+'/refund', {amount: amt, reason: rsn}, 'refunded');
        });
      });
    } else if (act === 'exception') {
      layer.prompt({title:'Exception reason', formType:2}, function (rsn, idx) {
        layer.close(idx);
        decision('/api/v1/settlements/'+id+'/exception', {reason: rsn}, 'marked');
      });
    }
  });

  reload();
});
