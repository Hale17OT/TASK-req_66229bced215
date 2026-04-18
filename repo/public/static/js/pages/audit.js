/* Audit search */
layui.use(['table', 'form', 'laydate'], function () {
  var table = layui.table, form = layui.form, laydate = layui.laydate;
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Audit search</div><div class="layui-card-body">'
    + '<form class="layui-form" lay-filter="auditF">'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">From</label><div class="layui-input-inline"><input id="af" class="layui-input" name="from"/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">To</label><div class="layui-input-inline"><input id="at" class="layui-input" name="to"/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Action</label><div class="layui-input-inline"><input class="layui-input" name="action"/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Entity</label><div class="layui-input-inline"><input class="layui-input" name="target_entity"/></div></div>'
    + '<div class="layui-form-item layui-inline"><div class="layui-input-inline"><button class="layui-btn" lay-submit lay-filter="auditGo">Search</button></div></div>'
    + '</form><table id="audit-list"></table></div></div>';
  laydate.render({elem:'#af', type:'datetime'}); laydate.render({elem:'#at', type:'datetime'});
  function load(filters) {
    var qs = Object.keys(filters).filter(function (k) { return filters[k]; }).map(function (k) { return k + '=' + encodeURIComponent(filters[k]); }).join('&');
    StudioApi.get('/api/v1/audit?size=200&' + qs).then(function (res) {
      var rows = (res.data && res.data.data) || res.data || [];
      table.render({elem:'#audit-list', data:rows, cols:[[
        {field:'id', width:80, title:'ID'},
        {field:'occurred_at', width:200, title:'When'},
        {field:'actor_username', width:160, title:'Actor'},
        {field:'action', width:240, title:'Action'},
        {field:'target_entity', width:140, title:'Entity'},
        {field:'target_entity_id', width:120, title:'Entity ID'},
        {field:'outcome', width:100, title:'Outcome'},
        {field:'ip', width:140, title:'IP'},
      ]]});
    });
  }
  form.on('submit(auditGo)', function (data) { load(data.field); return false; });
  load({});
});
