/*
 * Bikram Sambat conversion for the browser — mirrors app/nepali_date.php
 * (same canonical BS 2000-2090 table, digit = month days - 28).
 * Uses a single same-sized date control in either AD or BS mode.
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
    var picker = document.createElement('input');
    picker.type = 'text';
    picker.className = input.className;
    picker.style.cssText = input.style.cssText;
    picker.placeholder = 'YYYY-MM-DD (BS)';
    picker.inputMode = 'numeric';
    picker.pattern = '[0-9]{4}-[0-9]{2}-[0-9]{2}';
    picker.required = input.required;
    picker.disabled = input.disabled;
    picker.readOnly = input.readOnly;
    picker.setAttribute('aria-label', (input.getAttribute('aria-label') || 'Date') + ' (BS, YYYY-MM-DD)');
    var minimum = input.min, maximum = input.max;
    function sync() {
      var bs = input.value ? adToBs(input.value) : null;
      picker.value = bs ? bs.y + '-' + pad(bs.m) + '-' + pad(bs.d) : '';
      picker.setCustomValidity(input.value && !bs ? 'Date is outside the supported BS calendar.' : '');
    }
    picker.addEventListener('input', function () {
      var parts = picker.value.split('-').map(Number);
      var valid = /^\d{4}-\d{2}-\d{2}$/.test(picker.value) && parts[1] >= 1 && parts[1] <= 12 &&
        parts[2] >= 1 && parts[2] <= monthDays(parts[0], parts[1]);
      var ad = valid ? bsToAd(parts[0], parts[1], parts[2]) : null;
      var message = picker.value && !ad ? 'Enter a valid BS date (YYYY-MM-DD).' : '';
      if (ad && ((minimum && ad < minimum) || (maximum && ad > maximum))) {
        message = 'Date must be within the permitted date range.';
      }
      picker.setCustomValidity(message);
      input.value = ad || '';
      // Do not replace a partially typed date with a converted value.
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    picker.addEventListener('change', function () {
      if (picker.validity.valid) { input.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    input.addEventListener('change', sync);
    input.type = 'hidden';
    input.required = false;
    var wrap = document.createElement('span');
    wrap.className = 'bs-date-control ' + input.className;
    input.insertAdjacentElement('afterend', wrap);
    wrap.appendChild(picker);
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'bs-date-open';
    toggle.textContent = '▦';
    toggle.setAttribute('aria-label', 'Open Nepali calendar');
    toggle.setAttribute('aria-haspopup', 'dialog');
    toggle.disabled = input.disabled || input.readOnly;
    wrap.appendChild(toggle);
    var calendar = document.createElement('div');
    calendar.className = 'bs-date-calendar';
    calendar.setAttribute('popover', 'auto');
    calendar.setAttribute('role', 'dialog');
    calendar.setAttribute('aria-label', 'Select Nepali date');
    wrap.appendChild(calendar);
    var shownYear, shownMonth;
    function closeCalendar() { calendar.hidePopover(); toggle.focus(); }
    function render() {
      var years = '', months = '', days = '';
      for (var y = FIRST_YEAR; y < FIRST_YEAR + DATA.length; y++) {
        years += '<option value="' + y + '"' + (y === shownYear ? ' selected' : '') + '>' + y + '</option>';
      }
      MONTHS.forEach(function (name, i) { months += '<option value="' + (i + 1) + '"' + (i + 1 === shownMonth ? ' selected' : '') + '>' + name + '</option>'; });
      var first = bsToAd(shownYear, shownMonth, 1);
      var offset = new Date(first + 'T00:00:00Z').getUTCDay();
      for (var blank = 0; blank < offset; blank++) { days += '<span></span>'; }
      for (var d = 1; d <= monthDays(shownYear, shownMonth); d++) {
        var ad = bsToAd(shownYear, shownMonth, d);
        var disabled = (minimum && ad < minimum) || (maximum && ad > maximum);
        days += '<button type="button" data-day="' + d + '" aria-label="' + d + ' ' + MONTHS[shownMonth - 1] + ' ' + shownYear + ' BS"' + (disabled ? ' disabled' : '') + (ad === input.value ? ' aria-current="date"' : '') + '>' + d + '</button>';
      }
      calendar.innerHTML = '<div class="bs-date-nav"><button type="button" data-prev aria-label="Previous month">‹</button><select data-month aria-label="Nepali month">' + months + '</select><select data-year aria-label="Nepali year">' + years + '</select><button type="button" data-next aria-label="Next month">›</button></div><div class="bs-date-days">' + ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(function (day) { return '<strong>' + day + '</strong>'; }).join('') + days + '</div><div class="bs-date-footer"><button type="button" data-clear>Clear</button><button type="button" data-close>Close</button></div>';
    }
    toggle.addEventListener('click', function () {
      if (calendar.matches(':popover-open')) { closeCalendar(); return; }
      var seed = adToBs(input.value || minimum || new Date().toISOString().slice(0, 10)) || {y:FIRST_YEAR, m:1};
      shownYear = seed.y; shownMonth = seed.m;
      render();
      calendar.showPopover();
      var rect = wrap.getBoundingClientRect();
      calendar.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - calendar.offsetWidth - 8)) + 'px';
      calendar.style.top = Math.max(8, Math.min(rect.bottom + 4, window.innerHeight - calendar.offsetHeight - 8)) + 'px';
      (calendar.querySelector('[aria-current="date"]') || calendar.querySelector('[data-day]:not(:disabled)'))?.focus();
    });
    calendar.addEventListener('change', function (event) {
      var target = event.target.hasAttribute('data-month') ? '[data-month]' : '[data-year]';
      if (event.target.matches('[data-month]')) { shownMonth = Number(event.target.value); }
      if (event.target.matches('[data-year]')) { shownYear = Number(event.target.value); }
      render();
      calendar.querySelector(target).focus();
    });
    calendar.addEventListener('keydown', function (event) {
      var day = Number(event.target.dataset.day);
      var delta = {ArrowLeft:-1, ArrowRight:1, ArrowUp:-7, ArrowDown:7}[event.key];
      if (day && delta) {
        event.preventDefault();
        calendar.querySelector('[data-day="' + (day + delta) + '"]:not(:disabled)')?.focus();
      }
    });
    calendar.addEventListener('click', function (event) {
      var button = event.target.closest('button');
      if (!button) { return; }
      if (button.hasAttribute('data-day') || button.hasAttribute('data-clear')) {
        input.value = button.hasAttribute('data-clear') ? '' : bsToAd(shownYear, shownMonth, Number(button.dataset.day));
        input.dispatchEvent(new Event('input', {bubbles:true}));
        input.dispatchEvent(new Event('change', {bubbles:true}));
        closeCalendar();
      } else if (button.hasAttribute('data-close')) { closeCalendar(); }
      else {
        var next = (shownYear - FIRST_YEAR) * 12 + shownMonth - 1 + (button.hasAttribute('data-prev') ? -1 : 1);
        if (next >= 0 && next < DATA.length * 12) { shownYear = FIRST_YEAR + Math.floor(next / 12); shownMonth = next % 12 + 1; render(); }
      }
    });
    if (input.form) { input.form.addEventListener('reset', function () { setTimeout(sync, 0); }); }
    sync();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-calendar-toggle]').forEach(function (toggle) {
      var panel = toggle.parentElement.querySelector('[data-bs-calendar-panel]');
      var today = adToBs(toggle.getAttribute('data-ad-today'));
      if (!panel || !today) { return; }
      var shownYear = today.y;
      var shownMonth = today.m;

      function renderCalendar() {
        var firstAd = bsToAd(shownYear, shownMonth, 1);
        var startDay = firstAd ? new Date(firstAd + 'T00:00:00Z').getUTCDay() : 0;
        var cells = '';
        for (var blank = 0; blank < startDay; blank++) { cells += '<span class="is-empty"></span>'; }
        for (var day = 1; day <= monthDays(shownYear, shownMonth); day++) {
          var current = shownYear === today.y && shownMonth === today.m && day === today.d;
          cells += '<span' + (current ? ' class="is-today"' : '') + '>' + day + '</span>';
        }
        panel.innerHTML = '<div class="bs-calendar-head"><button type="button" data-bs-prev aria-label="Previous Nepali month">‹</button><strong>' + MONTHS[shownMonth - 1] + ' ' + shownYear + '</strong><button type="button" data-bs-next aria-label="Next Nepali month">›</button></div>' +
          '<div class="bs-calendar-week"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>' +
          '<div class="bs-calendar-grid">' + cells + '</div>' +
          '<button type="button" class="bs-calendar-today" data-bs-today>Today · ' + today.str + '</button>';
      }
      function shiftMonth(delta) {
        shownMonth += delta;
        if (shownMonth < 1) { shownMonth = 12; shownYear--; }
        if (shownMonth > 12) { shownMonth = 1; shownYear++; }
        if (shownYear < FIRST_YEAR) { shownYear = FIRST_YEAR; shownMonth = 1; }
        if (shownYear >= FIRST_YEAR + DATA.length) { shownYear = FIRST_YEAR + DATA.length - 1; shownMonth = 12; }
        renderCalendar();
      }
      toggle.addEventListener('click', function () {
        var open = panel.hasAttribute('hidden');
        panel.toggleAttribute('hidden', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { renderCalendar(); }
      });
      panel.addEventListener('click', function (event) {
        if (event.target.closest('[data-bs-prev]')) { shiftMonth(-1); }
        if (event.target.closest('[data-bs-next]')) { shiftMonth(1); }
        if (event.target.closest('[data-bs-today]')) { shownYear = today.y; shownMonth = today.m; renderCalendar(); }
      });
      document.addEventListener('click', function (event) {
        if (!toggle.parentElement.contains(event.target)) { panel.setAttribute('hidden', ''); toggle.setAttribute('aria-expanded', 'false'); }
      });
    });
    var mode = document.body.getAttribute('data-date-mode') || 'both';
    // AD and Both use one AD control; no secondary converted-date line.
    if (mode !== 'bs') { return; }
    document.querySelectorAll('input[type="date"]').forEach(buildBsPicker);
  });
})();
