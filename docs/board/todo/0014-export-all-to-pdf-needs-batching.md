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
- [ ] #1 WHEN a user exports more scenarios than fit in one pass, THE APP SHALL produce the
      export via a queued or batched job rather than one request.
- [ ] #2 THE APP SHALL complete a 30-scenario export without exceeding the memory limit or the
      gateway timeout.
<!-- AC:END -->

## Tasks
- [ ] Measure again at 20 and 30 scenarios to find the real cliff
- [ ] Queue or batch the export above the threshold
