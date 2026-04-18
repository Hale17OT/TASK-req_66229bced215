/* Budget allocations: list + create monthly cap */
layui.use(['table', 'form', 'layer', 'laydate'], function () {
  var table = layui.table, form = layui.form, layer = layui.layer, laydate = layui.laydate;
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Budget allocations</div><div class="layui-card-body">'
    + '<form class="layui-form" lay-filter="baf">'
    + '<div class="layui-form-item"><label class="layui-form-label">Category</label><div class="layui-input-inline"><select name="category_id" id="ba-cat"></select></div>'
    + '<label class="layui-form-label">Scope</label><div class="layui-input-inline"><select name="scope_type" lay-filter="ba-scope"><option value="org">org</option><option value="location">location</option><option value="department">department</option></select></div></div>'
    + '<div class="layui-form-item" id="ba-loc-row" style="display:none"><label class="layui-form-label">Location ID</label><div class="layui-input-inline"><input class="layui-input" name="location_id"/></div></div>'
    + '<div class="layui-form-item" id="ba-dep-row" style="display:none"><label class="layui-form-label">Department ID</label><div class="layui-input-inline"><input class="layui-input" name="department_id"/></div></div>'
    + '<div class="layui-form-item"><label class="layui-form-label">Period</label><div class="layui-input-inline"><input id="ps" class="layui-input" name="period_start" placeholder="2026-04-01"/></div>'
    + '<div class="layui-input-inline"><input id="pe" class="layui-input" name="period_end" placeholder="2026-04-30"/></div></div>'
    + '<div class="layui-form-item"><label class="layui-form-label">Cap</label><div class="layui-input-inline"><input class="layui-input" name="cap_amount" placeholder="25000.00"/></div></div>'
    + '<div class="layui-form-item"><div class="layui-input-block"><button class="layui-btn" lay-submit lay-filter="bafGo">Create allocation</button></div></div>'
    + '</form><table id="ba-list"></table></div></div>';
  laydate.render({elem:'#ps'}); laydate.render({elem:'#pe'});
  StudioApi.get('/api/v1/budget/categories').then(function (r) {
    document.getElementById('ba-cat').innerHTML = (r.data || []).map(function (c) { return '<option value="'+c.id+'">'+c.name+'</option>'; }).join('');
    form.render('select');
  });
  form.on('select(ba-scope)', function (d) {
    document.getElementById('ba-loc-row').style.display = d.value === 'location' ? '' : 'none';
    document.getElementById('ba-dep-row').style.display = d.value === 'department' ? '' : 'none';
  });
  function load() {
    StudioApi.get('/api/v1/budget/allocations?size=200').then(function (res) {
      var rows = (res.data && res.data.data) || res.data || [];
      table.render({elem:'#ba-list', data:rows, cols:[[
        {field:'id', width:60, title:'ID'}, {field:'category_id', width:100, title:'Cat'},
        {field:'period_id', width:80, title:'Period'}, {field:'scope_type', width:100, title:'Scope'},
        {field:'location_id', width:80, title:'Loc'}, {field:'department_id', width:80, title:'Dep'},
        {field:'cap_amount', width:140, title:'Cap', templet: function (d) { return '<span class="money">'+d.cap_amount+'</span>'; }},
        {field:'status', width:120, title:'Status'}
      ]]});
    });
  }
  form.on('submit(bafGo)', function (data) {
    var f = data.field;
    StudioApi.post('/api/v1/budget/allocations', f).then(function (res) {
      if (res.code !== 0) return layer.alert(res.message); layer.msg('created'); load();
    });
    return false;
  });
  load();
});
