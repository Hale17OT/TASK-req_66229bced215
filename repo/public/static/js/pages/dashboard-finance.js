/* Finance landing — budget health + commitments + recent settlements. */
layui.use(['table', 'element'], function () {
  var table = layui.table;
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Finance overview</div><div class="layui-card-body">'
    + '<h3>Budget utilization (your scope)</h3><table id="fn-util"></table>'
    + '<h3 style="margin-top:24px">Active commitments</h3><table id="fn-commit"></table>'
    + '<h3 style="margin-top:24px">Pending settlements</h3><table id="fn-settle"></table>'
    + '</div></div>';

  StudioApi.get('/api/v1/budget/utilization').then(function (r) {
    table.render({elem:'#fn-util', data:r.data || [], cols:[[
      {field:'category_id', title:'Cat', width:80},
      {field:'scope_type', title:'Scope', width:120},
      {field:'cap', title:'Cap', width:120, templet: function (d) { return '<span class="money">'+d.cap+'</span>'; }},
      {field:'confirmed_spend', title:'Spent', width:120, templet: function (d) { return '<span class="money">'+d.confirmed_spend+'</span>'; }},
      {field:'active_commitments', title:'In flight', width:140, templet: function (d) { return '<span class="money">'+d.active_commitments+'</span>'; }},
      {field:'available', title:'Available', templet: function (d) {
        return '<span class="money '+(d.over_cap ? 'layui-bg-red' : '')+'">'+d.available+'</span>';
      }},
    ]]});
  });
  StudioApi.get('/api/v1/budget/commitments?status=active&size=20').then(function (r) {
    table.render({elem:'#fn-commit', data:(r.data && r.data.data) || [], cols:[[
      {field:'reimbursement_id', title:'R#', width:80},
      {field:'allocation_id', title:'Alloc', width:80},
      {field:'amount', title:'Amount', width:140, templet: function (d) { return '<span class="money">'+d.amount+'</span>'; }},
      {field:'status', title:'Status', width:120},
      {field:'created_at', title:'Created', width:170},
    ]]});
  });
  StudioApi.get('/api/v1/settlements?status=recorded_not_confirmed&size=20').then(function (r) {
    table.render({elem:'#fn-settle', data:(r.data && r.data.data) || [], cols:[[
      {field:'settlement_no', title:'No', width:200},
      {field:'reimbursement_id', title:'R#', width:80},
      {field:'method', title:'Method', width:140},
      {field:'gross_amount', title:'Amount', width:120, templet: function (d) { return '<span class="money">'+d.gross_amount+'</span>'; }},
      {field:'recorded_at', title:'Recorded', width:170},
    ]]});
  });
});
