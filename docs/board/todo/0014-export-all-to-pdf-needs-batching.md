# Export-all-to-PDF will need batching past roughly 20 to 30 scenarios

## Why
Found 2026-07-30, not blocking. Now the report is complete, 14 scenarios measured 236 landscape
pages, 2.3 MB, ~38s and ~538 MB peak. That is fine against Herd's 1512M limit and the 300s
gateway timeout, but memory grows with scenario count and the per-scenario page count has since
risen to ~27. Single-scenario download is ~27 pages and ~1.3s with headroom.

## Not this card
Single-scenario export, which has plenty of headroom.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a user exports more scenarios than fit in one pass, THE APP SHALL produce the
      export via a queued or batched job rather than one request.
- [x] #2 THE APP SHALL complete a 30-scenario export without exceeding the memory limit or the
      gateway timeout.
<!-- AC:END -->

## Tasks
- [x] Measure again at 20 and 30 scenarios to find the real cliff
- [x] Queue or batch the export above the threshold

## Direction
**2026-08-22** Measured first, then batched.

Measured on this machine, one request against a batched build, using the rich test fixture:

| forecasts | one request | batched |
|-----------|-------------------|-----------------|
| 5 | 8.5s / 274 MB | |
| 10 | 25.6s / 444 MB | 15.3s / 200 MB |
| 20 | 78.9s / 774 MB | 30.6s / 180 MB |
| 30 | 171.8s / 1,114 MB | 45.7s / 200 MB |

