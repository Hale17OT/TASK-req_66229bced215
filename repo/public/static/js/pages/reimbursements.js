/* Reimbursements page — permission-aware buttons + draft auto-restore. */
layui.use(['table', 'form', 'layer', 'laydate'], function () {
  var table = layui.table, form = layui.form, layer = layui.layer, laydate = layui.laydate;

  // Pull capability map first; render UI based on what the caller can do.
  StudioApi.get('/api/v1/auth/me').then(function (me) {
    var caps = (me.data && me.data.capabilities) || {};
    var rcap = caps.reimbursement || {};
    var canCreate   = !!rcap.create;
    var canSubmit   = !!rcap.submit;
    var canReview   = !!rcap.review;
    var canApprove  = !!rcap.approve;
    var canReject   = !!rcap.reject;
    var canOverride = !!rcap.override;

    document.getElementById('page-root').innerHTML =
      (canCreate
        ? '<div class="layui-card"><div class="layui-card-header">New reimbursement (draft)</div><div class="layui-card-body">'
          + '<form id="rf" class="layui-form" lay-filter="rf">'
          + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Category</label><div class="layui-input-inline"><select name="category_id" id="rf-cat"></select></div></div>'
          + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Amount</label><div class="layui-input-inline"><input class="layui-input" name="amount" placeholder="125.00"/></div></div>'
          + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Merchant</label><div class="layui-input-inline"><input class="layui-input" name="merchant"/></div></div>'
          + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Receipt #</label><div class="layui-input-inline"><input class="layui-input" name="receipt_no"/></div></div>'
          + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Service start</label><div class="layui-input-inline"><input id="ss" class="layui-input" name="service_period_start"/></div></div>'
          + '<div class="layui-form-item layui-inline"><label class="layui-form-label">Service end</label><div class="layui-input-inline"><input id="se" class="layui-input" name="service_period_end"/></div></div>'
          + '<div class="layui-form-item"><label class="layui-form-label">Description</label><div class="layui-input-block"><textarea name="description" class="layui-textarea"></textarea></div></div>'
          + '<div class="layui-form-item"><div class="layui-input-block"><button class="layui-btn" lay-submit lay-filter="rfGo">Create draft</button>'
          + '<button class="layui-btn layui-btn-warm" id="rf-precheck">Pre-check budget</button>'
          + '<span class="muted" id="rf-draft-hint" style="margin-left:12px"></span></div></div>'
          + '</form></div></div>'
        : '')
      + '<div class="layui-card"><div class="layui-card-header">Reimbursements</div><div class="layui-card-body"><table id="r-list"></table></div></div>';

    if (canCreate) {
      laydate.render({elem:'#ss'}); laydate.render({elem:'#se'});
      StudioApi.get('/api/v1/budget/categories').then(function (r) {
        document.getElementById('rf-cat').innerHTML = (r.data || []).map(function (c) { return '<option value="'+c.id+'">'+c.name+'</option>'; }).join('');
        form.render('select');
      });
    }

    // Bind draft persistence to the create form. Token is per-user so two
    // browser tabs by the same user share the same draft.
    var draftToken = 'reimbursement-new-u' + (me.data && me.data.id);
    var draftBinding = canCreate ? StudioApi.Drafts.bindForm(document.getElementById('rf'), draftToken) : null;

    function reload() {
      StudioApi.get('/api/v1/reimbursements?size=100').then(function (res) {
        if (res.code !== 0) return layer.msg(res.message);
        var rows = (res.data && res.data.data) || res.data || [];
        table.render({elem:'#r-list', data:rows, cols:[[
          {field:'reimbursement_no', title:'No', width:200},
          {field:'amount', title:'Amount', width:120, templet: function (d) { return '<span class="money">'+d.amount+'</span>'; }},
          {field:'merchant', title:'Merchant', width:200},
          {field:'category_id', title:'Cat', width:80},
          {field:'status', title:'Status', width:160},
          {field:'submitted_at', title:'Submitted', width:170},
          {fixed:'right', title:'Actions', width:380, templet: function (d) {
            var html = '';
            // Lifecycle buttons gated by BOTH status AND capabilities (HIGH fix #5).
            if ((d.status === 'draft' || d.status === 'needs_revision') && canSubmit) {
              html += '<a class="layui-btn layui-btn-xs" data-rx="submit" data-id="'+d.id+'">Submit</a>';
              html += '<a class="layui-btn layui-btn-xs layui-btn-warm" data-rx="upload" data-id="'+d.id+'">Upload file</a>';
            }
            var inReview = ['submitted','resubmitted','under_review','pending_override_review'].indexOf(d.status) >= 0;
            if (inReview && canApprove) {
              html += '<a class="layui-btn layui-btn-xs layui-btn-normal" data-rx="approve" data-id="'+d.id+'">Approve</a>';
            }
            if (inReview && canReject) {
              html += '<a class="layui-btn layui-btn-xs layui-btn-danger" data-rx="reject" data-id="'+d.id+'">Reject</a>';
            }
            if (inReview && canReview) {
              html += '<a class="layui-btn layui-btn-xs layui-btn-primary" data-rx="needs-revision" data-id="'+d.id+'">Needs rev</a>';
            }
            if (d.status === 'pending_override_review' && canOverride) {
              html += '<a class="layui-btn layui-btn-xs" style="background:#8e44ad" data-rx="override" data-id="'+d.id+'">Override</a>';
            }
            html += '<a class="layui-btn layui-btn-xs layui-btn-primary" data-rx="history" data-id="'+d.id+'">History</a>';
            return html;
          }}
        ]]});
      });
    }

    if (canCreate) {
      form.on('submit(rfGo)', function (data) {
        var f = data.field || {};
        // Client-side pre-submit duplicate probe: give the submitter
        // immediate feedback before they waste the upload round trip.
        // Server still enforces this on /submit — the probe is advisory.
        var merchant  = (f.merchant || '').trim();
        var receiptNo = (f.receipt_no || '').trim();
        var runCreate = function () {
          var idem = draftBinding ? draftBinding.idemKey() : null;
          StudioApi.post('/api/v1/reimbursements', f, idem ? {idempotencyKey: idem} : null).then(function (res) {
            if (res.code !== 0) return layer.alert(res.message);
            if (draftBinding) {
              draftBinding.clear();
              draftBinding.rotateIdemKey();
              document.getElementById('rf').reset();
              form.render();
            }
            layer.msg('draft created');
            reload();
          });
        };
        if (merchant && receiptNo) {
          var qs = [
            'merchant='   + encodeURIComponent(merchant),
            'receipt_no=' + encodeURIComponent(receiptNo),
            'amount='     + encodeURIComponent(f.amount || '0'),
            'service_period_start=' + encodeURIComponent(f.service_period_start || ''),
            'service_period_end='   + encodeURIComponent(f.service_period_end   || ''),
          ].join('&');
          StudioApi.get('/api/v1/reimbursements/duplicate-check?' + qs).then(function (probe) {
            if (probe.code === 0 && probe.data && probe.data.ok === false) {
              layer.confirm(
                'Possible duplicate: ' + (probe.data.message || 'conflict') + '. Create draft anyway?',
                {title: 'Duplicate warning', btn: ['Create anyway', 'Cancel']},
                function (idx) { layer.close(idx); runCreate(); }
              );
            } else {
              runCreate();
            }
          }).catch(function () { runCreate(); });
        } else {
          runCreate();
        }
        return false;
      });
      document.getElementById('rf-precheck').addEventListener('click', function (e) {
        e.preventDefault();
        var f = {
          category_id: document.querySelector('[name="category_id"]').value,
          amount: document.querySelector('[name="amount"]').value,
          service_start: document.querySelector('[name="service_period_start"]').value || new Date().toISOString().slice(0,10)
        };
        var qs = Object.keys(f).map(function (k) { return k + '=' + encodeURIComponent(f[k]); }).join('&');
        StudioApi.get('/api/v1/budget/precheck?'+qs).then(function (res) {
          if (res.code !== 0) return layer.alert(res.message);
          layer.alert(JSON.stringify(res.data, null, 2), {title:'Precheck'});
        });
      });
    }

    // Decision actions go through StudioApi.idempotentAction so a network
    // drop mid-request followed by the user clicking again reuses the same
    // Idempotency-Key — the server's idempotency middleware returns the
    // cached response, the side effects fire exactly once.
    function decision(url, body, okMsg) {
      return StudioApi.idempotentAction('POST', url, body || {}).then(function (r) {
        if (r.code !== 0) return layer.alert(r.message);
        layer.msg(okMsg);
        reload();
      });
    }

    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-rx]'); if (!b) return;
      var act = b.getAttribute('data-rx'), id = b.getAttribute('data-id');
      if (act === 'submit')         decision('/api/v1/reimbursements/'+id+'/submit', {}, 'submitted');
      if (act === 'approve')        layer.prompt({title:'Approval comment (optional)', formType:2, value:''}, function (c, idx) { layer.close(idx); decision('/api/v1/reimbursements/'+id+'/approve', {comment:c}, 'approved'); });
      if (act === 'reject')         layer.prompt({title:'Rejection comment (min 10)', formType:2}, function (c, idx) { layer.close(idx); decision('/api/v1/reimbursements/'+id+'/reject',  {comment:c}, 'rejected'); });
      if (act === 'needs-revision') layer.prompt({title:'Revision request (min 10)', formType:2}, function (c, idx) { layer.close(idx); decision('/api/v1/reimbursements/'+id+'/needs-revision', {comment:c}, 'back to user'); });
      if (act === 'override')       layer.prompt({title:'Override reason (min 15)', formType:2}, function (reason, idx) { layer.close(idx); decision('/api/v1/reimbursements/'+id+'/override', {reason:reason}, 'override recorded'); });
      if (act === 'history')        StudioApi.get('/api/v1/reimbursements/'+id+'/history').then(function (r) { layer.alert('<pre>'+JSON.stringify(r.data, null, 2)+'</pre>', {area:['600px','500px']}); });
      if (act === 'upload') {
        // Client-side attachment pre-validation (audit-5 #3): matches the
        // server limits in config/app.php → studio.attachments. Kept in lock
        // step with AttachmentService; the server still enforces these and
        // sniffs the MIME type so these checks are advisory, not trusted.
        var MAX_BYTES = 10 * 1024 * 1024;
        var MAX_PER   = 5;
        var ALLOWED   = ['application/pdf', 'image/jpeg', 'image/png'];
        var ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
        var inp = document.createElement('input'); inp.type = 'file'; inp.accept = '.pdf,.png,.jpg,.jpeg';
        inp.onchange = function () {
          var file = inp.files && inp.files[0]; if (!file) return;
          var ext = (file.name.split('.').pop() || '').toLowerCase();
          if (ALLOWED_EXT.indexOf(ext) < 0 || (file.type && ALLOWED.indexOf(file.type) < 0)) {
            return layer.alert('Unsupported file type. Allowed: PDF, JPG, PNG.');
          }
          if (file.size <= 0) {
            return layer.alert('Empty file.');
          }
          if (file.size > MAX_BYTES) {
            return layer.alert('File is too large. Maximum is ' + (MAX_BYTES / 1024 / 1024) + ' MB.');
          }
          // Check current attachment count via the reimbursement show endpoint.
          // `attachment_count` is the server-reported number of non-deleted
          // attachments — the JS uses it to block the upload BEFORE the
          // user pays the round-trip cost. Server-side AttachmentService
          // still rejects at the same cap, so this is advisory, not a gate.
          StudioApi.get('/api/v1/reimbursements/' + id).then(function (r) {
            var existing = 0;
            if (r.code === 0 && r.data && typeof r.data.attachment_count === 'number') {
              existing = r.data.attachment_count;
            }
            if (existing >= MAX_PER) {
              return layer.alert('Attachment limit reached (' + MAX_PER + ' per reimbursement).');
            }
            var fd = new FormData(); fd.append('file', file);
            // Multipart upload is not retried automatically because tmp_name
            // streams are single-use. The browser shows the upload progress;
            // retries are user-initiated.
            StudioApi.post('/api/v1/reimbursements/'+id+'/attachments', fd).then(function (r2) {
              if (r2.code !== 0) return layer.alert(r2.message); layer.msg('uploaded');
            });
          });
        };
        inp.click();
      }
    });
    reload();
  });
});
