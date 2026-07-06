/*
 * MBW Charts — tiny dependency-free canvas charts for the MB World portal.
 * Self-contained (no CDN) so it works on cPanel hosting and behind strict CSP.
 *
 * Usage:
 *   MBWCharts.barLine(canvasEl, {
 *     labels: ['Apr', ...],
 *     bars: [{ label: 'Income', color: 'green', values: [...] },
 *            { label: 'Expense', color: 'red',  values: [...] }],
 *     line: { label: 'Net Profit', color: 'primary', values: [...] },
 *     format: v => 'Rs. ' + v.toLocaleString(),
 *   });
 *   MBWCharts.donut(canvasEl, { segments: [{ label, value, color }], thickness: 0.38 });
 *
 * Colors are design-token names resolved from CSS custom properties at draw
 * time (--mbw-green, --mbw-red, --mbw-primary, --mbw-amber, --mbw-purple,
 * --mbw-muted, --mbw-border, --mbw-card, --mbw-heading), so charts follow the
 * active light/dark theme. Charts redraw on resize and on the `mbw:theme`
 * event dispatched by the theme toggle.
 */
(function () {
  'use strict';

  var FALLBACK = {
    green: '#16a34a', red: '#e5484d', primary: '#2563eb', amber: '#f59e0b',
    purple: '#8b5cf6', muted: '#6b7a90', border: '#e3e9f2', card: '#ffffff',
    heading: '#16263e'
  };

  function cssColor(name) {
    var v = getComputedStyle(document.body).getPropertyValue('--mbw-' + name).trim();
    return v || FALLBACK[name] || name;
  }

  function resolveColor(c) {
    return FALLBACK.hasOwnProperty(c) ? cssColor(c) : c;
  }

  function setupCanvas(canvas) {
    var dpr = window.devicePixelRatio || 1;
    var rect = canvas.getBoundingClientRect();
    var w = Math.max(rect.width, 40);
    var h = Math.max(rect.height, 40);
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    var ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx: ctx, w: w, h: h };
  }

  function niceScale(min, max, ticks) {
    if (min === max) { max = min + 1; }
    var span = max - min;
    var step = Math.pow(10, Math.floor(Math.log(span / ticks) / Math.LN10));
    var err = (span / ticks) / step;
    if (err >= 7.5) { step *= 10; } else if (err >= 3.5) { step *= 5; } else if (err >= 1.5) { step *= 2; }
    return {
      min: Math.floor(min / step) * step,
      max: Math.ceil(max / step) * step,
      step: step
    };
  }

  function shortNum(v) {
    var a = Math.abs(v);
    if (a >= 1e7) { return (v / 1e6).toFixed(0) + 'M'; }
    if (a >= 1e6) { return (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M'; }
    if (a >= 1e3) { return (v / 1e3).toFixed(0) + 'K'; }
    return String(v);
  }

  function makeTooltip() {
    var tip = document.createElement('div');
    tip.className = 'mbw-chart-tooltip';
    tip.setAttribute('role', 'status');
    tip.style.display = 'none';
    document.body.appendChild(tip);
    return tip;
  }

  var charts = [];

  function register(canvas, draw) {
    charts.push({ canvas: canvas, draw: draw });
    draw();
    if (window.ResizeObserver) {
      var ro = new ResizeObserver(function () { draw(); });
      ro.observe(canvas.parentElement || canvas);
    }
  }

  window.addEventListener('mbw:theme', function () {
    charts.forEach(function (c) { c.draw(); });
  });
  window.addEventListener('resize', function () {
    charts.forEach(function (c) { c.draw(); });
  });

  function barLine(canvas, cfg) {
    var tip = makeTooltip();
    var geom = null;

    function draw() {
      var s = setupCanvas(canvas);
      var ctx = s.ctx;
      var padL = 46, padR = 10, padT = 12, padB = 26;
      var plotW = s.w - padL - padR;
      var plotH = s.h - padT - padB;
      var labels = cfg.labels || [];
      var barSeries = cfg.bars || [];
      var line = cfg.line || null;

      var all = [];
      barSeries.forEach(function (b) { all = all.concat(b.values); });
      if (line) { all = all.concat(line.values); }
      var minV = Math.min(0, Math.min.apply(null, all));
      var maxV = Math.max.apply(null, all.concat([1]));
      var scale = niceScale(minV, maxV, 4);

      function y(v) {
        return padT + plotH - ((v - scale.min) / (scale.max - scale.min)) * plotH;
      }

      ctx.clearRect(0, 0, s.w, s.h);
      ctx.font = '11px Inter, "Segoe UI", system-ui, sans-serif';
      ctx.textAlign = 'right';
      ctx.textBaseline = 'middle';

      for (var t = scale.min; t <= scale.max + scale.step / 2; t += scale.step) {
        var ty = y(t);
        ctx.strokeStyle = cssColor('border');
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(padL, ty);
        ctx.lineTo(s.w - padR, ty);
        ctx.stroke();
        ctx.fillStyle = cssColor('muted');
        ctx.fillText(shortNum(t), padL - 8, ty);
      }

      var n = labels.length || 1;
      var slot = plotW / n;
      var groupW = Math.min(slot * 0.55, 34);
      var barW = barSeries.length ? groupW / barSeries.length : groupW;
      geom = { slots: [], padT: padT, plotH: plotH, padL: padL, slot: slot };

      ctx.textAlign = 'center';
      ctx.textBaseline = 'top';
      labels.forEach(function (lb, i) {
        var cx = padL + slot * i + slot / 2;
        ctx.fillStyle = cssColor('muted');
        ctx.fillText(lb, cx, padT + plotH + 8);
        geom.slots.push(cx);
      });

      barSeries.forEach(function (b, si) {
        ctx.fillStyle = resolveColor(b.color);
        b.values.forEach(function (v, i) {
          var cx = padL + slot * i + slot / 2 - groupW / 2 + si * barW;
          var y0 = y(Math.max(0, scale.min));
          var y1 = y(v);
          var top = Math.min(y0, y1);
          var hgt = Math.max(Math.abs(y0 - y1), 1);
          var r = Math.min(3, barW / 2, hgt);
          ctx.beginPath();
          if (ctx.roundRect) {
            ctx.roundRect(cx, top, barW - 2, hgt, [r, r, 0, 0]);
          } else {
            ctx.rect(cx, top, barW - 2, hgt);
          }
          ctx.fill();
        });
      });

      if (line) {
        ctx.strokeStyle = resolveColor(line.color || 'primary');
        ctx.lineWidth = 2;
        ctx.beginPath();
        line.values.forEach(function (v, i) {
          var cx = padL + slot * i + slot / 2;
          if (i === 0) { ctx.moveTo(cx, y(v)); } else { ctx.lineTo(cx, y(v)); }
        });
        ctx.stroke();
        line.values.forEach(function (v, i) {
          var cx = padL + slot * i + slot / 2;
          ctx.beginPath();
          ctx.arc(cx, y(v), 3.5, 0, Math.PI * 2);
          ctx.fillStyle = cssColor('card');
          ctx.fill();
          ctx.lineWidth = 2;
          ctx.strokeStyle = resolveColor(line.color || 'primary');
          ctx.stroke();
        });
      }
    }

    canvas.addEventListener('mousemove', function (ev) {
      if (!geom || !geom.slots.length) { return; }
      var rect = canvas.getBoundingClientRect();
      var x = ev.clientX - rect.left;
      var idx = -1, best = 1e9;
      geom.slots.forEach(function (cx, i) {
        var d = Math.abs(cx - x);
        if (d < best) { best = d; idx = i; }
      });
      if (idx < 0 || best > geom.slot) { tip.style.display = 'none'; return; }
      var fmt = cfg.format || function (v) { return v.toLocaleString(); };
      var rows = '';
      (cfg.bars || []).forEach(function (b) {
        rows += '<div class="mbw-tip-row"><span class="mbw-tip-dot" style="background:' + resolveColor(b.color) + '"></span>' + b.label + '<b>' + fmt(b.values[idx]) + '</b></div>';
      });
      if (cfg.line) {
        rows += '<div class="mbw-tip-row"><span class="mbw-tip-dot" style="background:' + resolveColor(cfg.line.color || 'primary') + '"></span>' + cfg.line.label + '<b>' + fmt(cfg.line.values[idx]) + '</b></div>';
      }
      tip.innerHTML = '<div class="mbw-tip-title">' + (cfg.labels[idx] || '') + '</div>' + rows;
      tip.style.display = 'block';
      var tw = tip.offsetWidth;
      var left = ev.clientX + 14;
      if (left + tw > window.innerWidth - 8) { left = ev.clientX - tw - 14; }
      tip.style.left = (left + window.scrollX) + 'px';
      tip.style.top = (ev.clientY + window.scrollY - 10) + 'px';
    });
    canvas.addEventListener('mouseleave', function () { tip.style.display = 'none'; });

    register(canvas, draw);
  }

  function donut(canvas, cfg) {
    function draw() {
      var s = setupCanvas(canvas);
      var ctx = s.ctx;
      var cx = s.w / 2, cy = s.h / 2;
      var radius = Math.min(s.w, s.h) / 2 - 6;
      var thickness = radius * (cfg.thickness || 0.38);
      var total = 0;
      (cfg.segments || []).forEach(function (seg) { total += Math.max(seg.value, 0); });
      if (total <= 0) { total = 1; }
      ctx.clearRect(0, 0, s.w, s.h);
      var start = -Math.PI / 2;
      (cfg.segments || []).forEach(function (seg) {
        var frac = Math.max(seg.value, 0) / total;
        var end = start + frac * Math.PI * 2;
        ctx.beginPath();
        ctx.arc(cx, cy, radius, start + 0.02, end - 0.02);
        ctx.strokeStyle = resolveColor(seg.color);
        ctx.lineWidth = thickness;
        ctx.lineCap = 'round';
        ctx.stroke();
        start = end;
      });
      if (cfg.centerLabel) {
        ctx.fillStyle = cssColor('heading');
        ctx.font = '700 16px Inter, "Segoe UI", system-ui, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(cfg.centerLabel, cx, cy - 8);
        if (cfg.centerSub) {
          ctx.fillStyle = cssColor('muted');
          ctx.font = '11px Inter, "Segoe UI", system-ui, sans-serif';
          ctx.fillText(cfg.centerSub, cx, cy + 10);
        }
      }
    }
    register(canvas, draw);
  }

  window.MBWCharts = { barLine: barLine, donut: donut };
})();