The real cliff is **around 35 to 40 forecasts**, and time reaches it at about the same point as
memory: a single request's memory grows roughly linearly with the count while its time grows
faster than linearly (dompdf's cost per page rises with document size), so 30 forecasts already
spend 172s of the 300s gateway budget and 1,114 MB of the 1512M limit. Batched, both go flat:
peak memory is one report's whatever the count, and the total is three times faster at 30.

Built: past **eight** forecasts, `GET /scenarios/pdf` queues `App\Jobs\BuildScenarioExport`
instead of rendering, and returns to the dashboard. `App\Export\ScenarioExport` renders each
forecast as its own complete report and adds it to a zip, reporting building / ready /
failed-with-reason plus a per-forecast progress count, which the dashboard polls and shows.
`App\Export\ScenarioReport` (the report assembly, moved out of the controller) is shared with the
direct download, so a queued export prints exactly what a direct one does. At or below eight the
direct single-PDF download is unchanged. Rationale and the figures are in DECISIONS 2026-08-22.

Assumed, and worth a look:
- **The batched export is a zip of one PDF per forecast, not one merged PDF.** One file can only
  come from one render, which is the thing that does not scale, and nothing installed here can
  merge pre-rendered PDFs. If Rob wants a single file above the threshold, that is a new
  dependency (FPDI or similar), not a redesign. The zip is also bigger than the merged PDF (about
  35 MB for 30, against 4 MB) because each report re-embeds its own fonts and charts.
- **The threshold of eight is set well inside the cliff, not at it.** The measurements above use
  the test fixture; the card's earlier real-data figure (14 forecasts, 538 MB) is about 1.3x
  heavier per forecast, and eight leaves room for that.
- **A queue worker must be running**, as for Monte Carlo runs. On the `sync` driver the build
  would run inside the request and defeat the point.
- The archive stays on disk until the next export replaces it or the account is erased (erase now
  deletes it, since no foreign key reaches a file). Its status expires after 24 hours, after which
  the dashboard offers a rebuild rather than a stale download.

Could not settle here:
- **The dashboard panel has not been looked at in a browser.** This ran in a worktree, which Herd
  does not serve, so the progress panel, its polling and the zip download are proven by tests
  only. Add it to the 0001 browser sign-off.
- The card asked for `.\vendor\bin\pest.bat`; this project has no Pest. The suite is PHPUnit, run
  with `php artisan test` (`vendor/bin/phpunit.bat` also works). Pint ran clean.

### 2026-08-29 review (v20260829174938-0883)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 206s, run by this job rather than reported by the card.

**acceptance: sound**

Both criteria trace to real code. I tried to break each one.

**AC#1 ÔÇö queued past one pass.** `ScenarioPdfController::downloadAll` asks `ScenarioExport::fitsOneRequest` (threshold `ScenarioExport::BATCH_ABOVE`, 8). Above it, `ScenarioExport::queue` dispatches `BuildScenarioExport`, and the request returns a redirect, so nothing renders in it. `BuildScenarioExport::handle` calls `ScenarioExport::build`, which renders one report per forecast into a zip. Real, not decorative.

**AC#2 ÔÇö 30 without hitting memory or gateway.** `ScenarioExport::build` loops one scenario at a time. The flat-memory claim rests on `ScenarioExport::render` getting a fresh renderer each call; I checked the vendor code, and `Barryvdh\DomPDF\Facade\Pdf::__callStatic` does bypass the cached facade instance, so peak cost is one report, not thirty. `BuildScenarioExport::$timeout` is 3600, so the worker does not cut a long build off. The gateway never sees a render: `downloadAll` redirects and `ScenarioExport::download` streams the file from disk.

Attempts to break it that failed: `ScenarioReport::scenarios` ends with `values()`, so the loop keys are 0..n-1 and neither `ScenarioExport::entryName` nor the progress count can skew. A dead worker lands terminal via `BuildScenarioExport::failed` ÔåÆ `ScenarioExport::fail`.

VERDICT: sound

**scope: defect**

**Fence: held.** `ScenarioPdfController::download()` is untouched, and the report body moved into `App\Export\ScenarioReport::data()` byte-for-byte identical to the old controller method. Single-scenario export did not change.

**Left half done ÔÇö retention.** `ScenarioExport::clear()` is called from one place only, `GdprService::erase()`. No schedule, no expiry sweep. The zip (~35 MB for 30 forecasts, a full copy of the household's figures) sits on the local disk forever. The Direction says the status expires after 24 hours "after which the dashboard offers a rebuild rather than a stale download", but `ScenarioPdfController::downloadArchive()` checks only `ScenarioExport::exists()` ÔÇö never the state or the age. A bookmarked `/scenarios/pdf/archive` serves a months-old archive, or the previous archive after a build failed. Only half of the stated policy is built.

**Left half done ÔÇö a stuck build.** In `ScenarioPdfController::downloadAll()`, a state of `building` skips the queue but still tells the user "Building your export ÔÇª in the background". With no worker running (see card 0009), nothing is queued, the panel polls forever, and the user cannot retry for 24 hours. There is no cancel and no rebuild path.

VERDICT: defect

**breakage: defect**

**1. Job timeout outruns the queue's `retry_after`.**
`App\Jobs\BuildScenarioExport::$timeout` is 3600, but `config/queue.php` sets the `database` connection's `retry_after` to 90 (no `DB_QUEUE_RETRY_AFTER` in `.env`). Laravel's own rule is that `retry_after` must exceed the longest job. The app's own copy in `resources/views/livewire/dashboard.blade.php` says a big export "takes a few minutes", so this job crosses 90s by design. Failure: with a second worker running, the row is re-reserved at 90s; attempts 2 beats `$tries = 1`, so `failed()` fires and `ScenarioExport::fail()` tells the user the export broke while the first process is still writing the same zip. Both then race on one path.

**2. The memory claim in the code is not true.**
`App\Export\ScenarioExport` class docblock, and `build()`, say peak memory "is the cost of the BIGGEST SINGLE report" and "stops growing with the count". `ZipArchive::addFromString` keeps every added string in memory until `close()`, so the loop retains the sum of all rendered PDFs (~1.2 MB each; the Direction's own 35 MB zip at 30). It grows linearly, just with a smaller slope.

VERDICT: defect

