# Bitcoin vs its 200-week moving average

A small, self-contained web app that charts the bitcoin price against its
200-week moving average. PHP + MySQL on the server, Chart.js in the browser,
no frameworks and no build step.

![The chart](docs/preview.png)

## What it does

- Imports the full daily bitcoin price history into MySQL (`bin/import.php`)
- Computes the 200-week average on request (`/api/series.php`)
- Renders price and average together, shading the stretches where price traded
  **below** the average
- Marks every bitcoin halving with a vertical line, and projects the next one
  from the current chain height
- Lets you switch between the two common definitions of the average, pick a
  timeframe (1D / 1W / 1M / 1Y / all, or from a given year), and toggle log/linear
  scaling
- Zooms and pans: wheel or pinch to zoom, drag to pan, double-click or press
  `0` to reset (see [Zooming](#zooming))
- Overlays the Pi Cycle Top indicator — 111DMA against 2 × 350DMA — with the
  historical crossings marked (see [Pi Cycle Top](#pi-cycle-top))
- Overlays the 50-day and 200-day averages, marking golden and death crosses
  (see [50/200-day averages and crosses](#50200-day-averages-and-crosses))
- Marks the peak of every past market cycle, detected from the data rather than
  hardcoded (see [Cycle tops](#cycle-tops))
- Projects 1× the 200-week average forward under a stated assumption, and says
  plainly that it is arithmetic rather than a forecast
  (see [1× the 200-week average](#1-the-200-week-average-projection))

## Zooming

Wheel, pinch, or the `+` / `−` buttons zoom the x-axis; drag pans; double-click,
the **Reset** button, or the `0` key returns to the full range. With the canvas
focused, `+`/`−` zoom and the arrow keys pan.

Zooming moves a window over the data already in the browser. It does not
refetch, so a wheel gesture costs one canvas redraw and one database query is
saved. The y-axis rescales to whatever is visible — on a log scale over 13
years, a three-month window would otherwise be two flat lines in the middle of
a lot of empty space.

Two details worth knowing:

* **The series is sliced, not masked.** Handing the chart a shorter array keeps
  its category axis, tooltips and halving markers in agreement. Setting a
  min/max on a category axis does not mean "data index", and the tick labels
  quietly describe the wrong years.
* **The zoom belongs to the loaded range.** Changing mode or timeframe loads a
  different series and resets to unzoomed; toggling log scale keeps the window,
  since only the y-axis changes.

A 1D window is a single point and refuses to zoom, rather than collapsing the
axis to nothing.

## Requirements

- PHP 8.1+ with `pdo_mysql`
- MySQL 5.7+ / MariaDB 10.3+
- A modern browser (Chart.js is loaded from a CDN, so the page needs network
  access on first load)

Tested on PHP 8.5 and MariaDB 10.4.

## Setup

```bash
git clone <this repo> btc-200wma
cd btc-200wma

# 1. configuration
cp config/config.sample.php config/config.php
#    edit the db credentials in config.php

# 2. schema
mysql -u root -p < database/schema.sql

# 3. price history (~6,500 days, one HTTP request)
php bin/import.php

# 4. serve
php -S localhost:8000 -t public
```

Then open <http://localhost:8000>.

The view is also linkable: `?range=year` or `?mode=daily` open straight into
that selection.

If you use Apache or nginx instead, point the document root at `public/`. The
only PHP files served directly are `public/index.php` and
`public/api/series.php`; everything else lives outside the web root.

## Layout

```
bin/import.php              CLI: fetch history into MySQL
config/config.sample.php    copy to config.php (git-ignored)
database/schema.sql          tables: prices, import_runs
public/index.php            the page
public/api/series.php       JSON: dates, prices, average, stats
public/js/app.js            Chart.js rendering + controls
public/css/style.css        styling
src/App.php                 config + autoloader + factories
src/Database.php            PDO connection
src/PriceRepository.php     queries and upserts
src/MovingAverage.php       the average itself
src/Importer.php            download, normalise, store
tests/MovingAverageTest.php plain-PHP tests for the average
tests/PiCycleTest.php       plain-PHP tests for the Pi Cycle indicator
tests/MovingAveragesTest.php plain-PHP tests for the 50/200-day averages
tests/CyclePeaksTest.php    plain-PHP tests for cycle-top detection
tests/PeakJourneyTest.php   plain-PHP tests for peak-vs-average analysis
tests/ProjectionTest.php    plain-PHP tests for the 1x projection
```

## The 200-week average: which definition?

There are two, and they are not identical. Both are supported because the
difference is big enough to quote the wrong number if you are not explicit.

**Weekly closes (default).** The canonical definition: take the price at the
end of each week, average the last 200 of those closes. This is what most
charts showing "200W MA" use.

**Daily window (`?mode=daily`).** A straight rolling mean over the last
1,400 calendar days.

On the same data these differ by roughly 0.1%. That is small, but it is not
rounding — it is a genuinely different statistic.

Two details worth knowing:

* **A week runs Monday to Sunday**, and its close is Sunday's price. An
  unfinished trailing week is not a close, so it is excluded.
* **Each week's average is drawn across the days of that week.** This means the
  plotted line "knows" Sunday's close for up to six days before Sunday arrives.
  Over a 1,400-day window that lookahead is negligible, but it is a real
  property of the line, not an accident.

`tests/MovingAverageTest.php` pins both behaviours down.

## Data source

[blockchain.info](https://api.blockchain.info/charts/market-price) public chart
API — no key required. One point per day.

Because the source is daily, the 1D timeframe is a single observation — the
latest stored day — not an intraday session. There is no finer granularity in
this dataset.

Two things this imposes on the chart:

* It reports a **daily average price**, not an exchange close. Differences from
  a close-based series are small at this scale but non-zero.
* History begins in 2010 with a run of zeros before the first real print. Zeros
  are "no data", not "$0", so the importer drops them. The average therefore
  only exists from mid-2014 onward, once 200 weeks of history do.

The importer verifies that the series contains exactly one row per calendar day
before storing anything, and forward-fills any missing day, so the rolling
window counts days rather than observations.

The source can lag spot by a day or two. The page shows the latest stored day
so this is visible rather than assumed.

### Peak vs the 200-week average

Below the stat cards, a table answers how far each top ran above the average:

| Peak | Price | 200-week avg | × average | Days above |
|------|-------|--------------|-----------|------------|
| 11 Jun 2011 | $33.80 | — | — | — |
| 5 Dec 2013 | $1,137 | — | — | — |
| 17 Dec 2017 | $19,280 | $1,242 | 15.52× | 808 |
| 9 Nov 2021 | $67,562 | $17,369 | 3.89× | 600 |
| 7 Oct 2025 | $124,777 | $53,730 | 2.32× | 722 |

**× average** is the peak price divided by the 200-week average *on that day*.
**Days above** counts from the day price last crossed above the average to the
peak — the length of the run that carried it there.

The two early tops show a dash, and that is not a formatting choice: the
200-week average needs 1,400 days of history, so it does not exist before
mid-2014. The 2011 and 2013 peaks predate it entirely. `PeakJourney` returns
nulls for them and the table prints an em dash, because a number there would be
invented.

The declining multiple — 15.5×, then 3.9×, then 2.3× — is the familiar
diminishing-returns pattern of a maturing asset. It is worth noting what that
does *not* mean: three points is not a trend, and nothing here forecasts a
fourth.

## 1× the 200-week average (projection)

Toggled by **1× projection** (on by default). A dashed orange line continues the
200-week average beyond the last real day, and a table lists what 1× would be at
several future dates.

**This is arithmetic, not a forecast.** Nothing here predicts where Bitcoin will
trade. A moving average is a lagged mean, so given an assumption about future
prices the average itself follows deterministically. The assumption is stated on
the page and in the API response, because it is the only thing making the line
mean anything:

> the price holds at its latest close.

Under that assumption the average still moves — the window retires old prices and
admits the assumed one. Which produces a result worth seeing, because it is not
what most people expect:

* **When the window is discarding prices BELOW the current price, the average
  RISES even in a flat market.** In November 2026 the 200-week window is shedding
  2022–23's lows, so a flat $65.3k carries the average from $67.9k toward $77k
  over the following year. Price falls *further behind* the average, not closer.
* Once those highs leave the window too, the average turns back down — the
  projection peaks around mid-2028 and declines after.

So "1× the average" is not a fixed level to aim at. It is a moving target, and
its direction depends entirely on which prices are leaving the window. The table
shows the level at several dates rather than one number for exactly that reason.

Both average definitions are supported, by appending synthetic days and running
the **same** `MovingAverage` used for the chart. A projected line that quietly
used a different formula from the line it continues would be worse than no
projection at all.

**The line is capped, on purpose.** It extends at most half the visible span —
the same rule the halving padding follows. On the full-history view that is the
whole two years; on a 1Y view it is six months; on a 1W view it barely appears.
Without the cap, two years of projection would flatten a one-week chart to a
sliver. When the cap bites, the status line says so rather than leaving a
mysteriously short line.

## Cycle tops

Toggled by **Cycle tops** (on by default). Amber dots mark the peaks of past
market cycles, labelled with the price.

These are **detected from the data, not hardcoded**. A day qualifies when all
three hold:

1. it rose into the day,
2. it is a new all-time high, and
3. no day in the following 365 days trades higher.

In one sentence: **a record high that went unbeaten for a year.** Every Bitcoin
cycle top satisfied that, and nothing else did — which is why the rule
reproduces the peaks instead of being tuned to them.

On this data it finds exactly five:

| Date | Price |
|------|-------|
| 2011-06-11 | $33.80 |
| 2013-12-05 | $1,136.90 |
| 2017-12-17 | $19,279.90 |
| 2021-11-09 | $67,562.17 |
| 2025-10-07 | $124,776.68 |

Each rule exists because a simpler version drew a plausible-looking chart that
was wrong:

* Rule 1 collapses a plateau to one peak, on the day the price arrived, instead
  of one per day.
* Rule 2 rejects a recovery. Returning to a level the price already sat at is not
  a top, and a purely forward-looking test marks it, because nothing ahead
  happens to exceed it.
* Rule 3 is the prominence test. Without it the April 2013 and April 2021 double
  tops are promoted to cycle tops, and with a 180-day window so is the March
  2024 high — all of which were beaten within the year that followed.

`php tests/CyclePeaksTest.php` pins each rule, including the case that motivated
rule 2.

Two limits, stated rather than hidden:

* **Rule 3 looks forward**, so a peak inside the last 365 days cannot be
  confirmed — it may still be exceeded. Such peaks are reported with
  `confirmed: false` and drawn faded, so a forming top stays visible without
  being presented as history. The October 2025 high currently has a full year of
  follow-through, so it is confirmed.
* **These are daily average prices**, not intraday highs, so the date can differ
  by a day or so from sources quoting the highest tick.

## 50/200-day averages and crosses

A third overlay, toggled by **50/200 DMA** (on by default): the 50-day and
200-day simple moving averages on **daily** closes — note that these are days,
not the weeks used by the 200-week average.

Crossings are marked with a dot where the two lines meet, red for a **death
cross** (50DMA crossing below the 200DMA) and green for a **golden cross** (50DMA
crossing above). They are dots rather than vertical lines because the chart
already carries two sets of vertical markers; a third would become a lattice.

Three distinctions the code and tests hold to:

* **A cross is an event, a level is a state.** "Price below the 200DMA" can last
  a year; "death cross" is the single day the relation changed. Reporting the
  state as the event would turn 13 signals into thousands.
* **Touching counts.** The two averages converge before they separate, so the
  comparison is tracked as a sign (+1 / 0 / −1), not as a boolean. As a boolean,
  "equal then below" reads as no change on both days and the very day of the
  cross is dropped.
* **The relations are independent.** A golden cross (50 above 200) can coexist
  with price below both — as it did at the time of writing — which is exactly
  the "cross forming" case. The stat card states them separately for that
  reason: headline is where price sits, note is the regime and its date.

On this data that yields 13 deaths and 13 goldens between 2011 and 2026, each a
verified one-day transition. The exact dates differ from other chart sites
because the underlying series is blockchain.info's **daily average** price, not
an exchange close; the crossing is correct for this data, which is not a claim
that it matches anyone else's date.

The lines are dense across 13 years and read best zoomed in, which is what the
zoom controls are for.

## Pi Cycle Top

A second overlay, toggled by **Pi Cycle** (on by default). Two lines:

* **111-day simple moving average**, and
* **2 × the 350-day simple moving average**.

When the 111DMA rises through 2 × the 350DMA, that crossing has historically
coincided with a cycle high. The name comes from 350 / 111 = 3.153, the closest
you can get to pi by dividing 350 by a whole number.

Two things are easy to get wrong, and both are pinned by
`tests/PiCycleTest.php`:

* **The multiple is of the price, not the days.** 2 × 350DMA means twice the
  average *price*; it is not a 700-day average.
* **The signal is a crossing, not a level.** Sitting above is the after-effect;
  the event is the day it moved from at-or-below to above. Touching and then
  rising counts.

On the stored history this yields four crossings — 2013-04-07, 2013-12-07,
2017-12-17 and 2021-04-13 — each within three days of a local price peak, which
is what the indicator claims for itself. They are drawn as dashed vertical
markers, offset to a second line at the top so they cannot collide with the
halving labels.

Definition taken from the indicator's published description
(<https://www.lookintobitcoin.com/charts/pi-cycle-top-indicator/>, retrieved
2026-11-16). The Pi Cycle *Bottom* variant is deliberately absent: its periods
could not be confirmed from a primary source, and guessing them would produce a
plausible-looking chart that is simply wrong.

## Halvings

Vertical markers, drawn by a small custom Chart.js plugin (no extra dependency).

Past halvings are facts, taken from the timestamp of the first block of each
reward era:

| Block     | Date (UTC)          |
|-----------|---------------------|
| 210,000   | 2012-11-28 15:24    |
| 420,000   | 2016-07-09 16:46    |
| 630,000   | 2020-05-11 19:23    |
| 840,000   | 2024-04-20 00:09    |

Note the 2024 date is the **20th** in UTC — it is often quoted as the 19th
because that is the local date in US timezones.

**The next halving is a projection, not a fact.** It happens at block 1,050,000,
and the date depends on how quickly blocks are found. `bin/import.php` reads the
current height and stores it; the projection is current height plus the
remaining blocks at 600 seconds each, marked as an estimate and drawn dashed.

Two consequences worth knowing:

* The next halving is always in the future, so it falls outside the price data.
  The chart appends empty days to the x-axis to give it a place to sit — but
  only when that adds at most half the current span. On a 1W view, padding 18
  months of empty axis would squash the week to a sliver, so the marker is left
  out and the status line says it is out of range.
* Re-run the import to refresh the projection. It drifts as the block rate
  changes; nothing pretends otherwise.

## API

`GET /api/series.php`

| Parameter | Values                          | Default  | Meaning                          |
|-----------|---------------------------------|----------|----------------------------------|
| `mode`    | `weekly`, `daily`               | `weekly` | average definition               |
| `range`   | `day`, `week`, `month`, `year`  | all      | last 1 / 7 / 30 / 365 days       |
| `since`   | year, e.g. `2020`               | all      | trim from that calendar year     |

The `halvings` array in the response is never windowed — the client decides
which markers fall inside the visible range.

`range` is measured back from the **last stored day**, not from today. The
source can lag, and anchoring to "now" would leave a gap at the right edge of
the chart. If both `range` and `since` are supplied, `range` wins.

Response:

```json
{
  "mode": "weekly",
  "weeks": 200,
  "from": "2014-06-09",
  "to": "2026-11-03",
  "labels": ["2010-08-18", "..."],
  "price": [0.07, "..."],
  "ma": [null, "...", 67341.31],
  "stats": { "price": 76675.03, "ma": 67341.31, "ratio": 1.1386,
             "below_pct": 8.47, "points": 4531 }
}
```

`ma` is `null` for every day before 200 weeks of history exist. The client is
expected to break the line there rather than plot a zero.

`stats` always describes the whole series, not the zoomed window, so the
headline number does not change when you zoom.

The `pi` object carries the Pi Cycle overlay: `fast` and `slow` arrays aligned
with `labels` (null until enough history exists), `crosses` as a list of ISO
dates for every historical 111DMA-over-2×350DMA crossing, plus `fast_now`,
`slow_now` and `gap_pct` for the latest day. Like `halvings`, `crosses` is not
windowed — the client decides which markers fall inside the visible range.

The `dma` object carries the 50/200-day overlay in the same shape: `fast` and
`slow` arrays, `crosses` split into `death` and `golden` date lists, `fast_now`
and `slow_now`, `state` (`"death"`, `"golden"` or null) with `state_since`, and
`price_vs_fast_pct` / `price_vs_slow_pct` for the latest close against each
line — negative meaning below.

`peaks.items` lists the detected cycle tops as `{date, usd, confirmed}`, with
`peaks.window_days` stating the prominence window used. Also not windowed.

`peaks.vs_average` adds each peak's `ma` (the 200-week average that day),
`multiple` (peak ÷ average), `days_above` and `crossed_above`. The first two are
null for peaks older than the average itself.

`projection` carries the 1× projection: `assumption` (the sentence the numbers
depend on), `assumed_price`, `from`, and parallel `labels` / `ma` / `ratio`
arrays. `ratio` is the assumed price divided by the projected average — below
1.0 means price is under it.

## Refreshing the data

`bin/import.php` is idempotent — rows are upserted by date, so re-running only
refreshes recent values and fills new days. Run it from cron:

```
15 3 * * * cd /path/to/btc-200wma && php bin/import.php >/dev/null 2>&1
```

Each run is recorded in `import_runs` with row counts and status.

### Windows: run it daily

`setup_daily_import.ps1` registers a Scheduled Task that runs the import every
night at 03:15 and catches up on the next boot if the machine was off:

```
powershell -ExecutionPolicy Bypass -File setup_daily_import.ps1
```

It runs as `SYSTEM`, so no stored password is needed. Remove it with:

```
Unregister-ScheduledTask -TaskName 'btc-200wma daily import' -Confirm:$false
```

The page always states how far the data goes (`Latest stored day: …`) rather
than implying it is current, so a stale import is visible instead of silent.

## Tests

```bash
php tests/MovingAverageTest.php
php tests/HalvingsTest.php
php tests/PiCycleTest.php
php tests/MovingAveragesTest.php
php tests/CyclePeaksTest.php
php tests/PeakJourneyTest.php
php tests/ProjectionTest.php
```

17 assertions on week alignment, exclusion of the partial trailing week, and
both averaging modes; 20 on the halving schedule and projection; 14 on the Pi
Cycle windows, the price multiple and crossing detection; 16 on the 50/200-day
windows and cross direction; 20 on cycle-peak detection; 17 on the peak-vs-average
multiples and journey lengths; 23 on the 1× projection. No dependencies.

## Limitations

- Single-user, read-only view. There is no authentication and nothing to
  protect, but do not put it behind anything that assumes otherwise.
- Prices are daily averages from one source, not a consolidated feed.
- The chart loads Chart.js from a CDN; the app is not fully self-hosted.
- No historical storage of the computed average — it is recalculated per
  request (about 6,000 rows, so this is fast).

## Licence

MIT
