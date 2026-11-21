// Small helpers for panel forms (no libraries). Everything works without JS too, except adding rows and the
// customer lookup; amounts shown live are only a preview — the server calculates the real ones.
(function () {
  'use strict';

  function closest(el, sel) { return el && el.closest ? el.closest(sel) : null; }

  // data-confirm="Question?" on a submit button or form
  document.addEventListener('submit', function (ev) {
    var btn = ev.submitter || document.activeElement;
    var msg = (btn && btn.getAttribute && btn.getAttribute('data-confirm')) || ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) ev.preventDefault();
  });

  // form[data-only-changed]: rows (tr[data-row]) whose values equal their orig_* twins are not sent
  // (big lists — e.g. 1 400 products of a shop — would exceed PHP's max_input_vars)
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.hasAttribute || !form.hasAttribute('data-only-changed') || ev.defaultPrevented) return;
    form.querySelectorAll('tr[data-row]').forEach(function (tr) {
      var changed = false;
      tr.querySelectorAll('input:not([type=hidden]), select').forEach(function (el) {
        var orig = tr.querySelector('[name="' + el.name.replace(/\[(\w+)\]$/, '[orig_$1]') + '"]');
        if (orig && orig.value !== el.value) changed = true;
      });
      if (!changed) tr.querySelectorAll('input, select').forEach(function (el) { el.disabled = true; });
    });
  });

  document.addEventListener('click', function (ev) {
    // data-add-row="tbody-id": append a copy of <template id="tbody-id-tpl">
    var add = closest(ev.target, '[data-add-row]');
    if (add) {
      ev.preventDefault();
      var id = add.getAttribute('data-add-row');
      var tpl = document.getElementById(id + '-tpl');
      var body = document.getElementById(id);
      if (tpl && body) {
        body.appendChild(tpl.content.cloneNode(true));
        var first = body.lastElementChild && body.lastElementChild.querySelector('input, select');
        if (first) first.focus();
        recalc();
      }
      return;
    }
    if (closest(ev.target, '[data-add-discount]')) {
      ev.preventDefault();
      addDiscount();
      return;
    }
    var rm = closest(ev.target, '[data-remove-row]');
    if (rm) {
      ev.preventDefault();
      var tr = closest(rm, 'tr');
      if (tr) tr.parentNode.removeChild(tr);
      recalc();
      return;
    }
    var pick = closest(ev.target, '[data-customer]');
    if (pick) {
      ev.preventDefault();
      fillCustomer(JSON.parse(pick.getAttribute('data-customer')));
    }
  });

  // checkbox with data-toggles="#id": the target is hidden while the box is checked
  function syncToggles() {
    document.querySelectorAll('[data-toggles]').forEach(function (cb) {
      var t = document.querySelector(cb.getAttribute('data-toggles'));
      if (t) t.hidden = cb.checked;
    });
  }
  document.addEventListener('change', function (ev) {
    if (ev.target.matches && ev.target.matches('[data-toggles]')) syncToggles();
  });

  // ---------------------------------------------------------------- employee form: permissions of the ticked roles
  // + exceptions → the result per permission (the server computes the same when saving — Core\Roles::effective)
  function syncPerms() {
    var form = document.getElementById('employee-access');
    if (!form) return;
    var given = {};
    form.querySelectorAll('input[name="roles[]"]').forEach(function (cb) {
      if (!cb.checked) return;
      (cb.getAttribute('data-perms') || '').split(',').forEach(function (p) { if (p) given[p] = true; });
    });
    form.querySelectorAll('tr[data-perm]').forEach(function (tr) {
      var p = tr.getAttribute('data-perm'), mode = tr.querySelector('select').value;
      var on = mode === 'allow' || (mode !== 'deny' && !!given[p]);
      tr.querySelector('.from-role').textContent = given[p] ? '✓' : '–';
      var r = tr.querySelector('.result');
      r.textContent = on ? 'áno' : 'nie';
      r.className = 'result ' + (on ? 'yes' : 'no');
    });
  }
  document.addEventListener('change', function (ev) {
    if (ev.target.closest && ev.target.closest('#employee-access')) syncPerms();
  });

  // ---------------------------------------------------------------- invoice lines: live totals
  function num(v) {
    var n = parseFloat(String(v || '').replace(/\s/g, '').replace(',', '.'));
    return isNaN(n) ? 0 : n;
  }
  function money(n) {
    return n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }
  function recalc() {
    var table = document.getElementById('lines-table');
    if (!table) return;
    var vatPayer = table.getAttribute('data-vat-payer') === '1';
    var net = 0, byRate = {};
    table.querySelectorAll('tbody tr').forEach(function (tr) {
      var q = tr.querySelector('[name="line_qty[]"]'), p = tr.querySelector('[name="line_price[]"]');
      if (!q || !p) return;
      var r = tr.querySelector('[name="line_vat[]"]'), c = tr.querySelector('[name="line_cat[]"]');
      var lineNet = Math.round(num(q.value) * num(p.value) * 100) / 100;
      var rate = vatPayer && (!c || c.value === 'S') && r ? num(r.value) : 0;
      net += lineNet;
      byRate[rate] = (byRate[rate] || 0) + lineNet;
      var cell = tr.querySelector('.line-total');
      if (cell) cell.textContent = money(lineNet + Math.round(lineNet * rate) / 100);
    });
    var vat = 0;
    Object.keys(byRate).forEach(function (rate) { vat += Math.round(byRate[rate] * num(rate)) / 100; });
    var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = money(v); };
    set('sum-net', net); set('sum-vat', vat); set('sum-total', net + vat);
  }
  document.addEventListener('input', function (ev) {
    if (closest(ev.target, '#lines-table')) recalc();
  });

  // invoice discount (owner 2026-11-21): negative line(s) — one per VAT rate / category of the lines, so the VAT base of
  // each rate is reduced (a % of each rate's lines; a fixed amount split in the ratio of the rates' bases)
  function addDiscount() {
    var dv = document.getElementById('disc-value'), dm = document.getElementById('disc-mode');
    var body = document.getElementById('line-rows'), tpl = document.getElementById('line-rows-tpl');
    if (!dv || !dm || !body || !tpl) return;
    var val = num(dv.value), pct = dm.value === 'pct';
    if (!(val > 0) || (pct && val > 100)) { dv.focus(); return; }
    var groups = {}, keys = [], total = 0;
    body.querySelectorAll('tr').forEach(function (tr) {
      var q = tr.querySelector('[name="line_qty[]"]'), p = tr.querySelector('[name="line_price[]"]');
      if (!q || !p) return;
      var lineNet = Math.round(num(q.value) * num(p.value) * 100) / 100;
      if (lineNet <= 0) return;   // existing discounts / returns are not discounted again
      var r = tr.querySelector('[name="line_vat[]"]'), c = tr.querySelector('[name="line_cat[]"]');
      var key = (r ? String(num(r.value)) : '0') + '|' + (c ? c.value : 'S');
      if (!(key in groups)) { groups[key] = 0; keys.push(key); }
      groups[key] += lineNet;
      total += lineNet;
    });
    if (total <= 0) { alert('Najprv zadajte položky — zľava sa počíta z nich.'); return; }
    if (!pct && val > total + 0.005) { alert('Zľava je väčšia ako súčet položiek (' + money(total) + ').'); return; }
    var left = Math.round(val * 100) / 100;
    keys.forEach(function (key, i) {
      var amount = pct ? Math.round(groups[key] * val) / 100
        : (i === keys.length - 1 ? left : Math.round(val * groups[key] / total * 100) / 100);
      left = Math.round((left - amount) * 100) / 100;
      if (amount <= 0) return;
      body.appendChild(tpl.content.cloneNode(true));
      var tr = body.lastElementChild, parts = key.split('|');
      var set = function (name, v) { var el = tr.querySelector('[name="' + name + '"]'); if (el) el.value = v; };
      set('line_name[]', 'Zľava' + (pct ? ' ' + String(val).replace('.', ',') + ' %' : '') + (keys.length > 1 ? ' (DPH ' + parts[0].replace('.', ',') + ' %)' : ''));
      set('line_qty[]', '1');
      set('line_unit[]', 'ks');
      set('line_price[]', '-' + amount.toFixed(2).replace('.', ','));
      set('line_vat[]', parts[0].replace('.', ','));
      set('line_cat[]', parts[1]);
    });
    dv.value = '';
    recalc();
  }

  // ---------------------------------------------------------------- customer lookup in the invoice form
  var timer = null;
  document.addEventListener('input', function (ev) {
    if (ev.target.id !== 'customer-search') return;
    var input = ev.target, box = document.getElementById('customer-results');
    clearTimeout(timer);
    timer = setTimeout(function () {
      var q = input.value.trim();
      if (q.length < 2) { box.innerHTML = ''; return; }
      var url = input.getAttribute('data-url');
      fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (list) {
          box.innerHTML = '';
          if (!list.length) { box.textContent = 'Nič sa nenašlo.'; return; }
          list.forEach(function (c) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'pick';
            b.setAttribute('data-customer', JSON.stringify(c));
            b.textContent = c.name + (c.ico ? ' · IČO ' + c.ico : '') + (c.city ? ' · ' + c.city : '');
            box.appendChild(b);
          });
        })
        .catch(function () { box.textContent = 'Vyhľadávanie zlyhalo.'; });
    }, 250);
  });
  function fillCustomer(c) {
    var form = document.getElementById('invoice-form');
    if (!form) return;
    ['type', 'name', 'street', 'city', 'zip', 'country', 'ico', 'dic', 'icdph', 'email', 'phone'].forEach(function (k) {
      var el = form.elements['buyer_' + k];
      if (el) el.value = c[k] == null ? '' : c[k];
    });
    form.elements.customer_id.value = c.id;
    // customer's own due days (empty = company default)
    if (c.due_days !== null && c.due_days !== '' && form.elements.issue_date && form.elements.due_date) {
      var d = new Date(form.elements.issue_date.value + 'T00:00:00');
      if (!isNaN(d)) {
        d.setDate(d.getDate() + parseInt(c.due_days, 10));
        var pad = function (x) { return (x < 10 ? '0' : '') + x; };
        form.elements.due_date.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
      }
    }
    var box = document.getElementById('customer-results');
    if (box) box.innerHTML = '';
    var label = document.getElementById('customer-linked');
    if (label) label.textContent = 'Prepojené s adresárom: ' + c.name;
  }

  // ---------------------------------------------------------------- company data by IČO (register RPO)
  document.addEventListener('click', function (ev) {
    var btn = closest(ev.target, '[data-rpo]');
    if (!btn) return;
    ev.preventDefault();
    var form = closest(btn, 'form'), p = btn.getAttribute('data-rpo');
    var ico = form.elements[p + 'ico'];
    var note = btn.parentNode.querySelector('.rpo-note');
    if (!note) { note = document.createElement('small'); note.className = 'rpo-note'; btn.parentNode.appendChild(note); }
    if (!ico || !ico.value.trim()) { note.textContent = 'Najprv zadajte IČO.'; return; }
    note.textContent = 'Hľadám…';
    fetch(btn.getAttribute('data-url') + '?ico=' + encodeURIComponent(ico.value.trim()), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.error) { note.textContent = d.error; return; }
        ['name', 'street', 'zip', 'city', 'country'].forEach(function (k) {
          var el = form.elements[p + k];
          if (el && d[k]) el.value = d[k];
        });
        if (form.elements.registration && d.registration) form.elements.registration.value = d.registration;
        if (form.elements[p + 'type']) form.elements[p + 'type'].value = 'b2b';
        note.textContent = 'Doplnené. ' + d.source + ' — DIČ a IČ DPH doplňte ručne.';
      })
      .catch(function () { note.textContent = 'Register je nedostupný.'; });
  });

  // ---------------------------------------------------------------- received document (Náklady): known supplier → fill the rest
  document.addEventListener('change', function (ev) {
    var t = ev.target, form = t.form;
    if (!form || !form.hasAttribute('data-expense-form')) return;
    if (t.name === 'supplier_name') {
      var el = document.getElementById('expense-supplier-data'), data = {};
      try { data = JSON.parse(el ? el.textContent : '{}'); } catch (e) { return; }
      var s = data[t.value];
      if (!s) return;
      Object.keys(s).forEach(function (k) {
        var f = form.elements[k];
        if (f && k !== 'supplier_name' && s[k] !== null && s[k] !== '' && (!f.value || f.tagName === 'SELECT')) f.value = s[k];
      });
    }
    if (t.hasAttribute('data-vat-company')) expenseVat(form);
  });
  function expenseVat(form) {
    var sel = form.querySelector('[data-vat-company]'), note = form.querySelector('[data-vat-note]');
    if (!sel || !note) return;
    var payer = sel.options[sel.selectedIndex].getAttribute('data-vat') === '1';
    note.textContent = payer ? 'Len odpočítateľná DPH z dokladu.' : 'Firma nie je platiteľ DPH — DPH sa neodpočítava, celá suma je náklad (uloží sa 0).';
  }

  // ---------------------------------------------------------------- "select all" checkbox: data-check-all="<name of the checkboxes>"
  document.addEventListener('change', function (ev) {
    var name = ev.target.getAttribute && ev.target.getAttribute('data-check-all');
    if (!name || !ev.target.form) return;
    Array.prototype.forEach.call(ev.target.form.querySelectorAll('input[type=checkbox][name="' + name + '"]'), function (c) { c.checked = ev.target.checked; });
  });

  // ---------------------------------------------------------------- order cost rules: show the select of the chosen type
  function ocRow(row) {
    var t = row.querySelector('[data-oc-type]');
    if (!t) return;
    Array.prototype.forEach.call(row.querySelectorAll('[data-oc-for]'), function (el) {
      el.style.display = el.getAttribute('data-oc-for') === t.value ? '' : 'none';
    });
  }
  document.addEventListener('change', function (ev) {
    if (ev.target.hasAttribute && ev.target.hasAttribute('data-oc-type')) ocRow(closest(ev.target, '[data-oc-row]'));
  });

  document.addEventListener('DOMContentLoaded', function () {
    syncToggles(); recalc();
    Array.prototype.forEach.call(document.querySelectorAll('[data-oc-row]'), ocRow);
    var ef = document.querySelector('[data-expense-form]');
    if (ef) expenseVat(ef);
  });
})();
