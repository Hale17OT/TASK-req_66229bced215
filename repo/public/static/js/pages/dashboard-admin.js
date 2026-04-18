/* Administrator landing — org-wide health snapshot. */
layui.use(['element'], function () {
  document.getElementById('page-root').innerHTML =
    '<div class="layui-card"><div class="layui-card-header">Administrator overview</div>'
    + '<div class="layui-card-body">'
    + '<div class="layui-row layui-col-space12">'
    + tile('Users',                'admin-users',          '#') + tile('Roles & permissions', 'admin-roles', '#')
    + tile('Locations / depts',    'admin-locations',      '#') + tile('Audit search',        'audit',        '#')
    + tile('Reimbursements',       'reimbursements',       '#') + tile('Settlements',         'settlements',  '#')
    + tile('Budget utilization',   'budget-utilization',   '#') + tile('Reconciliation',      'reconciliation','#')
    + '</div>'
    + '<h3 style="margin-top:24px">Pending approvals (org-wide)</h3>'
    + '<table id="adm-queue"></table>'
    + '</div></div>';
  function tile(label, route, n) {
    return '<div class="layui-col-md3"><a href="#'+route+'" data-route="'+route+'" class="layui-card" style="display:block;text-align:center;padding:24px 8px;text-decoration:none;color:inherit"><div style="font-size:14px" class="muted">'+label+'</div><div style="font-size:28px;margin-top:6px">'+n+'</div></a></div>';
  }
  // Recent approval queue
  layui.use(['table'], function () {
    var table = layui.table;
    StudioApi.get('/api/v1/reimbursements?status=submitted&size=15').then(function (r) {
      table.render({elem:'#adm-queue', data:(r.data && r.data.data) || [], cols:[[
        {field:'reimbursement_no', title:'No', width:200},
        {field:'submitter_user_id', title:'By', width:80},
        {field:'amount', title:'Amount', width:120},
        {field:'merchant', title:'Merchant'},
        {field:'submitted_at', title:'Submitted', width:170},
      ]]});
    });
  });
});
