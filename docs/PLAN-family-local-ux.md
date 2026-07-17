# PLAN: family local-use polish — single-serve Tailscale + dashboard UX

**Status: approved scope, not built.** Scoped 2026-07-16 with Rob. Two independent workstreams;
execute one at a time, single agent. **Do not start until the in-flight "magic money" session's
work lands and the two finished-but-uncommitted clusters (host-conditional URL pin, PDF
export-all) are checkpointed** — the dashboard blade and docs carry uncommitted changes.

Context: the project focus is **local use by immediate family** planning the current (V2) couple.
Public-release items stay parked.

---

## A. Serve family traffic from Herd's nginx (drop `artisan serve`)

**Goal:** one fewer hand-launched process. After this, only the queue worker needs relaunching
after a reboot (Herd auto-starts; `tailscale serve` config persists).

**Why it works:** `artisan serve` exists only because Herd/Valet's catch-all routes by `Host`
header and doesn't know the `*.ts.net` name. Tailscale Serve preserves the original `*.ts.net`
Host when proxying to loopback (verified 2026-07-16, DECISIONS) — and the URL pin is now
host-conditional, so the app *needs* that Host to arrive intact.

**Change (no app code):** add a second `server` block to the existing per-site custom conf
`~/.config/herd/config/valet/Nginx/retireforecast.test.conf` (the 300s-timeout one — machine
config, not in the repo):

- `listen 127.0.0.1:8000; server_name _;` — catch-all, so the `*.ts.net` Host passes through
  to Laravel untouched.
- Bypass Valet's host-routing `server.php` entirely: `root C:/Dev/RetireForecast/public;`,
  standard Laravel `location / { try_files $uri $uri/ /index.php?$query_string; }`, PHP via the
  same `fastcgi_pass $herd_sock` with `SCRIPT_FILENAME $realpath_root$fastcgi_script_name`
  (or the resolved index.php), and the same 300s `fastcgi_read_timeout`/`send_timeout` as the
  existing block (assistant generations).
- Tailscale side unchanged: `tailscale serve --bg 8000` now hits nginx. TLS termination,
  `X-Forwarded-Proto`, loopback `trustProxies`, and the host-conditional pin all behave
  identically to the `artisan serve` setup.

**Verify:** from another tailnet device, the `https://<name>.ts.net` URL returns 200 with styled
assets and login stays on the ts.net origin; `retireforecast.test` locally unaffected; restart
Herd and confirm the block loads. Risk low — additive block, delete-to-revert.

**Docs after build:** update HANDOVER "Share with family" (drop the `artisan serve` step);
DECISIONS entry superseding the 2026-07-12 "serve Host-agnostically via artisan serve" rationale.

---

## B. Dashboard UX: clickable cards, real buttons, delete, hide

Files: `app/Livewire/Dashboard.php`, `resources/views/livewire/dashboard.blade.php`,
one migration, `tests/Feature/Livewire/DashboardTest.php`. Ownership guard everywhere is the
codebase standard: `abort_unless($scenario->user_id === auth()->id(), 403)`.

### B1. Whole card opens results
The name is already an `<a>` to `scenarios.results` but doesn't read as clickable and the rest
of the card is inert. Stretched-link pattern: card `<li>` gets `relative`; the name anchor gets
`after:absolute after:inset-0`; the action buttons get `relative z-10` so they sit above the
stretched hit-area. Same treatment on child what-if rows. One anchor = one accessible name; no JS.

### B2. Obvious buttons, not hyperlinks
Replace the text links with button-styled anchors reusing the existing button classes:
- **Results** — solid blue primary (matches "New forecast").
- **Edit** — bordered secondary.
- **Create what-if** / **Compare** — secondary.
- Child rows: small Edit (and Results) buttons.

### B3. Delete (base + child)
DB is already fully wired — `parent_scenario_id`, `simulation_runs`→`results`,
`threshold_results`, `assistant_turns` are all `cascadeOnDelete`, so `$scenario->delete()` is
complete. Build:
- Livewire action `delete(Scenario $scenario)` on Dashboard + ownership guard.
- `wire:confirm` confirmation; the base-scenario message **must warn its what-ifs are deleted
  too** (cascade). Child rows get their own delete.
- Styled as a quiet red action, visually separated from Results/Edit (no fat-finger).
- Flash `session('status')` on completion (the dashboard already renders it).

### B4. Hide (base + child)
- Migration: nullable `hidden_at` timestamp on `scenarios`; add to `casts`.
- Livewire action `toggleHidden(Scenario)` + ownership guard; button label flips Hide/Show.
- Ordering: `->orderByRaw('(hidden_at IS NOT NULL)')` before `latest()` — visible first, hidden
  sink to the bottom (valid on both Postgres and the SQLite test DB). Children likewise within
  their base.
- Hidden rendering: greyed (`opacity-60`, muted text) + a "hidden" chip. Hiding a base greys the
  whole card including children; a child can be hidden individually.
- **Locked decision (Rob, 2026-07-16): hidden scenarios are EXCLUDED from "Export all to PDF"**
  (`ScenarioPdfController::reports()` gains `whereNull('hidden_at')` on both bases and the
  children eager-load). Compare is untouched. Single-scenario PDF download of a hidden scenario
  still works (explicit ask). → gets a DECISIONS entry when built.

### Tests (extend DashboardTest + ScenarioPdfTest)
- Delete: owner deletes; foreign scenario 403; base delete removes children + runs.
- Hide: toggle round-trip; hidden sorts last; foreign scenario 403.
- PDF: export-all excludes hidden bases and hidden children; single download of a hidden
  scenario still 200s.
- Keep the whole suite green (invariant).

**Docs after build:** HANDOVER one-liners; DECISIONS entry for the hidden-vs-PDF rule.
