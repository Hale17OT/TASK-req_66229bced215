/* Exports: queue and download CSVs */
layui.use(['table', 'form', 'layer'], function () {
  var table = layui.table, form = layui.form, layer = layui.layer;
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">CSV exports</div><div class="layui-card-body">'
    + '<form class="layui-form" lay-filter="ef">'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Kind</label><div class="layui-input-inline"><select name="kind"><option value="audit">audit</option><option value="reimbursements">reimbursements</option><option value="settlements">settlements</option><option value="budget_utilization">budget_utilization</option></select></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">From</label><div class="layui-input-inline"><input class="layui-input" name="from" placeholder="2026-04-01"/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">To</label><div class="layui-input-inline"><input class="layui-input" name="to" placeholder="2026-04-30"/></div></div>'
    + '<div class="layui-form-item layui-inline"><div class="layui-input-inline"><button class="layui-btn" lay-submit lay-filter="efGo">Generate</button></div></div>'
    + '</form><table id="ex-list"></table></div></div>';
  function reload() {
    StudioApi.get('/api/v1/exports').then(function (res) {
      var rows = (res.data && res.data.data) || res.data || [];
      table.render({elem:'#ex-list', data:rows, cols:[[
        {field:'id', width:60, title:'ID'},
        {field:'kind', width:160, title:'Kind'},
        {field:'status', width:120, title:'Status'},
        {field:'row_count', width:100, title:'Rows'},
        {field:'created_at', width:170, title:'Created'},
        {field:'completed_at', width:170, title:'Completed'},
        {fixed:'right', title:'Action', width:160, templet: function (d) {
          return d.status === 'completed' ? '<a class="layui-btn layui-btn-xs" data-dl="'+d.id+'">Download</a>' : '';
        }}
      ]]});
    });
  }
  form.on('submit(efGo)', function (data) {
    var f = data.field;
    var filters = {};
    if (f.from) filters.from = f.from;
    if (f.to)   filters.to = f.to;
    StudioApi.post('/api/v1/exports', { kind: f.kind, filters: filters }).then(function (res) {
      if (res.code !== 0) return layer.alert(res.message); layer.msg('queued'); reload();
    });
    return false;
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-dl]'); if (!b) return;
    window.open('/api/v1/exports/'+b.getAttribute('data-dl')+'/download', '_blank');
  });
  reload();
});
