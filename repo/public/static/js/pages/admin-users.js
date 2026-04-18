/* Page module: User admin (list + lock/unlock/reset). Loaded by shell.js. */
layui.use(['table', 'layer'], function () {
  var table = layui.table, layer = layui.layer;
  var root = document.getElementById('page-root');
  root.innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Users</div>'
    + '<div class="layui-card-body">'
    + '<div class="studio-toolbar"><input id="user-search" class="layui-input" placeholder="search username/display name" style="max-width:280px"/>'
    + '<button class="layui-btn layui-btn-sm" id="user-search-go">Search</button>'
    + '<span class="grow"></span>'
    + '<button class="layui-btn layui-btn-sm layui-btn-normal" id="user-create">+ New user</button>'
    + '</div><table id="usersTable" lay-filter="usersTable"></table></div></div>';

  function load() {
    var q = document.getElementById('user-search').value.trim();
    StudioApi.get('/api/v1/admin/users?size=100&q=' + encodeURIComponent(q)).then(function (res) {
      if (res.code !== 0) { layer.msg(res.message); return; }
      table.render({
        elem: '#usersTable',
        data: res.data.data || res.data,
        cols: [[
          { field:'id',           title:'ID',        width:60  },
          { field:'username',     title:'Username',  width:140 },
          { field:'display_name', title:'Name',      width:180 },
          { field:'status',       title:'Status',    width:120 },
          { field:'last_login_at',title:'Last login',width:170 },
          { fixed:'right',        title:'Actions',   width:280, templet: function (d) {
            return '<a class="layui-btn layui-btn-xs" data-act="reset" data-id="'+d.id+'">Reset pwd</a>'
                 + (d.status === 'locked'
                     ? '<a class="layui-btn layui-btn-xs layui-btn-warm" data-act="unlock" data-id="'+d.id+'">Unlock</a>'
                     : '<a class="layui-btn layui-btn-xs layui-btn-danger" data-act="lock" data-id="'+d.id+'">Lock</a>')
                 + '<a class="layui-btn layui-btn-xs layui-btn-primary" data-act="revoke" data-id="'+d.id+'">Revoke sessions</a>';
          }}
        ]],
        page: false
      });
    });
  }
  document.getElementById('user-search-go').addEventListener('click', load);
  document.getElementById('user-create').addEventListener('click', function () {
    layer.prompt({title: 'New username (4-64 chars)'}, function (un, idx) {
      layer.close(idx);
      StudioApi.post('/api/v1/admin/users', { username: un, display_name: un, roles: [] }).then(function (res) {
        if (res.code !== 0) return layer.msg(res.message);
        layer.alert('Created. Temp password: <span class="kbd">'+res.data.temp_password+'</span>', {title:'Issued'});
        load();
      });
    });
  });
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-act]'); if (!el) return;
    var id = el.getAttribute('data-id'), act = el.getAttribute('data-act');
    var url, method = 'POST';
    if (act === 'reset')  url = '/api/v1/admin/users/'+id+'/reset-password';
    if (act === 'lock')   url = '/api/v1/admin/users/'+id+'/lock';
    if (act === 'unlock') url = '/api/v1/admin/users/'+id+'/unlock';
    if (act === 'revoke') { url = '/api/v1/admin/users/'+id+'/sessions'; method = 'DELETE'; }
    layer.confirm(act + ' user #' + id + '?', function () {
      (method === 'POST' ? StudioApi.post(url, {}) : StudioApi.del(url)).then(function (res) {
        if (res.code !== 0) return layer.msg(res.message);
        if (res.data && res.data.temp_password) {
          layer.alert('Temp password: <span class="kbd">'+res.data.temp_password+'</span>');
        } else {
          layer.msg('OK');
        }
        load();
      });
    });
  });
  load();
});
