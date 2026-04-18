/* Budget utilization: cap / consumed / active / available per allocation */
layui.use(['table'], function () {
  var table = layui.table;
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Budget utilization</div><div class="layui-card-body"><table id="bu-list"></table></div></div>';
  StudioApi.get('/api/v1/budget/utilization').then(function (res) {
    table.render({elem:'#bu-list', data:res.data || [], cols:[[
      {field:'allocation_id', width:80, title:'Alloc'},
      {field:'category_id', width:80, title:'Cat'},
      {field:'period_id', width:80, title:'Period'},
      {field:'scope_type', width:110, title:'Scope'},
      {field:'cap', title:'Cap', templet: function (d) { return '<span class="money">'+d.cap+'</span>'; }},
      {field:'confirmed_spend', title:'Spent', templet: function (d) { return '<span class="money">'+d.confirmed_spend+'</span>'; }},
      {field:'active_commitments', title:'In flight', templet: function (d) { return '<span class="money">'+d.active_commitments+'</span>'; }},
      {field:'available', title:'Available', templet: function (d) {
        var cls = d.over_cap ? 'layui-bg-red' : ''; return '<span class="money '+cls+'">'+d.available+'</span>';
      }}
    ]]});
  });
});
