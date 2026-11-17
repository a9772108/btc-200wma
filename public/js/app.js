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

    var state = { mode: 'weekly', range: 'all', since: 0, log: true, halvings: true, pi: true, dma: true, peaks: true, projection: true };

    // The initial view can come from the query string, so a specific window is
    // linkable: ?range=year or ?mode=daily.
    (function readInitialState() {
        var params = new URLSearchParams(window.location.search);
        var mode = params.get('mode');
        var range = params.get('range');

        if (mode === 'daily' || mode === 'weekly') state.mode = mode;
        if (['day', 'week', 'month', 'year', 'all'].indexOf(range) !== -1) state.range = range;
        if (params.get('halvings') === '0') state.halvings = false;
        if (params.get('pi') === '0') state.pi = false;
        if (params.get('dma') === '0') state.dma = false;
        if (params.get('peaks') === '0') state.peaks = false;
        if (params.get('projection') === '0') state.projection = false;
    })();
    /**
     * Value labels for a one-point window.
     *
     * A 1D view holds a single observation, so there is no line to read and the
     * dots alone do not say what they are worth. Label them in place instead.
     */
    var pointLabels = {
        id: 'pointLabels',
        afterDatasetsDraw: function (c) {
            if (!c.$singlePoint) return;

            var area = c.chartArea;
            var ctx = c.ctx;

            c.data.datasets.forEach(function (ds, i) {
                var meta = c.getDatasetMeta(i);
                if (!meta || meta.hidden) return;

                var point = meta.data[0];
                if (!point) return;

                var v = ds.data[0];
                if (v === null || v === undefined || isNaN(v)) return;

                var text = ds.label + ' ' + money(v);
                ctx.save();
                ctx.font = '600 12px -apple-system, Segoe UI, Roboto, sans-serif';
                ctx.textBaseline = 'middle';

                var w = ctx.measureText(text).width;

                // To the right of the dot, unless that would run off the plot.
                var left = point.x + 10;
                if (left + w > area.right - 2) left = point.x - 10 - w;

                ctx.fillStyle = ds.borderColor || INK;
                ctx.fillText(text, left, point.y);
                ctx.restore();
            });
        }
    };

    var chart = null;
    var requestId = 0;
    var lastData = null;
    var statusBase = '';

    // The visible slice of the plotted series, as fractional indices. Zooming
    // moves this window instead of reloading: the chart instance survives, so
    // dragging costs one redraw and no network request.
    var view = { active: false, from: 0, to: 0, a: 0, b: 0 };
    var canvasEl = null;

    var statusEl = document.getElementById('status');
    var subEl = document.getElementById('sub');
    var statsEl = document.getElementById('stats');
    var freshEl = document.getElementById('freshness');
    var peakSectionEl = document.getElementById('peak-section');
    var peakTableBodyEl = document.querySelector('#peak-table tbody');
    var peakNoteEl = document.getElementById('peak-note');
    var projSectionEl = document.getElementById('projection-section');
    var projTableBodyEl = document.querySelector('#projection-table tbody');
    var projAssumptionEl = document.getElementById('projection-assumption');

    var HALVING = '#6d5f9e';        // muted violet: clearly not price data
    var PI = '#c2405a';             // crimson: likewise, and distinct from violet
    var DMA_FAST = '#3f7f9e';       // steel blue, 50-day
    var DMA_SLOW = '#1e4f66';       // darker steel, 200-day
    var CROSS_DEATH = '#b91c1c';
    var CROSS_GOLDEN = '#15803d';
    var PEAK = '#a16207';           // amber: 'top of cycle', reads as a badge

    /**
     * Cycle-top markers.
     *
     * A filled dot on the peak with its price beneath. The label goes BELOW the
     * point on purpose: a cycle top is by definition the highest thing on the
     * chart, so a label above it would sit outside the plot area and be clipped.
     */
    var peakMarkers = {
        id: 'peakMarkers',
        afterDatasetsDraw: function (c) {
            var peaks = c.$peaks;
            if (!peaks || !peaks.length) return;

            var labels = c.data.labels;
            var ctx = c.ctx;
            var area = c.chartArea;
            var lastLabelRight = -Infinity;

            peaks.forEach(function (peak) {
                var i = labels.indexOf(peak.date);
                if (i < 0) return;

                var px = c.scales.x.getPixelForValue(i);
                var py = c.scales.y.getPixelForValue(peak.usd);

                if (px < area.left || px > area.right) return;
                if (py < area.top - 2 || py > area.bottom) return;

                var r = peak.confirmed ? 4.5 : 3.5;

                ctx.save();
                ctx.beginPath();
                ctx.arc(px, py, r, 0, Math.PI * 2);
                ctx.fillStyle = PEAK;
                ctx.globalAlpha = peak.confirmed ? 0.95 : 0.55;
                ctx.fill();
                ctx.lineWidth = 1.2;
                ctx.strokeStyle = '#ffffff';
                ctx.globalAlpha = 0.8;
                ctx.stroke();
                ctx.restore();

                var text = money(peak.usd) + (peak.confirmed ? '' : ' ?');

                ctx.save();
                ctx.font = '700 10px -apple-system, Segoe UI, Roboto, sans-serif';
                var w = ctx.measureText(text).width;
                var labelX = px - w / 2;

                // Keep the text inside the plot horizontally.
                labelX = Math.min(area.right - w - 2, Math.max(area.left + 2, labelX));

                // Skip rather than overlap: two peaks close together would
                // otherwise print on top of each other.
                if (labelX > lastLabelRight + 6) {
                    ctx.textBaseline = 'top';
                    // Halo first: the label sits on top of the price line and
                    // the other averages, so plain text is hard to read there.
                    ctx.lineWidth = 3;
                    ctx.strokeStyle = 'rgba(255,255,255,0.92)';
                    ctx.lineJoin = 'round';
                    ctx.strokeText(text, labelX, py + r + 3);

                    ctx.fillStyle = PEAK;
                    ctx.fillText(text, labelX, py + r + 3);
                    lastLabelRight = labelX + w;
                }
                ctx.restore();
            });
        }
    };

    /**
     * Death and golden cross markers.
     *
     * Drawn as a dot at the point where the two averages met, not as a vertical
     * line: the chart already carries two sets of vertical markers (halvings and
     * Pi Cycle), and a third would turn the background into a lattice.
     */
    var dmaCrossMarkers = {
        id: 'dmaCrossMarkers',
        afterDatasetsDraw: function (c) {
            var marks = c.$dmaCrosses;
            if (!marks) return;

            var fastIdx = c.$dmaFastIndex;
            var slowIdx = c.$dmaSlowIndex;
            if (fastIdx === undefined || slowIdx === undefined) return;

            var labels = c.data.labels;
            var slowData = c.data.datasets[slowIdx].data;
            var ctx = c.ctx;

            ['death', 'golden'].forEach(function (kind) {
                (marks[kind] || []).forEach(function (date) {
                    var i = labels.indexOf(date);
                    if (i < 0) return;

                    var v = slowData[i];
                    if (v === null || v === undefined || !isFinite(v)) return;

                    var px = c.scales.x.getPixelForValue(i);
                    var py = c.scales.y.getPixelForValue(v);
                    var area = c.chartArea;

                    if (px < area.left || px > area.right) return;
                    if (py < area.top || py > area.bottom) return;

                    ctx.save();
                    ctx.beginPath();
                    ctx.arc(px, py, 4, 0, Math.PI * 2);
                    ctx.fillStyle = kind === 'death' ? CROSS_DEATH : CROSS_GOLDEN;
                    ctx.globalAlpha = 0.9;
                    ctx.fill();
                    ctx.lineWidth = 1;
                    ctx.strokeStyle = '#ffffff';
                    ctx.globalAlpha = 0.55;
                    ctx.stroke();
                    ctx.restore();
                });
            });
        }
    };

    /**
     * Vertical markers for Pi Cycle Top crossings.
     *
     * Same shape as the halving markers, offset to a second line at the top so
     * the two sets of year labels cannot land on top of each other (a Pi
     * crossing in 2013 sits close to the 2012 halving on a wide axis).
     */
    var piLines = {
        id: 'piLines',
        afterDatasetsDraw: function (c) {
            var dates = c.$piCrosses;
            if (!dates || !dates.length) return;

            var x = c.scales.x;
            var area = c.chartArea;
            var labels = c.data.labels;
            var ctx = c.ctx;
            var lastLabelRight = -Infinity;

            dates.forEach(function (date) {
                var idx = indexForDate(labels, date);
                if (idx === null) return;

                var px = x.getPixelForValue(idx);
                if (px < area.left || px > area.right) return;

                ctx.save();
                ctx.beginPath();
                ctx.setLineDash([4, 3]);
                ctx.strokeStyle = PI;
                ctx.globalAlpha = 0.75;
                ctx.lineWidth = 1.2;
                ctx.moveTo(px, area.top);
                ctx.lineTo(px, area.bottom);
                ctx.stroke();
                ctx.restore();

                var text = date.slice(0, 4);

                ctx.save();
                ctx.font = '600 10px -apple-system, Segoe UI, Roboto, sans-serif';
                var w = ctx.measureText(text).width;
                var fitsRight = px + 4 + w <= area.right - 2;
                var labelX = fitsRight ? px + 4 : px - 4 - w;

                if (labelX > lastLabelRight + 8) {
                    ctx.fillStyle = PI;
                    ctx.textBaseline = 'top';
                    ctx.fillText(text, labelX, area.top + 15);
                    lastLabelRight = labelX + w;
                }
                ctx.restore();
            });
        }
    };

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

        // The projection has dates of its own and needs axis room to sit on. Extend
        // the padded axis to reach it, but only while the extension stays
        // proportionate — the same rule the halving padding follows, for the same
        // reason: 24 months of empty axis would flatten a one-week view to a sliver.
        var projection = (state.projection && data.projection && data.projection.labels && data.projection.labels.length)
            ? data.projection
            : null;

        var projectionPad = 0;

        if (projection) {
            var projectionEnd = projection.labels[projection.labels.length - 1];
            var lastLabel = labels[labels.length - 1];

            if (projectionEnd > lastLabel) {
                var currentEnd = new Date(lastLabel + 'T00:00:00Z').getTime();
                var wantedEnd = new Date(projectionEnd + 'T00:00:00Z').getTime();
                var needDays = Math.round((wantedEnd - currentEnd) / 86400000);

                var realSpan = futureDays > 0
                    ? Math.round((currentEnd - new Date(labels[0] + 'T00:00:00Z').getTime()) / 86400000) - futureDays
                    : Math.round((currentEnd - new Date(labels[0] + 'T00:00:00Z').getTime()) / 86400000);

                // Room already granted by the halving padding still counts.
                var allowed = Math.max(0, Math.round(realSpan * 0.5) - futureDays);
                var extra = Math.min(needDays, allowed);

                for (var d = 1; d <= extra; d++) {
                    labels.push(new Date(currentEnd + d * 86400000).toISOString().slice(0, 10));
                    price.push(null);
                    ma.push(null);
                }

                futureDays += extra;
                projectionPad = extra;
                projection.clipped = extra < needDays;
            } else {
                projection.clipped = false;
            }
        }

        // Keep an existing zoom across a redraw that only changed the y-axis
        // (log toggle), but reset it when the series itself is different.
        var keep = (view.active && chart && chart.$labels &&
                    chart.$labels.length === labels.length)
            ? { from: view.from, to: view.to }
            : null;

        view.active = false;
        view.from = 0;
        view.to = Math.max(0, labels.length - 1);

        // Span decides both the tick format and how much the tooltip repeats.
        var firstDate = new Date(labels[0] + 'T00:00:00Z');
        var lastDate = new Date(labels[labels.length - 1] + 'T00:00:00Z');
        var spanDays = Math.round((lastDate - firstDate) / 86400000) + 1;

        // With one point per day, a 1D window holds a single observation and a
        // line has nothing to draw. Show the points themselves in that case.
        var singlePoint = spanDays <= 1;

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
        renderPeakTable(data);
        renderProjection(data);
        var plotted = labels.length - futureDays;
        var statusText = plotted.toLocaleString('en-US') +
            (plotted === 1 ? ' day plotted' : ' days plotted');

        if (futureDays > 0) {
            // Say which of the two reasons applies, since the padding now serves
            // both and naming only one would be misleading.
            var reasons = [];
            if (projectionPad > 0) reasons.push('the 1\u00d7 projection');
            if (futureDays > projectionPad) reasons.push('the projected next halving');

            statusText += ' \u00b7 axis extended to fit ' + reasons.join(' and ');

            if (projection && projection.clipped) {
                statusText += ' (projection cut short to keep the scale readable)';
            }
        } else if (state.halvings && nextHalving) {
            statusText += ' \u00b7 next halving ' + shortDate(nextHalving.date) + ' is out of range';
        }

        statusBase = statusText;
        updateStatus();

        var below = belowMask(labels, price, ma);
        var piData = data.pi || null;
        var showPi = state.pi && piData && piData.fast && piData.fast.length;
        var dmaData = data.dma || null;
        var showDma = state.dma && dmaData && dmaData.fast && dmaData.fast.length;

        // The projection has its own dates; align it to the axis and seed the last
        // day that has a real average, so the dashed continuation begins exactly
        // where the solid line ends instead of a day adrift. Built here, before
        // the datasets, because `var` hoists a declaration but not its value.
        var projectionData = null;

        if (projection) {
            var projByDate = {};
            for (var pj = 0; pj < projection.labels.length; pj++) {
                projByDate[projection.labels[pj]] = projection.ma[pj];
            }

            projectionData = labels.map(function (d) {
                return Object.prototype.hasOwnProperty.call(projByDate, d) ? projByDate[d] : null;
            });

            for (var lj = labels.length - 1; lj >= 0; lj--) {
                if (ma[lj] !== null && ma[lj] !== undefined) {
                    projectionData[lj] = ma[lj];
                    break;
                }
            }
        }

        // The Pi and 50/200-day arrays arrive for real days only, while labels
        // carry the padded future. Pad them with nulls so every dataset is the
        // same length: the zoom slices all of them by the same indices, and a
        // shorter array would shift its line left against the price as soon as
        // the window reached the projection.
        if (futureDays > 0) {
            if (showPi) {
                piData = {
                    fast_days: piData.fast_days,
                    slow_days: piData.slow_days,
                    multiple: piData.multiple,
                    crosses: piData.crosses,
                    fast_now: piData.fast_now,
                    slow_now: piData.slow_now,
                    gap_pct: piData.gap_pct,
                    fast: piData.fast.concat(Array(futureDays).fill(null)),
                    slow: piData.slow.concat(Array(futureDays).fill(null))
                };
            }

            if (showDma) {
                dmaData = {
                    fast_days: dmaData.fast_days,
                    slow_days: dmaData.slow_days,
                    crosses: dmaData.crosses,
                    fast_now: dmaData.fast_now,
                    slow_now: dmaData.slow_now,
                    state: dmaData.state,
                    state_since: dmaData.state_since,
                    price_vs_fast_pct: dmaData.price_vs_fast_pct,
                    price_vs_slow_pct: dmaData.price_vs_slow_pct,
                    fast: dmaData.fast.concat(Array(futureDays).fill(null)),
                    slow: dmaData.slow.concat(Array(futureDays).fill(null))
                };
            }
        }

        // Built as data, not as literals, because the Pi Cycle pair and the
        // 50/200 lines are both optional. Indices are recorded by key so the
        // zoom and the fill never depend on which toggles are on.
        var datasets = [];
        var idx = {};

        function add(key, def) {
            idx[key] = datasets.length;
            datasets.push(def);
        }

        add('below', {
            label: 'Price below the average',
            data: below,
            borderWidth: 0,
            pointRadius: 0,
            fill: '+1',              // fill down to the average line
            backgroundColor: 'rgba(247,147,26,0.38)',
            spanGaps: false,
            order: 3                 // drawn behind the strokes
        });

        add('ma', {
            label: '200-week average',
            data: ma,
            borderColor: ORANGE,
            borderWidth: 2.4,
            pointRadius: singlePoint ? 4 : 0,
            pointHoverRadius: singlePoint ? 6 : 0,
            pointBackgroundColor: ORANGE,
            pointBorderColor: ORANGE,
            fill: false,
            spanGaps: false,
            tension: 0,
            order: 1
        });

        add('price', {
            label: 'BTC price',
            data: price,
            borderColor: PRICE,
            borderWidth: 1.15,
            pointRadius: singlePoint ? 4 : 0,
            pointHoverRadius: singlePoint ? 6 : 0,
            pointBackgroundColor: PRICE,
            pointBorderColor: PRICE,
            fill: false,
            tension: 0,
            order: 0
        });

        if (showDma) {
            add('dmaFast', {                label: dmaData.fast_days + 'DMA',
                data: dmaData.fast,
                borderColor: DMA_FAST,
                borderWidth: 1.2,
                pointRadius: 0,
                fill: false,
                spanGaps: false,
                tension: 0,
                order: 2
            });

            add('dmaSlow', {
                label: dmaData.slow_days + 'DMA',
                data: dmaData.slow,
                borderColor: DMA_SLOW,
                borderWidth: 1.8,
                pointRadius: 0,
                fill: false,
                spanGaps: false,
                tension: 0,
                order: 2
            });
        }

        if (showPi) {
            add('piFast', {
                label: 'Pi Cycle ' + piData.fast_days + 'DMA',
                data: piData.fast,
                borderColor: PI,
                borderWidth: 1.6,
                pointRadius: 0,
                fill: false,
                spanGaps: false,
                tension: 0,
                order: 2
            });

            add('piSlow', {
                label: '2 \u00d7 ' + piData.slow_days + 'DMA',
                data: piData.slow,
                borderColor: PI,
                borderWidth: 1.4,
                borderDash: [6, 4],
                pointRadius: 0,
                fill: false,
                spanGaps: false,
                tension: 0,
                order: 2
            });
        }

        if (projectionData) {
            add('projection', {
                label: '200-week avg (projected)',
                data: projectionData,
                borderColor: ORANGE,
                borderWidth: 1.8,
                borderDash: [5, 4],
                pointRadius: 0,
                fill: false,
                spanGaps: false,
                tension: 0,
                order: 2
            });
        }

        var belowIndex = idx.below;
        var maIndex = idx.ma;
        var priceIndex = idx.price;

        var cfg = {
            type: 'line',
            plugins: [halvingLines, piLines, dmaCrossMarkers, peakMarkers, pointLabels],
            data: {
                labels: labels,
                datasets: datasets
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
                        // One category would otherwise sit hard against the left
                        // edge; offset gives it the middle of the plot.
                        offset: singlePoint,
                        grid: { display: false },
                        border: { color: '#e5e7eb' },
                        ticks: {
                            color: '#6b7280',
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 9,
                            callback: function (value, index) {
                                // Read the labels the chart is showing right now:
                                // when zoomed that is a slice, not the full series.
                                var current = (this.chart && this.chart.data && this.chart.data.labels) || labels;
                                var i = (typeof value === 'number' && isFinite(value)) ? value : index;
                                var iso = current[i];
                                if (!iso) return '';

                                // Over a wide span, label years. Over a short one
                                // the year repeats, so label the date instead.
                                // The span is the VISIBLE one, so zooming in swaps
                                // year labels for day labels without a refetch.
                                var span = visibleSpanDays();
                                if (span === null) span = spanDays;

                                var d = new Date(iso + 'T00:00:00Z');
                                var months = ['Jan','Feb','Mar','Apr','May','Jun',
                                              'Jul','Aug','Sep','Oct','Nov','Dec'];

                                // Three tiers, because each one alone reads badly at
                                // some zoom level: years repeat over a few thousand
                                // days, days repeat over a few thousand too.
                                if (span > 2900) return iso.slice(0, 4);
                                if (span > 400) return months[d.getUTCMonth()] + ' ' + iso.slice(0, 4);

                                return d.getUTCDate() + ' ' + months[d.getUTCMonth()];
                            }
                        }
                    },
                    y: {
                        // A single observation has no decades to compress, and a
                        // log axis only produces uneven tick spacing over a few
                        // percent of range, so the one-day view stays linear.
                        type: (state.log && !singlePoint) ? 'logarithmic' : 'linear',
                        grid: { color: '#eef0f3' },
                        border: { display: false },
                        ticks: {
                            color: '#6b7280',
                            // A log axis generates 1..9 x 10^n minor ticks, which
                            // pile up at the low end of a narrow range. Cap the
                            // count whenever every tick is labelled.
                            maxTicksLimit: labelEveryTick ? 6 : 10,
                            callback: function (value) {
                                // Zoomed in, the visible range is narrow enough
                                // that powers of ten would leave the axis blank.
                                if (view.active || labelEveryTick) {
                                    // The padded bounds sit a few pixels from a
                                    // round tick and their labels collide, so
                                    // the ends go unlabelled while zoomed.
                                    if (view.active) {
                                        var s = this;
                                        var eps = Math.abs(s.max - s.min) * 1e-9;
                                        if (Math.abs(value - s.min) <= eps || Math.abs(value - s.max) <= eps) {
                                            return '';
                                        }
                                    }

                                    return money(value);
                                }

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
                                if (!items.length) return '';
                                var current = (chart && chart.data && chart.data.labels) || labels;
                                return shortDate(current[items[0].dataIndex]);
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
        chart.$singlePoint = singlePoint;
        chart.$below = below;
        chart.$price = price;
        chart.$ma = ma;
        chart.$labels = labels;
        chart.$log = state.log && !singlePoint;
        chart.$labelEveryTick = labelEveryTick;

        // Everything the zoom needs to re-slice, in dataset order. Both overlay
        // pairs are optional, so indices are recorded rather than assumed.
        chart.$series = datasets.map(function (d) { return d.data; });
        chart.$belowIndex = belowIndex;
        chart.$maIndex = maIndex;
        chart.$priceIndex = priceIndex;
        chart.$piCrosses = showPi ? (piData.crosses || []) : [];
        chart.$dmaCrosses = showDma ? (dmaData.crosses || null) : null;
        chart.$dmaFastIndex = showDma ? idx.dmaFast : undefined;
        chart.$dmaSlowIndex = showDma ? idx.dmaSlow : undefined;
        chart.$peaks = (state.peaks && data.peaks && data.peaks.items) ? data.peaks.items : [];

        chart.update('none');

        if (keep) {
            view.active = true;
            view.from = keep.from;
            view.to = keep.to;
            applyView();
        } else {
            // The new series starts unzoomed; the Reset button has to say so.
            syncZoomButtons();
        }
    }

    function renderStats(stats, data) {
        var ratio = stats.ratio || 0;
        var premium = (ratio - 1) * 100;
        var sign = premium >= 0 ? '+' : '';

        statsEl.innerHTML =
            stat('BTC price', money(stats.price)) +
            stat('200-week average', money(stats.ma), 'ma') +
            stat('Price vs average', sign + premium.toFixed(1) + '%') +
            stat('Days below average', (stats.below_pct || 0).toFixed(1) + '%') +
            dmaStat(data) +
            piStat(data);

        freshEl.textContent = 'Latest stored day: ' + shortDate(data.to) + '.';
    }

    /**
     * Which 50/200-day regime we are in, and where price sits against both.
     *
     * The regime is a date-stamped fact (the last crossing), while "price below
     * the 50/200" is a standing state that can be true without any crossing
     * having happened — the two are shown separately for that reason.
     */
    function dmaStat(data) {
        var dma = data.dma;
        if (!state.dma || !dma || dma.fast_now === null || !dma.slow_now) {
            return '';
        }

        var label = 'DMA ' + dma.fast_days + ' / ' + dma.slow_days;

        // The headline is the standing state, because that is the day-to-day
        // question ("is price below the averages?"); the regime is the rarer
        // event and lives in the note.
        var below = [];
        if (dma.price_vs_fast_pct !== null && dma.price_vs_fast_pct < 0) {
            below.push(dma.fast_days);
        }
        if (dma.price_vs_slow_pct !== null && dma.price_vs_slow_pct < 0) {
            below.push(dma.slow_days);
        }

        var value = below.length === 2
            ? 'Below both'
            : (below.length === 1 ? 'Below ' + below[0] : 'Above both');

        var note = '';

        if (dma.state === 'death') {
            note = 'death cross ' + shortDate(dma.state_since);
        } else if (dma.state === 'golden') {
            note = 'golden cross ' + shortDate(dma.state_since);
        }

        return '<div class="stat"><dt>' + label + '</dt>' +
               '<dd class="dma">' + value +
               (note !== '' ? '<small> ' + note + '</small>' : '') +
               '</dd></div>';
    }

    /**
     * How far the Pi Cycle pair is from a top signal.
     *
     * A crossing is rare — four in this whole history — so the useful number is
     * the distance still to cover, not a red/green flag on a 13-year chart.
     */
    function piStat(data) {
        if (!state.pi || !data.pi || data.pi.fast_now === null || !data.pi.slow_now) {
            return '';
        }

        var pi = data.pi;
        var gap = pi.gap_pct;
        var label = 'Pi Cycle (' + pi.fast_days + ' vs 2\u00d7' + pi.slow_days + ')';

        return '<div class="stat"><dt>' + label + '</dt>' +
               '<dd class="pi">' + (gap === null ? '\u2014' : (gap >= 0 ? '+' : '') + gap.toFixed(1) + '%') +
               '<small> ' + (gap !== null && gap >= 0 ? 'crossed above' : 'below signal') + '</small>' +
               '</dd></div>';
    }

    /**
     * What 1x the 200-week average is now, and what it drifts to.
     *
     * The point of showing several dates rather than one number is that 1x is a
     * moving level: a moving average is a lagged mean, so it keeps travelling
     * even in a flat market, and its direction depends on which prices are
     * leaving the window. A single target would imply a fixed goal that does not
     * exist.
     */
    function renderProjection(data) {
        if (!projSectionEl || !projTableBodyEl) return;

        var projection = data.projection;

        if (!state.projection || !projection || !projection.labels || !projection.labels.length) {
            projSectionEl.hidden = true;
            return;
        }

        projSectionEl.hidden = false;

        projAssumptionEl.textContent =
            'Assume ' + money(projection.assumed_price) + ' holds from ' +
            shortDate(projection.from) + '. The line beyond the last real day is what the ' +
            '200-week average becomes under that assumption.';

        // A handful of milestones rather than every day: today, then roughly
        // quarterly-ish steps across the projection.
        var picks = [0];
        var step = Math.max(1, Math.round(projection.labels.length / 5));
        for (var i = step; i < projection.labels.length; i += step) picks.push(i);
        picks.push(projection.labels.length - 1);

        var seen = {};
        var now = (data.stats && data.stats.price) || projection.assumed_price;
        var html = '';

        picks.forEach(function (i) {
            if (seen[i]) return;
            seen[i] = true;

            var level = projection.ma[i];
            if (level === null || level === undefined) return;

            var needPct = (level / now - 1) * 100;

            html += '<tr>' +
                '<td data-label="When">' + shortDate(projection.labels[i]) + '</td>' +
                '<td class="num" data-label="1&times; level">' + money(level) + '</td>' +
                '<td class="num peak-mult" data-label="Price would need">' +
                    (needPct >= 0 ? '+' : '') + needPct.toFixed(1) + '%</td>' +
                '</tr>';
        });

        projTableBodyEl.innerHTML = html;
    }

    function stat(label, value, cls) {
        return '<div class="stat"><dt>' + label + '</dt>' +
               '<dd' + (cls ? ' class="' + cls + '"' : '') + '>' + value + '</dd></div>';
    }

    /**
     * The cycle-top table: each peak's price, the 200-week average on that day,
     * the multiple between them, and how long price had been above the average.
     *
     * Peaks older than the average itself (it needs 1,400 days of history) show
     * a dash. Printing a number there would be inventing one.
     */
    function renderPeakTable(data) {
        if (!peakSectionEl || !peakTableBodyEl) return;

        var rows = (state.peaks && data.peaks && data.peaks.vs_average) ? data.peaks.vs_average : [];

        if (!rows.length) {
            peakSectionEl.hidden = true;
            return;
        }

        var html = '';
        var missing = 0;

        rows.forEach(function (p) {
            var hasAverage = p.multiple !== null && p.multiple !== undefined;

            if (!hasAverage) missing++;

            html += '<tr>' +
                '<td data-label="Peak">' + shortDate(p.date) + '</td>' +
                '<td class="num" data-label="Price">' + money(p.usd) + '</td>' +
                '<td class="num' + (hasAverage ? '' : ' na') + '" data-label="200-week avg">' +
                    (hasAverage ? money(p.ma) : '\u2014') + '</td>' +
                '<td class="num peak-mult' + (hasAverage ? '' : ' na') + '" data-label="\u00d7 average">' +
                    (hasAverage ? p.multiple.toFixed(2) + '\u00d7' : '\u2014') + '</td>' +
                '<td class="num' + (p.days_above === null ? ' na' : '') + '" data-label="Days above">' +
                    (p.days_above === null ? '\u2014' : p.days_above.toLocaleString('en-US')) + '</td>' +
                '</tr>';
        });

        peakTableBodyEl.innerHTML = html;
        peakSectionEl.hidden = false;

        peakNoteEl.textContent = missing > 0
            ? 'The 200-week average needs 1,400 days of history, so it does not exist before mid-2014. The '
                + missing + ' earlier top' + (missing === 1 ? '' : 's') + ' above predate it and have no multiple.'
            : '"Days above" counts from the day price last crossed above the 200-week average to the peak.';
    }

    // ------------------------------------------------------------------ zoom
    //
    // Zooming moves a window over the already-loaded series rather than asking
    // the server for a smaller range. The window is expressed in fractional
    // indices so a pinch is as smooth as a wheel notch, and the y-axis is
    // rescaled to whatever is visible — otherwise zooming into a quiet month
    // of a log chart draws two flat lines with empty space above and below.

    var pointers = new Map();
    var dragState = null;
    var pinchState = null;

    function visibleSpanDays() {
        if (!view.active || !chart || !chart.$labels) return null;

        // Use the exact slice boundaries the chart was given, not a rounded
        // version of them, or the axis and the status line disagree by a day.
        var a = view.a || 0;
        var b = view.b || 0;
        if (b <= a) return 1;

        var d1 = new Date(chart.$labels[a] + 'T00:00:00Z');
        var d2 = new Date(chart.$labels[b] + 'T00:00:00Z');

        return Math.round((d2 - d1) / 86400000) + 1;
    }

    /** Lowest and highest plotted value inside the window, padded for breathing room. */
    function visibleValueRange() {
        if (!chart || !chart.$series) return null;

        var a = Math.max(0, Math.floor(view.from));
        var b = Math.min(chart.$labels.length - 1, Math.ceil(view.to));
        var lo = Infinity, hi = -Infinity;

        // Every visible dataset counts, not just price and average: the Pi Cycle
        // slow line sits well above price near a signal, and ignoring it would
        // clip the very line the indicator exists to show.
        chart.$series.forEach(function (series, i) {
            if (i === chart.$belowIndex) return;   // derived fill, not a value

            for (var k = a; k <= b; k++) {
                var v = series[k];
                if (v !== null && v !== undefined && isFinite(v)) {
                    if (v < lo) lo = v;
                    if (v > hi) hi = v;
                }
            }
        });

        if (!isFinite(lo) || !isFinite(hi)) return null;
        if (hi <= lo) { lo *= 0.98; hi *= 1.02; }
        if (lo <= 0) lo = Math.max(0.0001, hi / 1000);

        if (chart.$log) {
            // Pad in log space, not by a fixed ratio: a fixed ratio shrinks to
            // nothing as the window narrows, leaving the line stuck to the edge
            // of a deeply zoomed chart.
            var logLo = Math.log(lo);
            var logHi = Math.log(hi);
            var padLog = (logHi - logLo) * 0.08;

            return { lo: Math.exp(logLo - padLog), hi: Math.exp(logHi + padLog) };
        }

        var pad = (hi - lo) * 0.08;

        return { lo: Math.max(0, lo - pad), hi: hi + pad };
    }

    function applyView() {
        if (!chart || !chart.options || !chart.$labels) return;

        var y = chart.options.scales.y;
        var full = chart.$labels;

        if (!view.active) {
            // Restore the whole series. Copies, not the originals: Chart.js owns
            // the arrays it is given and the untouched ones stay reusable.
            chart.data.labels = full.slice();

            for (var d = 0; d < chart.data.datasets.length; d++) {
                chart.data.datasets[d].data = chart.$series[d].slice();
            }

            y.min = undefined;
            y.max = undefined;
        } else {
            // Slicing is what makes zoom correct: the category axis numbers its
            // own labels, so a min/max on it does not mean "data index" and the
            // ticks end up describing the wrong years. Handing the chart only
            // the visible slice keeps data, labels, tooltip and halving markers
            // in agreement.
            var a = Math.max(0, Math.floor(view.from));
            var b = Math.min(full.length - 1, Math.ceil(view.to));

            if (b - a < 1) b = Math.min(full.length - 1, a + 1);

            view.a = a;
            view.b = b;

            var labels = full.slice(a, b + 1);

            chart.data.labels = labels;

            // Slice every dataset by the same window, whatever the count. The
            // Pi Cycle pair is optional, so index arithmetic would break the
            // moment it is toggled.
            for (var k = 0; k < chart.data.datasets.length; k++) {
                chart.data.datasets[k].data = chart.$series[k].slice(a, b + 1);
            }

            // The below-average fill is derived, not stored, so it is rebuilt
            // from the sliced price and average rather than sliced itself.
            chart.data.datasets[chart.$belowIndex].data = belowMask(
                labels,
                chart.data.datasets[chart.$priceIndex].data,
                chart.data.datasets[chart.$maIndex].data
            );

            var range = visibleValueRange();

            // Explicit padded bounds, so the series never touches the frame.
            y.min = range ? range.lo : undefined;
            y.max = range ? range.hi : undefined;
        }

        // A zoomed axis spans too few decades for power-of-ten ticks to say
        // anything, so the tick count follows the same rule as labelEveryTick.
        y.ticks.maxTicksLimit = (view.active || chart.$labelEveryTick) ? 6 : 10;

        chart.update('none');
        updateStatus();
        syncZoomButtons();
    }

    function resetZoom() {
        if (!view.active) return;

        view.active = false;
        view.from = 0;
        view.to = chart && chart.$labels ? chart.$labels.length - 1 : 0;
        applyView();
    }

    /**
     * Zoom about an anchor index (the point under the cursor or between the
     * fingers). factor < 1 tightens, > 1 widens.
     */
    function zoomBy(factor, anchor) {
        if (!chart || !chart.$labels) return;

        var total = chart.$labels.length - 1;
        if (total < 1) return;

        var from = view.active ? view.from : 0;
        var to = view.active ? view.to : total;
        var span = to - from;

        if (span <= 0) return;

        if (anchor === undefined || anchor === null || !isFinite(anchor)) {
            anchor = (from + to) / 2;
        }
        anchor = Math.min(to, Math.max(from, anchor));

        // Two points is the floor: a single point has no line to draw and the
        // axis math starts dividing by zero.
        var newSpan = Math.max(2, Math.min(total, span * factor));

        // Hold the anchor at the same fraction of the window, so zooming feels
        // anchored to what the pointer is actually over.
        var frac = span > 0 ? (anchor - from) / span : 0.5;
        frac = Math.min(1, Math.max(0, frac));

        var nFrom = anchor - frac * newSpan;
        var nTo = nFrom + newSpan;

        if (nFrom < 0) { nFrom = 0; nTo = newSpan; }
        if (nTo > total) { nTo = total; nFrom = total - newSpan; }

        if (nFrom <= 0 && nTo >= total) {
            resetZoom();
            return;
        }

        view.active = true;
        view.from = nFrom;
        view.to = nTo;
        applyView();
    }

    function panBy(fraction) {
        if (!view.active && !fraction) return;

        var total = chart.$labels.length - 1;
        var from = view.active ? view.from : 0;
        var to = view.active ? view.to : total;
        var span = to - from;

        var shift = fraction * span;
        var nFrom = from + shift;
        var nTo = to + shift;

        if (nFrom < 0) { nFrom = 0; nTo = span; }
        if (nTo > total) { nTo = total; nFrom = total - span; }

        view.active = true;
        view.from = nFrom;
        view.to = nTo;
        applyView();
    }

    /** Fractional index under a client x coordinate, in FULL-series space. */
    function indexAtClientX(clientX) {
        if (!chart || !chart.chartArea) return null;

        var rect = chart.canvas.getBoundingClientRect();
        var px = clientX - rect.left;

        // While zoomed the scale describes the slice, so add the slice offset
        // back to get an index the view window understands.
        var base = view.active ? (view.a || 0) : 0;
        var total = chart.$labels.length - 1;

        var v = chart.scales.x.getValueForPixel(px);

        if (!isFinite(v)) {
            v = px < chart.chartArea.left ? 0 : (view.active ? (view.to - view.from) : total);
        }

        return Math.min(total, Math.max(0, base + v));
    }

    function updateStatus() {
        if (view.active && chart && chart.$labels) {
            var a = view.a || 0;
            var b = view.b || a;
            var days = visibleSpanDays() || 0;

            setStatus(statusBase +
                ' \u00b7 zoomed to ' + shortDate(chart.$labels[a]) + ' \u2013 ' + shortDate(chart.$labels[b]) +
                ' (' + days.toLocaleString('en-US') + (days === 1 ? ' day' : ' days') + ')' +
                ' \u00b7 double-click the chart to reset');
        } else {
            setStatus(statusBase);
        }
    }

    function syncZoomButtons() {
        var reset = document.getElementById('zoom-reset');
        if (reset) reset.disabled = !view.active;
    }

    function wireZoom() {
        canvasEl = document.getElementById('chart');
        if (!canvasEl) return;

        // Wheel: one notch is a fixed ratio, so zooming feels the same at any
        // depth. Ctrl/⌘ is left alone in case the user wants browser zoom.
        canvasEl.addEventListener('wheel', function (e) {
            if (!chart || !chart.chartArea) return;
            if (e.ctrlKey || e.metaKey) return;

            e.preventDefault();

            var anchor = indexAtClientX(e.clientX);

            // Scale with the delta so a trackpad's small scrolls are gentle and
            // a mouse notch is decisive. Fixed factors make one of them wrong.
            var factor = Math.exp((e.deltaY || 0) * 0.0025);
            factor = Math.min(2, Math.max(1 / 2, factor));

            zoomBy(factor, anchor);
        }, { passive: false });

        canvasEl.addEventListener('pointerdown', function (e) {
            if (!chart || !chart.chartArea) return;

            pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

            try { canvasEl.setPointerCapture(e.pointerId); } catch (err) {}

            if (pointers.size === 1) {
                var total = chart.$labels.length - 1;
                dragState = {
                    x: e.clientX,
                    from: view.active ? view.from : 0,
                    to: view.active ? view.to : total
                };
                canvasEl.classList.add('is-dragging');
            } else if (pointers.size === 2) {
                dragState = null;
                var pts = Array.from(pointers.values());
                var midX = (pts[0].x + pts[1].x) / 2;

                pinchState = {
                    dist: Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y) || 1,
                    anchor: indexAtClientX(midX),
                    from: view.active ? view.from : 0,
                    to: view.active ? view.to : chart.$labels.length - 1
                };
            }
        });

        canvasEl.addEventListener('pointermove', function (e) {
            if (!pointers.has(e.pointerId)) return;

            pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

            if (pointers.size === 2 && pinchState) {
                var pts = Array.from(pointers.values());
                var dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y) || 1;
                var ratio = pinchState.dist / dist;   // spread fingers -> ratio < 1 -> zoom in

                var total = chart.$labels.length - 1;
                var span = pinchState.to - pinchState.from;
                var nSpan = Math.max(2, Math.min(total, span * ratio));
                var anchor = pinchState.anchor === null ? (pinchState.from + pinchState.to) / 2 : pinchState.anchor;
                var frac = span > 0 ? (anchor - pinchState.from) / span : 0.5;

                frac = Math.min(1, Math.max(0, frac));

                var nFrom = anchor - frac * nSpan;
                var nTo = nFrom + nSpan;

                if (nFrom < 0) { nFrom = 0; nTo = nSpan; }
                if (nTo > total) { nTo = total; nFrom = total - nSpan; }

                view.active = !(nFrom <= 0 && nTo >= total);
                view.from = view.active ? nFrom : 0;
                view.to = view.active ? nTo : total;
                applyView();
                return;
            }

            if (!dragState) return;

            var area = chart.chartArea;
            var width = area.right - area.left;
            if (width <= 0) return;

            // Panning is measured as a fraction of the plot width, so it stays
            // consistent no matter how deep the zoom already is.
            var dx = (e.clientX - dragState.x) / width;
            var span0 = dragState.to - dragState.from;
            var shift = -dx * span0;

            var total0 = chart.$labels.length - 1;
            var nFrom = dragState.from + shift;
            var nTo = dragState.to + shift;

            if (nFrom < 0) { nFrom = 0; nTo = span0; }
            if (nTo > total0) { nTo = total0; nFrom = total0 - span0; }

            view.active = !(nFrom <= 0 && nTo >= total0);
            view.from = view.active ? nFrom : 0;
            view.to = view.active ? nTo : total0;
            applyView();
        });

        function endPointer(e) {
            pointers.delete(e.pointerId);

            if (pointers.size < 2) pinchState = null;
            if (pointers.size === 0) {
                dragState = null;
                canvasEl.classList.remove('is-dragging');
            }
        }

        canvasEl.addEventListener('pointerup', endPointer);
        canvasEl.addEventListener('pointercancel', endPointer);

        canvasEl.addEventListener('dblclick', function () {
            resetZoom();
        });

        // Keyboard: the same operations without a pointer.
        canvasEl.addEventListener('keydown', function (e) {
            var step = 0.15;

            if (e.key === 'ArrowLeft') { panBy(-step); }
            else if (e.key === 'ArrowRight') { panBy(step); }
            else if (e.key === '+' || e.key === '=') { zoomBy(1 / 1.3); }
            else if (e.key === '-' || e.key === '_') { zoomBy(1.3); }
            else if (e.key === '0') { resetZoom(); }
            else { return; }

            e.preventDefault();
        });

        var zin = document.getElementById('zoom-in');
        if (zin) zin.addEventListener('click', function () { zoomBy(1 / 1.4); });

        var zout = document.getElementById('zoom-out');
        if (zout) zout.addEventListener('click', function () { zoomBy(1.4); });

        var zreset = document.getElementById('zoom-reset');
        if (zreset) zreset.addEventListener('click', function () { resetZoom(); });

        syncZoomButtons();
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

        var piBox = document.getElementById('pi');
        if (piBox) piBox.checked = state.pi;

        var dmaBox = document.getElementById('dma');
        if (dmaBox) dmaBox.checked = state.dma;

        var peaksBox = document.getElementById('peaks');
        if (peaksBox) peaksBox.checked = state.peaks;

        var projBox = document.getElementById('projection');
        if (projBox) projBox.checked = state.projection;

        document.querySelectorAll('[data-mode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-mode]').forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });
                state.mode = btn.dataset.mode;
                load();
            });
        });

        // Range buttons: relative windows (1D/1W/1M/1Y), absolute years, and All.
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

        var peaksToggle = document.getElementById('peaks');
        if (peaksToggle) {
            peaksToggle.addEventListener('change', function () {
                state.peaks = peaksToggle.checked;
                // The markers are drawn by a plugin reading the chart instance,
                // so a redraw is enough; no refetch.
                if (lastData) {
                    draw(lastData);
                } else {
                    load();
                }
            });
        }

        var projToggle = document.getElementById('projection');
        if (projToggle) {
            projToggle.addEventListener('change', function () {
                state.projection = projToggle.checked;
                // The axis may grow or shrink to fit the projection, so rebuild.
                if (lastData) {
                    draw(lastData);
                } else {
                    load();
                }
            });
        }

        var dmaToggle = document.getElementById('dma');
        if (dmaToggle) {
            dmaToggle.addEventListener('change', function () {
                state.dma = dmaToggle.checked;
                // The dataset set changes, so rebuild rather than toggle a scale.
                if (lastData) {
                    draw(lastData);
                } else {
                    load();
                }
            });
        }

        var piToggle = document.getElementById('pi');
        if (piToggle) {
            piToggle.addEventListener('change', function () {
                state.pi = piToggle.checked;
                // The dataset set changes, so rebuild rather than toggle a scale.
                if (lastData) {
                    draw(lastData);
                } else {
                    load();
                }
            });
        }

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
        if (halvingToggle) {            halvingToggle.addEventListener('change', function () {
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
    wireZoom();
    load();
})();
