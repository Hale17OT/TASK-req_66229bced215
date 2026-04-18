/* Page module: locations + departments */
layui.use(['layer'], function () {
  var layer = layui.layer;
  var root = document.getElementById('page-root');
  root.innerHTML = '<div class="layui-row layui-col-space12">'
    + '<div class="layui-col-md6"><div class="layui-card"><div class="layui-card-header">Locations</div><div class="layui-card-body" id="locs"></div></div></div>'
    + '<div class="layui-col-md6"><div class="layui-card"><div class="layui-card-header">Departments</div><div class="layui-card-body" id="deps"></div></div></div>'
    + '</div>';
  function table(rows) {
    var h = '<table class="layui-table"><thead><tr><th>Code</th><th>Name</th><th>Status</th></tr></thead><tbody>';
    rows.forEach(function (r) { h += '<tr><td>'+r.code+'</td><td>'+r.name+'</td><td>'+r.status+'</td></tr>'; });
    return h + '</tbody></table>';
  }
  StudioApi.get('/api/v1/admin/locations').then(function (r) { document.getElementById('locs').innerHTML = table(r.data || []); });
  StudioApi.get('/api/v1/admin/departments').then(function (r) { document.getElementById('deps').innerHTML = table(r.data || []); });
});
