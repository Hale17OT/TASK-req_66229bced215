/* Budget categories: list + create */
layui.use(['table', 'form', 'layer'], function () {
  var table = layui.table, form = layui.form, layer = layui.layer;
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Budget categories</div><div class="layui-card-body">'
    + '<form class="layui-form" lay-filter="bcf">'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Name</label><div class="layui-input-inline"><input class="layui-input" name="name" required/></div></div>'
    + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Code</label><div class="layui-input-inline"><input class="layui-input" name="code"/></div></div>'
    + '<div class="layui-form-item layui-inline"><div class="layui-input-inline"><button class="layui-btn" lay-submit lay-filter="bcfGo">Add</button></div></div>'
    + '</form><table id="bc-list"></table></div></div>';
  function load() {
    StudioApi.get('/api/v1/budget/categories').then(function (r) {
      table.render({elem:'#bc-list', data:r.data || [], cols:[[
        {field:'id', width:60, title:'ID'}, {field:'name', title:'Name'}, {field:'code', title:'Code', width:120},
        {field:'status', title:'Status', width:120}
      ]]});
    });
  }
  form.on('submit(bcfGo)', function (data) {
    StudioApi.post('/api/v1/budget/categories', data.field).then(function (res) {
      if (res.code !== 0) return layer.alert(res.message); layer.msg('added'); load();
    });
    return false;
  });
  load();
});
