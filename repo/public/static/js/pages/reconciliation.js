/* Reconciliation: start runs + list */
layui.use(['table', 'form', 'layer', 'laydate'], function () {
  var table = layui.table, form = layui.form, layer = layui.layer, laydate = layui.laydate;
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Reconciliation</div><div class="layui-card-body">'
    + '<form class="layui-form" lay-filter="reconF">'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Period start</label><div class="layui-input-inline"><input id="r-ps" class="layui-input" name="period_start"/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Period end</label><div class="layui-input-inline"><input id="r-pe" class="layui-input" name="period_end"/></div></div>'
    + '<div class="layui-form-item layui-inline"><div class="layui-input-inline"><button class="layui-btn" lay-submit lay-filter="reconGo">Run reconciliation</button></div></div>'
    + '</form><table id="recon-list"></table></div></div>';
  laydate.render({elem:'#r-ps'}); laydate.render({elem:'#r-pe'});
  function reload() {
    StudioApi.get('/api/v1/reconciliation/runs').then(function (res) {
      var rows = (res.data && res.data.data) || res.data || [];
      table.render({elem:'#recon-list', data:rows, cols:[[
        {field:'id', width:60, title:'ID'},
        {field:'period_start', width:120, title:'Start'},
        {field:'period_end', width:120, title:'End'},
        {field:'status', width:120, title:'Status'},
        {field:'started_at', width:170, title:'Started'},
        {field:'completed_at', width:170, title:'Completed'},
        {field:'summary_json', title:'Summary', templet: function (d) { return '<code>'+JSON.stringify(d.summary_json||{})+'</code>'; }}
      ]]});
    });
  }
  form.on('submit(reconGo)', function (data) {
    StudioApi.post('/api/v1/reconciliation/runs', data.field).then(function (res) {
      if (res.code !== 0) return layer.alert(res.message); layer.msg('done'); reload();
    });
    return false;
  });
  reload();
});
