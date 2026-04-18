/* Front desk landing — today's attendance + open corrections. */
layui.use(['table'], function () {
  var table = layui.table;
  var todayStart = new Date(); todayStart.setHours(0,0,0,0);
  var iso = todayStart.toISOString().slice(0,19).replace('T',' ');
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Front desk dashboard</div><div class="layui-card-body">'
    + '<div class="layui-row layui-col-space12">'
    + tile('Record attendance', 'attendance')
    + tile('My pending corrections', 'attendance-corrections')
    + '</div>'
    + '<h3 style="margin-top:24px">Today\'s attendance entries</h3><table id="fd-att"></table>'
    + '</div></div>';
  function tile(label, route) {
    return '<div class="layui-col-md4"><a href="#'+route+'" data-route="'+route+'" class="layui-card" style="display:block;text-align:center;padding:24px 8px;text-decoration:none;color:inherit"><div style="font-size:18px">'+label+'</div></a></div>';
  }
  StudioApi.get('/api/v1/attendance/records?from=' + encodeURIComponent(iso) + '&size=50').then(function (r) {
    table.render({elem:'#fd-att', data:(r.data && r.data.data) || [], cols:[[
      {field:'id', title:'ID', width:80},
      {field:'occurred_at', title:'When', width:170},
      {field:'member_name', title:'Member'},
      {field:'attendance_type', title:'Type', width:140},
      {field:'status', title:'Status', width:120},
    ]]});
  });
});
