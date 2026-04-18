/* Page: my coach schedule */
layui.use(['table'], function () {
  var table = layui.table;
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">My schedule</div><div class="layui-card-body"><table id="sched"></table></div></div>';
  StudioApi.get('/api/v1/schedule/entries?size=200').then(function (res) {
    var rows = (res.data && res.data.data) || res.data || [];
    table.render({elem:'#sched', data:rows, cols:[[
      {field:'id', width:60, title:'ID'},
      {field:'starts_at', title:'Starts', width:170},
      {field:'ends_at', title:'Ends', width:170},
      {field:'title', title:'Title'},
      {field:'location_id', width:80, title:'Loc'},
      {field:'status', width:110, title:'Status'},
    ]]});
  });
});
