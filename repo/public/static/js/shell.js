/* Shell — fetches /me, builds the role-aware nav, and dispatches hash routes
 * to per-page modules under /static/js/pages/. */
layui.use(['element', 'layer'], function () {
  var element = layui.element, layer = layui.layer;

  function buildNav(perms) {
    // Definition list keyed by permission required → menu group/item
    var groups = [
      {
        title: 'Operations', perm: null, items: [
          { route: 'attendance',          label: 'Attendance',           perm: 'attendance.record' },
          { route: 'attendance-corrections', label: 'Attendance corrections', perm: 'attendance.request_correction' },
          { route: 'schedule',            label: 'My schedule',          perm: 'schedule.view_assigned' },
          { route: 'schedule-adjustments',label: 'Schedule adjustments', perm: 'schedule.request_adjustment' }
        ]
      },
      {
        title: 'Finance', perm: null, items: [
          { route: 'budget-categories',   label: 'Budget categories',    perm: 'budget.manage_categories' },
          { route: 'budget-allocations',  label: 'Allocations',          perm: 'budget.manage_allocations' },
          { route: 'budget-utilization',  label: 'Utilization',          perm: 'budget.view' },
          { route: 'reimbursements',      label: 'Reimbursements',       perm: 'reimbursement.create|reimbursement.review|reimbursement.approve' },
          { route: 'settlements',         label: 'Settlements',          perm: 'settlement.record' },
          { route: 'reconciliation',      label: 'Reconciliation',       perm: 'ledger.view' }
        ]
      },
      {
        title: 'Audit & Admin', perm: null, items: [
          { route: 'audit',               label: 'Audit search',         perm: 'audit.view' },
          { route: 'exports',             label: 'Exports',              perm: 'audit.export' },
          { route: 'admin-users',         label: 'Users',                perm: 'auth.manage_users' },
          { route: 'admin-roles',         label: 'Roles & permissions',  perm: 'auth.manage_roles' },
          { route: 'admin-locations',     label: 'Locations & departments', perm: 'auth.manage_users' }
        ]
      }
    ];
    function has(p) {
      if (!p) return true;
      return p.split('|').some(function (one) { return perms.indexOf(one) >= 0; });
    }
    var html = '';
    groups.forEach(function (g) {
      var visible = g.items.filter(function (i) { return has(i.perm); });
      if (!visible.length) return;
      html += '<li class="layui-nav-item layui-nav-itemed"><a href="javascript:;">' + g.title + '</a><dl class="layui-nav-child">';
      visible.forEach(function (i) {
        html += '<dd><a href="#' + i.route + '" data-route="' + i.route + '">' + i.label + '</a></dd>';
      });
      html += '</dl></li>';
    });
    document.getElementById('nav').innerHTML = html;
    element.init();
  }

  function dispatch(route) {
    var root = document.getElementById('page-root');
    if (!route) return;
    // Lazy-load the page module from /static/js/pages/<route>.js
    var s = document.createElement('script');
    s.src = '/static/js/pages/' + route + '.js?_=' + Date.now();
    s.onerror = function () {
      root.innerHTML = '<div class="layui-card"><div class="layui-card-header">' + route + '</div>'
                     + '<div class="layui-card-body muted">Page not implemented yet.</div></div>';
    };
    document.body.appendChild(s);
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest('[data-route]');
    if (!a) return;
    e.preventDefault();
    location.hash = '#' + a.getAttribute('data-route');
    dispatch(a.getAttribute('data-route'));
  });

  document.getElementById('signout').addEventListener('click', function () {
    StudioApi.post('/api/v1/auth/logout', {}).finally(function () {
      location.href = '/pages/login.html';
    });
  });

  // Boot
  StudioApi.get('/api/v1/auth/me').then(function (res) {
    if (res.code !== 0) { location.href = '/pages/login.html'; return; }
    var me = res.data;
    window.StudioMe = me; // expose for page modules
    document.getElementById('who').textContent = me.username + ' · ' + (me.roles || []).join(', ');
    document.getElementById('perms').textContent = (me.permissions || []).join(', ') || '(none)';
    buildNav(me.permissions || []);
    if (location.hash) {
      dispatch(location.hash.slice(1));
    } else {
      // MEDIUM fix audit-2 #7: pick a role-tailored landing dashboard
      // instead of the generic shell. Resolution order:
      //   global admin → dashboard-admin   (org-wide tiles)
      //   finance      → dashboard-finance (budget util + commitments)
      //   operations   → dashboard-operations (review queue)
      //   front desk   → dashboard-frontdesk  (today's attendance)
      //   coach        → dashboard-coach   (next 7 days schedule)
      //   fallback     → dashboard-default
      var caps = me.capabilities || {};
      var route = 'dashboard-default';
      if (caps.is_global || (me.roles || []).indexOf('Administrator') >= 0) route = 'dashboard-admin';
      else if ((me.roles || []).indexOf('Finance') >= 0)    route = 'dashboard-finance';
      else if ((me.roles || []).indexOf('Operations') >= 0) route = 'dashboard-operations';
      else if ((me.roles || []).indexOf('FrontDesk') >= 0)  route = 'dashboard-frontdesk';
      else if ((me.roles || []).indexOf('Coach') >= 0)      route = 'dashboard-coach';
      dispatch(route);
    }
  }).catch(function () {
    location.href = '/pages/login.html';
  });
});
