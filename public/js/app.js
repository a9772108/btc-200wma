/*
 * Bitcoin vs its 200-week moving average — chart client.
 *
 * The average arrives as null for the stretch before 200 weeks of history
 * exist, so the line breaks there instead of dropping to zero.
 */
(function () {
    'use strict';

    // Resolves against the path the app is served under, so the same file works
    // at a domain root and in a subdirectory.
    var BASE = (typeof window.BTC_BASE === 'string') ? window.BTC_BASE : '';
    var API = BASE + '/api/series.php';
    var ORANGE = '#f7931a';
    var ORANGE_DEEP = '#b45309';
    var PRICE = '#9aa3af';
    var INK = '#111318';

    var state = { mode: 'weekly', range: 'all', since: 0, log: true, halvings: true };

    // The initial view can come from the query string, so a specific window is
    // linkable: ?range=year or ?mode=daily.
    (function readInitialState() {
        var params = new URLSearchParams(window.location.search);
        var mode = params.get('mode');
        var range = params.get('range');

        if (mode === 'daily' || mode === 'weekly') state.mode = mode;
        if (['week', 'month', 'year', 'all'].indexOf(range) !== -1) state.range = range;
        if (params.get('halvings') === '0') state.halvings = false;
    })();
    var chart = null;
    var requestId = 0;
    var lastData = null;

    var statusEl = document.getElementById('status');
    var subEl = document.getElementById('sub');
    var statsEl = document.getElementById('stats');
    var freshEl = document.getElementById('freshness');

    var HALVING = '#6d5f9e';        // muted violet: clearly not price data

    /**
     * Vertical markers for the halvings.
     *
     * Chart.js has no annotation layer without an extra plugin, and this needs
     * only lines, so it is drawn directly. Past halvings are solid; the next one
     * is dashed because its date is a projection, not a fact.
     */
    var halvingLines = {
        id: 'halvingLines',
        afterDatasetsDraw: function (c) {
            var marks = c.$halvings;
            if (!marks || !marks.length) return;

            var x = c.scales.x;
            var area = c.chartArea;
            var labels = c.data.labels;
            var ctx = c.ctx;

            // Only label when there is room, so the text never overlaps.
            var lastLabelRight = -Infinity;

            marks.forEach(function (mark) {
                var idx = indexForDate(labels, mark.date);
                if (idx === null) return;

                var px = x.getPixelForValue(idx);
                if (px < area.left || px > area.right) return;

                ctx.save();
                ctx.beginPath();
                ctx.setLineDash(mark.estimated ? [5, 4] : []);
                ctx.strokeStyle = HALVING;
                ctx.globalAlpha = mark.estimated ? 0.75 : 0.55;
                ctx.lineWidth = mark.estimated ? 1.5 : 1.2;
                ctx.moveTo(px, area.top);
                ctx.lineTo(px, area.bottom);
                ctx.stroke();
                ctx.restore();

                var text = mark.estimated
                    ? 'next \u2248 ' + mark.date.slice(0, 4)
                    : mark.date.slice(0, 4);

                ctx.save();
                ctx.font = '600 10px -apple-system, Segoe UI, Roboto, sans-serif';
                var w = ctx.measureText(text).width;

                // Prefer the label to the right of the line, but flip it to the
                // left when it would run off the plot area (the next halving sits
                // at the far right edge by definition).
                var fitsRight = px + 4 + w <= area.right - 2;
                var labelX = fitsRight ? px + 4 : px - 4 - w;
                var leftEdge = labelX;
                var rightEdge = labelX + w;

                if (leftEdge > lastLabelRight + 8) {
                    ctx.fillStyle = HALVING;
                    ctx.textBaseline = 'top';
                    ctx.fillText(text, labelX, area.top + 3);
                    lastLabelRight = rightEdge;
                }
                ctx.restore();
            });
        }
    };

    /**
     * Index of the first label on or after $date, or null when the date falls
     * outside the plotted range. Both ends matter: clamping an earlier date to
     * index 0 drew a stray halving line at the left edge of a one-week view.
     */
    function indexForDate(labels, date) {
        var lo = 0, hi = labels.length - 1;
        if (!labels.length) return null;
        if (date > labels[hi]) return null;    // beyond the plotted range
        if (date < labels[0]) return null;     // before the plotted range
        if (date === labels[0]) return 0;

        while (lo < hi) {
            var mid = (lo + hi) >> 1;
            if (labels[mid] < date) lo = mid + 1;
            else hi = mid;
        }

        return lo;
    }

    /**
     * Extend the series into the future so the projected next halving has a
     * place to sit. Without this the marker falls past the last price and is
     * simply not drawn, which is the opposite of what the chart is for.
     *
     * The extension is capped at half the visible span: on a one-week view,
     * padding 18 months of empty axis would squash the week to a sliver. When
     * the cap applies the halving stays out of view, and the status line says
     * so rather than leaving it silently missing.
     */
    function padToHalving(labels, price, ma, halving) {
        var result = { labels: labels.slice(), price: price.slice(), ma: ma.slice(), added: 0 };

        if (!halving || !labels.length) return result;

        var lastLabel = labels[labels.length - 1];
        if (halving.date <= lastLabel) return result;

        var lastMs = new Date(lastLabel + 'T00:00:00Z').getTime();
        var targetMs = new Date(halving.date + 'T00:00:00Z').getTime();
        var gapDays = Math.round((targetMs - lastMs) / 86400000);

        var spanDays = Math.round(
            (lastMs - new Date(labels[0] + 'T00:00:00Z').getTime()) / 86400000
        ) + 1;

        if (gapDays > spanDays * 0.5) return result;   // too distorting, leave it out

        for (var i = 1; i <= gapDays; i++) {
            var d = new Date(lastMs + i * 86400000).toISOString().slice(0, 10);
            result.labels.push(d);
            result.price.push(null);
            result.ma.push(null);
        }
        result.added = gapDays;

        return result;
    }
    function money(v) {
        if (v === null || v === undefined || isNaN(v)) return '—';
        if (v >= 1000) return '$' + v.toLocaleString('en-US', { maximumFractionDigits: 0 });
        if (v >= 1) return '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return '$' + v.toLocaleString('en-US', { maximumFractionDigits: 4 });
    }

    function shortDate(iso) {
        // "2026-11-03" -> "3 Nov 2026"
        var d = new Date(iso + 'T00:00:00Z');
        return d.getUTCDate() + ' ' +
            ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
             'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][d.getUTCMonth()] + ' ' +
            d.getUTCFullYear();
    }

    function setStatus(text, isError) {
        statusEl.textContent = text || '';
        statusEl.classList.toggle('error', !!isError);
    }

    // ---------------------------------------------------------------- data
    function buildUrl() {
        var q = ['mode=' + encodeURIComponent(state.mode)];

        // A relative range and an absolute year are the same kind of choice:
        // whichever was clicked last wins.
        if (state.range !== 'all') {
            q.push('range=' + encodeURIComponent(state.range));
        } else if (state.since > 0) {
            q.push('since=' + state.since);
        }

        return API + '?' + q.join('&');
    }

    function load() {
        var myRequest = ++requestId;
        setStatus('Loading…');

        fetch(buildUrl(), { headers: { 'Accept': 'application/json' } })
            .then(function (res) {
                return res.json().then(function (body) {
                    return { ok: res.ok, body: body };
                });
            })
            .then(function (result) {
                if (myRequest !== requestId) return;   // a newer request won
                if (!result.ok) throw new Error(result.body.error || 'Request failed');
                draw(result.body);
            })
            .catch(function (err) {
                if (myRequest !== requestId) return;
                setStatus(err.message, true);
            });
    }

    // --------------------------------------------------------------- chart
    function belowMask(labels, price, ma) {
        // Price only where it trades under the average, null elsewhere, so the
        // filled area marks exactly the discounts and nothing more.
        return price.map(function (p, i) {
            var m = ma[i];
            return (m !== null && p < m) ? p : null;
        });
    }

    function draw(data) {
        lastData = data;
        var labels = data.labels || [];
        var price = data.price || [];
        var ma = data.ma || [];

        if (!labels.length) {
            setStatus('No points in this range.', true);
            return;
        }

        // The next halving is in the future, so the axis usually has to grow to
        // reach it. Only done when the extension stays proportionate.
        var nextHalving = null;
        (data.halvings || []).forEach(function (h) {
            if (h.estimated) nextHalving = h;
        });

        var padded = state.halvings && nextHalving
            ? padToHalving(labels, price, ma, nextHalving)
            : { labels: labels, price: price, ma: ma, added: 0 };

        labels = padded.labels;
        price = padded.price;
        ma = padded.ma;
        var futureDays = padded.added;

        // Span decides both the tick format and how much the tooltip repeats.
        var firstDate = new Date(labels[0] + 'T00:00:00Z');
        var lastDate = new Date(labels[labels.length - 1] + 'T00:00:00Z');
        var spanDays = Math.round((lastDate - firstDate) / 86400000) + 1;

        // On a log axis that spans several decades, only labelling powers of ten
        // keeps it readable. Over a short window the values are all within one
        // decade, so there are almost no powers of ten to label — there we label
        // every tick instead.
        // Only real numbers count: the padded future is null, and a stray null
        // would drag the minimum to zero and wreck the tick labelling.
        var lo = Infinity, hi = -Infinity;
        function consider(v) {
            if (v === null || v === undefined || !isFinite(v)) return;
            if (v < lo) lo = v;
            if (v > hi) hi = v;
        }
        for (var i = 0; i < price.length; i++) {
            consider(price[i]);
            consider(ma[i]);
        }
        if (!isFinite(lo) || !isFinite(hi) || lo <= 0) {
            lo = 1;
            hi = 1;
        }
        var orderSpan = hi / lo;
        var labelEveryTick = !state.log || orderSpan < 100;

        var stats = data.stats || {};
        var ratio = stats.ratio || 1;
        var relation = ratio >= 1 ? 'above' : 'below';
        subEl.innerHTML =
            '<strong>' + money(stats.price) + '</strong> today vs a <strong>' +
            money(stats.ma) + '</strong> average — ' +
            (Math.abs(ratio - 1) * 100).toFixed(0) + '% ' + relation +
            ' <span class="muted">(data to ' + shortDate(data.to) + ')</span>';

        renderStats(stats, data);
        var plotted = labels.length - futureDays;
        var statusText = plotted.toLocaleString('en-US') + ' days plotted';

        if (futureDays > 0) {
            statusText += ' · axis extended to the projected next halving';
        } else if (state.halvings && nextHalving) {
            statusText += ' · next halving ' + shortDate(nextHalving.date) + ' is out of range';
        }

        setStatus(statusText);

        var cfg = {
            type: 'line',
            plugins: [halvingLines],
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Price below the average',
                        data: belowMask(labels, price, ma),
                        borderWidth: 0,
                        pointRadius: 0,
                        fill: '+1',              // fill down to the average line
                        backgroundColor: 'rgba(247,147,26,0.38)',
                        spanGaps: false,
                        order: 3                 // drawn behind the strokes
                    },
                    {
                        label: '200-week average',
                        data: ma,
                        borderColor: ORANGE,
                        borderWidth: 2.4,
                        pointRadius: 0,
                        fill: false,
                        spanGaps: false,
                        tension: 0,
                        order: 1
                    },
                    {
                        label: 'BTC price',
                        data: price,
                        borderColor: PRICE,
                        borderWidth: 1.15,
                        pointRadius: 0,
                        fill: false,
                        tension: 0,
                        order: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                layout: { padding: { top: 8, right: 8 } },
                scales: {
                    x: {
                        type: 'category',
                        grid: { display: false },
                        border: { color: '#e5e7eb' },
                        ticks: {
                            color: '#6b7280',
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 9,
                            callback: function (value, index) {
                                var iso = labels[index];
                                if (!iso) return '';

                                // Over a wide span, label years. Over a short one
                                // the year repeats, so label the date instead.
                                if (spanDays > 400) return iso.slice(0, 4);

                                var d = new Date(iso + 'T00:00:00Z');
                                var months = ['Jan','Feb','Mar','Apr','May','Jun',
                                              'Jul','Aug','Sep','Oct','Nov','Dec'];
                                return d.getUTCDate() + ' ' + months[d.getUTCMonth()];
                            }
                        }
                    },
                    y: {
                        type: state.log ? 'logarithmic' : 'linear',
                        grid: { color: '#eef0f3' },
                        border: { display: false },
                        ticks: {
                            color: '#6b7280',
                            // A log axis generates 1..9 x 10^n minor ticks, which
                            // pile up at the low end of a narrow range. Cap the
                            // count whenever every tick is labelled.
                            maxTicksLimit: labelEveryTick ? 6 : 10,
                            callback: function (value) {
                                if (labelEveryTick) return money(value);

                                // Log axis over many decades: powers of ten only.
                                var exp = Math.log10(value);
                                if (Math.abs(exp - Math.round(exp)) > 1e-9) return '';
                                return money(value);
                            }
                        }
                    }
                },
                plugins: {
                    // Halving markers are drawn by the custom plugin below, so
                    // the built-in plugin list keeps only legend and tooltip.
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: {
                            boxWidth: 14,
                            boxHeight: 3,
                            usePointStyle: false,
                            color: INK,
                            filter: function (item) {
                                return item.text !== 'Price below the average';
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                return items.length ? shortDate(labels[items[0].dataIndex]) : '';
                            },
                            label: function (item) {
                                return item.dataset.label + ': ' + money(item.parsed.y);
                            }
                        }
                    }
                }
            }
        };

        if (chart) {
            chart.destroy();
        }

        // The plugin reads these off the chart instance rather than closing over
        // them, so a redraw picks up new data without re-registering.
        var halvings = state.halvings ? (data.halvings || []) : [];

        var canvas = document.getElementById('chart');
        chart = new Chart(canvas, cfg);
        chart.$halvings = halvings;
        chart.update('none');
    }

    function renderStats(stats, data) {
        var ratio = stats.ratio || 0;
        var premium = (ratio - 1) * 100;
        var sign = premium >= 0 ? '+' : '';

        statsEl.innerHTML =
            stat('BTC price', money(stats.price)) +
            stat('200-week average', money(stats.ma), 'ma') +
            stat('Price vs average', sign + premium.toFixed(1) + '%') +
            stat('Days below average', (stats.below_pct || 0).toFixed(1) + '%');

        freshEl.textContent = 'Latest stored day: ' + shortDate(data.to) + '.';
    }

    function stat(label, value, cls) {
        return '<div class="stat"><dt>' + label + '</dt>' +
               '<dd' + (cls ? ' class="' + cls + '"' : '') + '>' + value + '</dd></div>';
    }

    // ------------------------------------------------------------- controls
    function wire() {
        // Reflect any state supplied by the query string on the buttons before
        // the first paint, so the selected option matches what was requested.
        document.querySelectorAll('[data-mode]').forEach(function (b) {
            b.classList.toggle('is-active', b.dataset.mode === state.mode);
        });
        document.querySelectorAll('[data-range]').forEach(function (b) {
            b.classList.toggle('is-active', b.dataset.range === state.range);
        });

        var halvingBox = document.getElementById('halvings');
        if (halvingBox) halvingBox.checked = state.halvings;

        document.querySelectorAll('[data-mode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-mode]').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                state.mode = btn.dataset.mode;
                load();
            });
        });

        // Range buttons: relative windows (1W/1M/1Y), absolute years, and All.
        // All of them are one mutually-exclusive choice, so activating any one
        // clears the others.
        function activateRange(btn) {
            document.querySelectorAll('[data-range], [data-since]').forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
            });
        }

        document.querySelectorAll('[data-range]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateRange(btn);
                state.range = btn.dataset.range || 'all';
                state.since = 0;
                load();
            });
        });

        document.querySelectorAll('[data-since]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activateRange(btn);
                state.since = parseInt(btn.dataset.since, 10) || 0;
                state.range = 'all';
                load();
            });
        });

        var logToggle = document.getElementById('log');
        if (logToggle) {
            logToggle.addEventListener('change', function () {
                state.log = logToggle.checked;
                // Tick labelling depends on the axis type, so re-render from the
                // response we already have rather than only flipping the scale.
                if (lastData) {
                    draw(lastData);
                } else {
                    load();
                }
            });
        }

        var halvingToggle = document.getElementById('halvings');
        if (halvingToggle) {
            halvingToggle.addEventListener('change', function () {
                state.halvings = halvingToggle.checked;
                if (lastData) {
                    draw(lastData);
                } else {
                    load();
                }
            });
        }
    }

    wire();
    load();
})();
