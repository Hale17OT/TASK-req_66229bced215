/* Coach landing — next 7 days schedule + pending adjustments. */
layui.use(['table'], function () {
  var table = layui.table;
  var from = new Date(); var to = new Date(Date.now() + 7 * 86400 * 1000);
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Coach dashboard</div><div class="layui-card-body">'
    + '<h3>Your schedule for the next 7 days</h3><table id="co-sched"></table>'
    + '<h3 style="margin-top:24px">Your pending adjustment requests</h3><table id="co-adj"></table>'
    + '</div></div>';
  StudioApi.get('/api/v1/schedule/entries?from=' + encodeURIComponent(from.toISOString().slice(0,10))
    + '&to=' + encodeURIComponent(to.toISOString().slice(0,10)) + '&size=200').then(function (r) {
    table.render({elem:'#co-sched', data:(r.data && r.data.data) || r.data || [], cols:[[
      {field:'starts_at', title:'Starts', width:170},
      {field:'ends_at',   title:'Ends',   width:170},
      {field:'title',     title:'Title'},
      {field:'location_id', title:'Loc', width:80},
      {field:'status', title:'Status', width:110},
    ]]});
  });
  StudioApi.get('/api/v1/schedule/adjustments?status=submitted&size=20').then(function (r) {
    table.render({elem:'#co-adj', data:(r.data && r.data.data) || [], cols:[[
      {field:'id', title:'ID', width:80},
      {field:'target_entry_id', title:'Entry', width:100},
      {field:'reason', title:'Reason'},
      {field:'created_at', title:'Submitted', width:170},
    ]]});
  });
});
