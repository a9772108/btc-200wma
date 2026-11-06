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

    var state = { mode: 'weekly', range: 'all', since: 0, log: true };

    // The initial view can come from the query string, so a specific window is
    // linkable: ?range=year or ?mode=daily.
    (function readInitialState() {
        var params = new URLSearchParams(window.location.search);
        var mode = params.get('mode');
        var range = params.get('range');

        if (mode === 'daily' || mode === 'weekly') state.mode = mode;
        if (['week', 'month', 'year', 'all'].indexOf(range) !== -1) state.range = range;
    })();
    var chart = null;
    var requestId = 0;
    var lastData = null;

    var statusEl = document.getElementById('status');
    var subEl = document.getElementById('sub');
    var statsEl = document.getElementById('stats');
    var freshEl = document.getElementById('freshness');

    // ------------------------------------------------------------ formatting
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

        // Span decides both the tick format and how much the tooltip repeats.
        var firstDate = new Date(labels[0] + 'T00:00:00Z');
        var lastDate = new Date(labels[labels.length - 1] + 'T00:00:00Z');
        var spanDays = Math.round((lastDate - firstDate) / 86400000) + 1;

        // On a log axis that spans several decades, only labelling powers of ten
        // keeps it readable. Over a short window the values are all within one
        // decade, so there are almost no powers of ten to label — there we label
        // every tick instead.
        var lo = Infinity, hi = -Infinity;
        for (var i = 0; i < price.length; i++) {
            if (price[i] < lo) lo = price[i];
            if (price[i] > hi) hi = price[i];
            if (ma[i] !== null && ma[i] < lo) lo = ma[i];
            if (ma[i] !== null && ma[i] > hi) hi = ma[i];
        }
        var orderSpan = (lo > 0 && hi > 0) ? (hi / lo) : 1;
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
        setStatus(labels.length.toLocaleString('en-US') + ' days plotted');

        var cfg = {
            type: 'line',
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
        chart = new Chart(document.getElementById('chart'), cfg);
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
    }

    wire();
    load();
})();
