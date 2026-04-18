/* Page module: change own password */
layui.use(['form', 'layer'], function () {
  var form = layui.form, layer = layui.layer;
  var root = document.getElementById('page-root');
  root.innerHTML = '<div class="layui-card"><div class="layui-card-header">Change password</div>'
    + '<div class="layui-card-body">'
    + '<form class="layui-form" lay-filter="cpwd">'
    + '<div class="layui-form-item"><label class="layui-form-label">Current</label><div class="layui-input-block"><input type="password" name="current_password" class="layui-input" required lay-verify="required"/></div></div>'
    + '<div class="layui-form-item"><label class="layui-form-label">New</label><div class="layui-input-block"><input type="password" name="new_password" class="layui-input" required lay-verify="required"/></div></div>'
    + '<div class="layui-form-item"><div class="layui-input-block"><button class="layui-btn" lay-submit lay-filter="cpwdGo">Change</button>'
    + '<p class="muted" style="margin-top:8px">Min 12 chars, with uppercase + lowercase + digit + special. Cannot reuse last 5.</p></div></div>'
    + '</form></div></div>';
  form.render();
  form.on('submit(cpwdGo)', function (data) {
    StudioApi.post('/api/v1/auth/password/change', data.field).then(function (res) {
      if (res.code !== 0) {
        var msg = res.message || 'Failed';
        if (res.errors && res.errors.password) msg += '\n' + res.errors.password.join('\n');
        layer.alert(msg);
      } else {
        layer.alert('Password changed.');
      }
    });
    return false;
  });
});
