/* Generic landing — used when none of the role-specific dashboards apply. */
layui.use([], function () {
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Welcome</div>'
    + '<div class="layui-card-body"><p class="muted">Pick a section from the left to get started.</p></div></div>';
});
