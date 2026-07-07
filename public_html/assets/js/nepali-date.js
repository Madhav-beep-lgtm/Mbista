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

  window.NepaliDate = { adToBs: adToBs, months: MONTHS };

  document.addEventListener('DOMContentLoaded', function () {
    if (!document.body.classList.contains('admin-layout')) { return; }
    document.querySelectorAll('input[type="date"]').forEach(function (input) {
      var hint = document.createElement('small');
      hint.className = 'bs-date-hint';
      hint.style.cssText = 'display:block;margin-top:2px;font-size:11px;font-weight:600;color:var(--mbw-amber)';
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
