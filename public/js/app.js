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

    var state = { mode: 'weekly', since: 0, log: true };
    var chart = null;
    var requestId = 0;

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
        if (state.since > 0) q.push('since=' + state.since);
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
        var labels = data.labels || [];
        var price = data.price || [];
        var ma = data.ma || [];

        if (!labels.length) {
            setStatus('No points in this range.', true);
            return;
        }

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
                                // One label per year: showing the year of every
                                // sampled tick reads as a clean timeline.
                                return iso ? iso.slice(0, 4) : '';
                            }
                        }
                    },
                    y: {
                        type: state.log ? 'logarithmic' : 'linear',
                        grid: { color: '#eef0f3' },
                        border: { display: false },
                        ticks: {
                            color: '#6b7280',
                            callback: function (value) {
                                // On a log axis only label powers of ten, so the
                                // years of stacked minor ticks stay readable.
                                if (state.log) {
                                    var exp = Math.log10(value);
                                    if (Math.abs(exp - Math.round(exp)) > 1e-9) return '';
                                }
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
        document.querySelectorAll('[data-mode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-mode]').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                state.mode = btn.dataset.mode;
                load();
            });
        });

        document.querySelectorAll('[data-since]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-since]').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                state.since = parseInt(btn.dataset.since, 10) || 0;
                load();
            });
        });

        var logToggle = document.getElementById('log');
        if (logToggle) {
            logToggle.addEventListener('change', function () {
                state.log = logToggle.checked;
                // Only the axis type changed, so redraw from what we already
                // have rather than re-fetching the series.
                if (chart) {
                    chart.options.scales.y.type = state.log ? 'logarithmic' : 'linear';
                    chart.update('none');
                }
            });
        }
    }

    wire();
    load();
})();
