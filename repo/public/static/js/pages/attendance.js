/* Page: record attendance + view list */
layui.use(['table', 'form', 'layer', 'laydate'], function () {
  var table = layui.table, form = layui.form, layer = layui.layer, laydate = layui.laydate;
  var root = document.getElementById('page-root');
  root.innerHTML = '<div class="layui-card"><div class="layui-card-header">Record attendance</div><div class="layui-card-body">'
    + '<form class="layui-form" lay-filter="att">'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Location</label><div class="layui-input-inline"><select name="location_id" id="loc"></select></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Member ref</label><div class="layui-input-inline"><input class="layui-input" name="member_reference"/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Member name</label><div class="layui-input-inline"><input class="layui-input" name="member_name"/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">When</label><div class="layui-input-inline"><input id="occ" class="layui-input" name="occurred_at"/></div></div>'
    + '<div class="layui-form-item"><div class="layui-input-block"><button class="layui-btn" lay-submit lay-filter="attGo">Record</button></div></div>'
    + '</form></div></div>'
    + '<div class="layui-card"><div class="layui-card-header">Recent attendance</div><div class="layui-card-body"><table id="att-list" lay-filter="att-list"></table></div></div>';

  laydate.render({elem:'#occ', type:'datetime'});
  // Scope-aware reference endpoint: Front Desk can't enumerate the admin
  // location catalog (auth.manage_users), only the locations inside its
  // data scope. See LocationController::referenceList.
  StudioApi.get('/api/v1/locations').then(function (r) {
    var html = (r.data || []).map(function (l) { return '<option value="'+l.id+'">'+l.code+' — '+l.name+'</option>'; }).join('');
    document.getElementById('loc').innerHTML = html; form.render('select');
  });

  function reload() {
    StudioApi.get('/api/v1/attendance/records?size=50').then(function (res) {
      table.render({ elem:'#att-list', data:(res.data && res.data.data) || res.data || [], cols:[[
        {field:'id', width:60, title:'ID'},
        {field:'location_id', width:80, title:'Loc'},
        {field:'occurred_at', title:'When', width:170},
        {field:'member_name', title:'Member'},
        {field:'attendance_type', title:'Type', width:120},
        {field:'status', title:'Status', width:100}
      ]]});
    });
  }
  form.on('submit(attGo)', function (data) {
    StudioApi.post('/api/v1/attendance/records', data.field).then(function (res) {
      if (res.code !== 0) return layer.msg(res.message);
      layer.msg('recorded'); reload();
    });
    return false;
  });
  reload();
});
