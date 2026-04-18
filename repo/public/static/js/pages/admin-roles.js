/* Page module: Roles & permissions */
layui.use(['table', 'layer'], function () {
  var layer = layui.layer;
  var root = document.getElementById('page-root');
  root.innerHTML = '<div class="layui-card"><div class="layui-card-header">Roles & permissions</div>'
    + '<div class="layui-card-body" id="roles-body"><p class="muted">Loading...</p></div></div>';
  Promise.all([StudioApi.get('/api/v1/admin/roles'), StudioApi.get('/api/v1/admin/permissions')]).then(function (rs) {
    var roles = rs[0].data || [], perms = rs[1].data || [];
    var html = '<table class="layui-table"><thead><tr><th>Role</th><th>Description</th><th>Permission count</th></tr></thead><tbody>';
    roles.forEach(function (r) {
      html += '<tr><td><strong>'+r.key+'</strong><br><small class="muted">'+(r.name||'')+'</small></td>'
            + '<td>'+(r.description||'')+'</td>'
            + '<td>'+(r.permissions ? r.permissions.length : 0)+(r.is_system ? ' <span class="layui-badge-rim">system</span>' : '')+'</td></tr>';
    });
    html += '</tbody></table>';
    html += '<h3 style="margin-top:24px">Permission catalog</h3><table class="layui-table"><thead><tr><th>Category</th><th>Key</th><th>Description</th></tr></thead><tbody>';
    perms.forEach(function (p) {
      html += '<tr><td>'+p.category+'</td><td><code class="kbd">'+p.key+'</code></td><td>'+(p.description||'')+'</td></tr>';
    });
    html += '</tbody></table>';
    document.getElementById('roles-body').innerHTML = html;
  }).catch(function () { layer.msg('Failed to load roles'); });
});
