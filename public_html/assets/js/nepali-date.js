/*
 * Bikram Sambat conversion for the browser — mirrors app/nepali_date.php
 * (same canonical BS 2000-2090 table, digit = month days - 28).
 * Adds a live BS hint under every date input on portal pages.
 */
(function () {
  'use strict';

  var EPOCH = Date.UTC(1943, 3, 14); // BS 2000-01-01
  var FIRST_YEAR = 2000;
  var MONTHS = ['Baishakh', 'Jestha', 'Ashadh', 'Shrawan', 'Bhadra', 'Ashwin', 'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'];
  var DATA = ['243432221213', '334333212122', '334432212122', '343432221123', '243432221213',
    '334333212122', '334432212122', '343432221123', '333433122113', '334333212122',
    '334432212122', '343432221123', '333433122122', '334333212122', '334432212122',
    '343432221123', '333433122122', '334333212122', '343432212122', '343432221213',
    '333433212122', '334333212122', '343432221122', '343432221213', '333433212122',
    '334333212122', '343432221123', '243432221213', '334333212122', '334342212122',
    '343432221123', '243432221213', '334333212122', '334432212122', '343432221123',
    '243433122113', '334333212122', '334432212122', '343432221123', '333433122122',
    '334333212122', '334432212122', '343432221123', '333433122122', '334333212122',
    '343432212122', '343432221123', '333433212122', '334333212122', '343432221122',
    '343432221213', '333433212122', '334333212122', '343432221122', '343432221213',
    '334333212122', '334342212122', '343432221123', '243432221213', '334333212122',
    '334432212122', '343432221123', '243433121213', '334333212122', '334432212122',
    '343432221123', '333433122113', '334333212122', '334432212122', '343432221123',
    '333433122122', '334333212122', '343432212122', '343432221123', '333433212122',
    '334333212122', '343432221122', '343432221213', '333433212122', '334333212122',
    '343432221122', '334432221222', '243432221222', '334332221222', '334332221222',
    '343423221222', '243432221222', '334333221222', '234423221222', '243432221222',
    '243432221222'];

  function monthDays(year, month) {
    var row = DATA[year - FIRST_YEAR];
    return row ? 28 + parseInt(row.charAt(month - 1), 10) : 30;
  }

  function adToBs(dateStr) {
    var parts = (dateStr || '').split('-');
    if (parts.length !== 3) { return null; }
    var days = Math.round((Date.UTC(+parts[0], +parts[1] - 1, +parts[2]) - EPOCH) / 86400000);
    if (days < 0) { return null; }
    var year = FIRST_YEAR;
    while (year - FIRST_YEAR < DATA.length) {
      var total = 0;
      for (var m = 1; m <= 12; m++) { total += monthDays(year, m); }
      if (days < total) { break; }
      days -= total;
      year++;
    }
    if (year - FIRST_YEAR >= DATA.length) { return null; }
    var month = 1;
    while (days >= monthDays(year, month)) { days -= monthDays(year, month); month++; }
    return { y: year, m: month, d: days + 1, str: (days + 1) + ' ' + MONTHS[month - 1] + ' ' + year };
  }

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  // BS y/m/d -> AD 'Y-m-d' (mirror of PHP bs_to_ad)
  function bsToAd(year, month, day) {
    if (year < FIRST_YEAR || year - FIRST_YEAR >= DATA.length) { return null; }
    var days = 0, y, m;
    for (y = FIRST_YEAR; y < year; y++) { for (m = 1; m <= 12; m++) { days += monthDays(y, m); } }
    for (m = 1; m < month; m++) { days += monthDays(year, m); }
    days += day - 1;
    var d = new Date(EPOCH + days * 86400000);
    return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
  }

  window.NepaliDate = { adToBs: adToBs, bsToAd: bsToAd, months: MONTHS, firstYear: FIRST_YEAR, years: DATA.length, monthDays: monthDays };

  // Build a BS Y/M/D picker bound to a native (AD) date input.
  function buildBsPicker(input) {
    var wrap = document.createElement('span');
    wrap.className = 'bs-picker';
    wrap.style.cssText = 'display:inline-flex;gap:4px';
    var ySel = document.createElement('select');
    var mSel = document.createElement('select');
    var dSel = document.createElement('select');
    [ySel, mSel, dSel].forEach(function (sel) {
      sel.style.cssText = 'min-height:38px;border:1px solid var(--mbw-border,#e3e9f2);border-radius:8px;background:var(--mbw-card,#fff);color:var(--mbw-heading,#16263e);font-size:12.5px;padding:4px 6px';
    });
    var y;
    for (y = FIRST_YEAR + DATA.length - 1; y >= FIRST_YEAR; y--) {
      ySel.insertAdjacentHTML('beforeend', '<option value="' + y + '">' + y + '</option>');
    }
    MONTHS.forEach(function (name, i) {
      mSel.insertAdjacentHTML('beforeend', '<option value="' + (i + 1) + '">' + name + '</option>');
    });

    function fillDays() {
      var dim = monthDays(+ySel.value, +mSel.value);
      var cur = +dSel.value || 1;
      dSel.innerHTML = '';
      for (var d = 1; d <= dim; d++) {
        dSel.insertAdjacentHTML('beforeend', '<option value="' + d + '">' + d + '</option>');
      }
      dSel.value = Math.min(cur, dim);
    }

    function pushToInput() {
      var ad = bsToAd(+ySel.value, +mSel.value, +dSel.value);
      if (ad) {
        input.value = ad;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    // Seed from the input's current AD value, else today.
    var seed = input.value ? adToBs(input.value) : adToBs(new Date().toISOString().slice(0, 10));
    if (seed) { ySel.value = seed.y; mSel.value = seed.m; }
    fillDays();
    if (seed) { dSel.value = seed.d; }
    if (!input.value) { pushToInput(); }

    ySel.addEventListener('change', function () { fillDays(); pushToInput(); });
    mSel.addEventListener('change', function () { fillDays(); pushToInput(); });
    dSel.addEventListener('change', pushToInput);

    wrap.appendChild(ySel);
    wrap.appendChild(mSel);
    wrap.appendChild(dSel);
    input.style.display = 'none';
    input.insertAdjacentElement('afterend', wrap);
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!document.body.classList.contains('admin-layout')) { return; }
    var mode = document.body.getAttribute('data-date-mode') || 'both';
    if (mode === 'ad') { return; }

    document.querySelectorAll('input[type="date"]').forEach(function (input) {
      if (mode === 'bs') {
        buildBsPicker(input);
        return;
      }
      // 'both': keep the native AD input, add a live BS hint underneath.
      var hint = document.createElement('small');
      hint.className = 'bs-date-hint';
      hint.style.cssText = 'display:block;margin-top:3px;font-size:10.5px;font-weight:500;letter-spacing:0.02em;color:var(--mbw-muted,#64748b)';
      input.insertAdjacentElement('afterend', hint);
      var update = function () {
        var bs = input.value ? adToBs(input.value) : null;
        hint.textContent = bs ? bs.str + ' BS' : '';
      };
      input.addEventListener('change', update);
      input.addEventListener('input', update);
      update();
    });
  });
})();
