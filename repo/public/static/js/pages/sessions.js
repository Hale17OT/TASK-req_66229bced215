/* Page module: My sessions */
layui.use(['layer'], function () {
  var layer = layui.layer;
  var root = document.getElementById('page-root');
  root.innerHTML = '<div class="layui-card"><div class="layui-card-header">My active sessions</div>'
    + '<div class="layui-card-body" id="sb">Loading...</div></div>';
  function load() {
    StudioApi.get('/api/v1/sessions').then(function (res) {
      var rows = res.data || [];
      var html = '<table class="layui-table"><thead><tr><th>IP</th><th>User agent</th><th>Created</th><th>Last activity</th><th>Expires</th><th></th></tr></thead><tbody>';
      rows.forEach(function (r) {
        html += '<tr><td>'+(r.ip||'')+'</td><td>'+(r.user_agent||'').slice(0,80)+'</td>'
              + '<td>'+r.created_at+'</td><td>'+r.last_activity_at+'</td><td>'+r.expires_at+'</td>'
              + '<td><a class="layui-btn layui-btn-xs layui-btn-danger" data-revoke="'+r.id+'">Revoke</a></td></tr>';
      });
      html += '</tbody></table>';
      document.getElementById('sb').innerHTML = html;
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-revoke]'); if (!b) return;
    StudioApi.del('/api/v1/sessions/'+b.getAttribute('data-revoke')).then(function () { layer.msg('revoked'); load(); });
  });
  load();
});
