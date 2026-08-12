# RetireForecast

A local-first UK retirement and downsizing forecast tool. It models what happens to a household's
money when they take a pension lump sum, sell a home, buy somewhere cheaper or move into rented,
and whether the money lasts for life.

---

## Please read this before anything else

**This is a personal project, built for one household's own use. It is not a product, it is not
intended for anybody else to use, and it is not being offered as a service.**

- **It is not financial advice.** Nothing it produces is a personal recommendation, and nothing in
  this repository should be treated as guidance about your own money. Pension, drawdown and
  investment advice is a regulated activity in the UK. I am not authorised to give it and this
  tool is not a substitute for somebody who is. If you are making a decision of this size, speak
  to an FCA-authorised adviser.
- **It is deliberately not deployed anywhere public**, and there is no hosted version to try.
- **It is not supported.** No warranty, no issue triage, no promise that it is correct for any
  situation other than the one it was written against.
- **It models one set of circumstances.** England and Wales only, a specific household shape, and
  a frozen snapshot of the tax rules. See the known divergences in
  [docs/DATA-MODEL.md](docs/DATA-MODEL.md) before assuming any figure generalises.

It is public because it is the clearest sample of how I build things, not because it is ready for
anybody to run. If you want the engineering rationale rather than the numbers, start with
[docs/HANDOVER.md](docs/HANDOVER.md) and [docs/DECISIONS.md](docs/DECISIONS.md).

### On the regulatory line specifically

The original and public posture of this project is **education and guidance only, never a personal
recommendation**, recorded in `docs/DECISIONS.md` and enforced by a build-time test that fails if
directive wording reaches the neutral output.

That boundary is **deliberately relaxed in this build** while it remains a private, local tool for
its owner, via `COMPLIANCE_PERSONAL_USE` in `config/compliance.php`. That config key is the single
findable home of the line, and it is documented in place. **It must be set to `false` before this
is ever put in front of anybody else**, at which point the guidance-only posture re-applies in
full. `php artisan compliance:advice-audit` lists every spot that is affected.

If you have cloned this, you have cloned the personal-use build. Assume advice-shaped output is
switched on.

---

## What it does

- **The pension lump-sum tax shock.** The 25% tax-free element, marginal income tax on the
  balance, and the Month-1 emergency-tax overpayment with its reclaim route.
- **Whether the money lasts.** Monte Carlo with stochastic joint-life mortality, sequence-of-returns
  risk, and stochastic house-price, salary and care-cost paths. Reproducible under a fixed seed.
- **Housing decisions compared on identical simulated paths.** Stay put, buy cheaper outright,
  rent, park homes, let-to-let, equity release, and real amortising repayment mortgages.
- **The rest of the picture**, because leaving it out changes the answer: State Pension, stamp duty,
  capital gains and private residence relief, means-tested benefits, inheritance tax, and care costs.

## How it is built

The point of the design is that being approximately right is worthless here.

- **The engine is framework-free**, in a Composer path package under `packages/finance-engine`,
  with no framework dependencies, no I/O and no clock. A test fails the build if it ever reaches
  for the application. The app is a shell around it.
- **Money is integer pence**, never a float. Rates are integer basis points. Ages derive from a
  date of birth and a reference date and are never stored.
- **Tax figures are versioned per tax year**, each carrying its source and the date it was verified.
- **The engine reproduces published HMRC worked examples to the penny**, proven by unit tests,
  and that bar was met before any figure was allowed onto a screen.
- **Unmodelled cases refuse rather than guess.** Scottish income tax throws instead of quietly
  returning an English figure.
- **No invisible figures.** Any default the engine applies is disclosed with its value and the
  reason it applies. `php artisan scenarios:audit` sweeps every stored scenario for correctness
  and correct disclosure, and exits non-zero so it can gate a release.

Laravel 13 on PHP 8.3+, PostgreSQL, Livewire and Filament, PHPUnit. Tests run on in-memory SQLite.

## Running it

```bash
composer install
cp .env.example .env && php artisan key:generate
# configure a local PostgreSQL connection in .env, then:
php artisan migrate
npm install && npm run build
php artisan serve
```

No sample household ships with it, and no real data is in this repository. Any first-run example is
obviously synthetic.

## Documentation

| Doc | What it holds |
|---|---|
| [docs/HANDOVER.md](docs/HANDOVER.md) | Current state, architecture, and how to pick it up |
| [docs/PRD.md](docs/PRD.md) | Purpose, goals, success criteria |
| [docs/DATA-MODEL.md](docs/DATA-MODEL.md) | The data shape, conventions, and known divergences |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Append-only decision log with rationale |
| [docs/build/PLAN.md](docs/build/PLAN.md) | The full approved plan, UK rule set and phasing |

## Licence

No licence is granted. This is published as a work sample, not as software for reuse. All rights
reserved.
