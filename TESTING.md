# Testing

What has been proven, where it was proven, and how to tell the difference.

## Where the gates run

**CI is the runtime.** This machine has no host PHP and no reachable Docker
daemon, and Playwright's runner will not start on it (ENG-6418). None of that
is a gap in coverage — it is a gap in *local* tooling. Every gate runs on a
GitHub Actions runner, in about four minutes, and that is where to read the
result.

The one suite that also runs anywhere is `npm run test:js`. Keep it that way:
it must not acquire a browser or a Docker dependency.

## Status

| Gate | Scope | Result |
|---|---|---|
| PHPCS (WPCS + PHPCompatibilityWP) | whole tree | clean |
| PHPStan level 6 | plugin + templates + uninstall | clean |
| PHPUnit unit | 38 tests × PHP 8.1 / 8.2 / 8.3 | pass |
| PHPUnit integration | 47 methods × PHP {8.1,8.2,8.3} × WP {6.4,latest} | pass |
| jsdom (`npm run test:js`) | 36 tests | pass, every guard mutation-checked |
| Playwright + axe | desktop + narrow projects, incl. the block editor | pass |
| Plugin Check (WP.org scanner) | built plugin | pass |
| Translation catalogue freshness | regenerate + diff | pass |
| Version header vs constant | `bin/assert-version.sh` | pass |

axe runs with **no rules disabled**, on both products, including with the
listbox open, at 320px and at 200% zoom.

## What the first real run found

These suites were written with no runtime available, so their first execution
was the interesting one. It is worth recording what that bought, because the
argument for writing tests you cannot yet run rests on it.

**A production bug, in the code a comment defended.** `add_query_arg()` does
not encode the values it is given — it runs `urlencode_deep()` only over params
parsed from an existing query string, and merges new ones in afterwards. A
location search for "New York" therefore left as `?query=New York`, a literal
space in the request line. The comment above it asserted the opposite and
warned against pre-encoding, so the defect had been argued for in prose and
shipped. `test_a_search_term_with_a_space_is_encoded_exactly_once` is the test
written for exactly this, and it caught it on first execution.

**Implicit coupling between templates.** The partials guarded their optional
inputs with `isset()`, and the guards never fired: values survived from the
previous `require` in the same scope, so `fromPort` and `toPort` were rendering
`vessel`'s hint and `increasedValue` was inheriting `handmValue`'s type and
autocomplete. It happened to be what each field wanted. The next field added
would have inherited whatever sat above it. Every call site now sets every
variable its partial reads.

**Internal documentation in the release zip.** A dry run of
`bin/build-dist.sh` showed `.claude/`, `CLAUDE.md`, `TESTING.md`,
`renovate.json`, `.coderabbit.yml`, `playwright.config.js` and
`phpunit-integration.xml.dist` all reaching an artifact that is unpacked
verbatim onto a customer's web server.

**A WCAG 2.1 A defect only the 320px project could see.** `overflow-x: auto`
sat on `.sswi-rates`, so at narrow widths it became a scroll container that
could not be focused — pointer-only, with the columns past the fold unreachable
by keyboard. The desktop project was green on identical markup. This is the
argument for running the narrow viewport as its own project rather than as a
resize inside one spec.

**A test environment that could not reach its own stand-in.** Three browser
specs failed on a missing `aria-activedescendant`, which reads like a combobox
bug and was not one: `wp_http_validate_url()` — which `wp_safe_remote_get()`
runs — rejects any port outside { 80, 443, 8080 } before it checks anything
else, and the stand-in was on 9911. The job now proves reachability with the
same call the API client makes, before the browser starts, so this fails with
`UNREACHABLE` instead of three misleading front-end failures.

**Four harness faults**, which is the expected cost of writing tests blind and
not a surprise: a property typed `array` that holds a `WP_Error`; an assertion
pinning key *order* rather than the key set; a locator matching both location
comboboxes on hull; and `(string) wp_parse_url( $url )['scheme'] ?? ''`, where
the `??` guards the cast instead of the subscript.

## What the first review found

A Copilot review on PR #1 raised thirteen threads. Most were real, and pulling
on one of them found something larger than the thread described.

**The rate table was built against a payload the API has never emitted.** The
review noticed only that a single global column list cannot serve two products.
Reading `backend/insurance/rates.py` showed the whole shape was invented:
`GET /api/insurance/rates/` returns
`{ updatedAt, hull: { columns, zones, disclaimers, footnotes }, cargo: {…} }`,
with each column an object of `{ key, label }`, each row keyed `name`, and each
rate a **JSON number**. The template read a top-level `columns` list of plain
strings, treated `$rates[$product]` as the row list, keyed rows `zone`, and
echoed the rate verbatim.

Against the real backend that renders a table with no header cells and four
empty body rows — one per key of the product object — no disclaimers, and, had
the rows resolved, `0.085` where `8.50%` belongs. The disclaimers are the part
that matters most: the partial's own comment calls omitting them a compliance
failure, and they were omitted on every install.

Three separate controls should have caught it and none did:

- `bin/e2e-upstream.js` **reproduced the invented shape**, so the browser suite
  asserted a contract that does not exist.
- The one browser assertion over the table was wrapped in
  `if ( await rates.count() )`, so it did nothing whenever the table was absent
   — the single case it existed for.
- `tests/js/contract.test.js` had a disclaimers test that **required the
  top-level read**, pinning the very shape that made them disappear.

This is the same failure `bin/derive-backend-keys.sh` was written to prevent for
the 201 body, one endpoint over, so it now has the same control:
`bin/derive-backend-rates.sh`.

**The same wrong contract had a second consumer.** `populateRiskZones()` read
`rates.cargo` as a row list and `row.zone` as the label. It is an object, so
`.forEach` threw, the promise rejected into a deliberately-silent catch, and
the **required** risk-zone select stayed at its placeholder — not a degraded
cargo form but one nobody can submit. Its test passed throughout, because it
hand-wrote the shape the script expected. Every jsdom rates stub now reads the
derived fixture.

**Neither block appeared in the editor.** `register_block_type()` in PHP makes
a block renderable; the inserter is built from the client-side registry, and
nothing called `registerBlockType()`. Half of what the ticket asks for did not
work. Nothing caught it because no spec had ever opened wp-admin's editor — a
block you cannot insert is invisible to a front-end test.

**The browser suite was exceeding its own rate limit.** Every spec runs twice,
once per Playwright project, from one runner IP against five submits an hour,
so the second project got a 429 on every submit. It had been happening all
along and no assertion was strong enough to see it: *"Too many requests. Please
wait 3488 seconds and try again."* satisfies `not /unavailable/` perfectly well.

**`rate_limited` was a state nothing could ever produce.** It was declared,
listed in `STATE_CODES`, and written by neither path that should have written
it: `normalise_code()` sent everything it did not recognise to `unavailable`,
and `throttled` is not a `STATE_*` constant; while the local throttle answered
429 with no upstream call, so it never reached `record()` at all. The
diagnostics blob is what an administrator sends to support, so "unavailable"
sent that person hunting a network fault when the remedy was to wait or raise a
ceiling — and an install turning its own visitors away looked perfectly
healthy. Recording the local throttle also required building the dedup the
class docblock had always *claimed* ("the cap and the dedup"): the cap was
real, the dedup was not, and without it twenty identical rows would evict every
other thing that had happened.

The other confirmed findings: the per-IP limiter counting in PHP rather than in
the database, so concurrency beat the limit it exists to impose; a fetch-lock
release that was not ownership-aware, so a slow request could delete the lock of
the request that took over from it; Cloudflare's IPv6 edges not being
recognised, collapsing every IPv6 visitor behind one into a single rate-limit
bucket; `uninstall.php` sweeping only the current site on multisite, leaving a
live API key in every other site's options; `bin/assert-version.sh` never
comparing the plugin version to the tag being released; and the notes textarea
missing `data-sswi-field` in **both** templates, so a notes error degraded to
"temporarily unavailable".

## What the first real-backend run found

The suite that talks to the live dev deployment had never executed. It has now,
and the first thing it found was not in this plugin.

**Dev's insurance endpoints are failing, and the picture moved between two
runs twenty minutes apart.**

```
00:08   rates 500   location_search 500            vessel_search 500
00:23   rates 200   location_search 200 (5 hits)   vessel_search 500
00:38   rates 500   location_search 500            vessel_search 500
```

**It is flapping, not down.** Any single reading would have produced a
confident and wrong conclusion — "the deployment is down", then "only the
vessel index is broken", then "the deployment is down" again — and the middle
one proves the data is there, because `location_search` returned five real
Rotterdam hits.

A later run made it ten attempts over three and a half minutes and never found
a healthy window — all 500 — so the flap is wide.

The body fingerprint on those 500s says **Django debug traceback (DEBUG on)**,
and that is the more serious finding. An anonymous request to a nonexistent
path on the dev host returns Django's technical 404 page, which is served only
under `DEBUG = True`, so the 500s are technical 500 pages carrying a traceback
with local variables — on an internet-facing host, to an authenticated API
caller. The probe deliberately fingerprints rather than prints those bodies for
exactly this reason.

It is a backend problem, not a plugin one, and not fixable from this
repository. The flapping is also the reason the probe PRINTS rather than
concludes: a single observation of a shared deployment is a snapshot, and these
aged out in fifteen minutes.

Two things changed here as a result, because the first run could not say any of
that:

- The job now **probes the endpoints before the browser starts** and prints
  status and result counts — never the payload, which is commercial figures and
  customer records, and never the key. On a 5xx it extracts only the exception
  title: with `DEBUG` on, a Django error page carries settings and local
  variables, and a public job log is the wrong place for them.
- It then **fails at the probe** rather than running the suite — on a 5xx from
  ANY of the three, not just the canary. Against a broken endpoint every spec
  fails describing a symptom: "the real backend returned no locations for
  rotterdam", or "the real vessel search returned nothing for 'ever'", which
  reads as missing data and is a 500. One accurate failure beats ten misleading
  ones.

## Proving a test can fail

**A passing test proves nothing until it has been shown to fail.** Every jsdom
guard here has been mutation-checked against a deliberately broken source. Two
of those exercises found real gaps that reading had not:

| Mutation | Outcome |
|---|---|
| Diverge the *destinations* listbox id scheme | **Survived** — only the ordinary combobox was covered. Test added. |
| Route cargo to the hull response shape | **Survived** — the test checked the key-building functions, not which one the route reached. Routing extracted into a pure function and covered. |
| Drop `data-sswi-field` from the notes textarea | **Survived** — the new test matched inputs from `<tag … name="x"`, but every one of these tags carries `<?php echo … ?>` for its id, so `[^>]*` stopped at the `>` inside the PHP block and the test collected nothing. Regex fixed, and it now asserts it matched something. |

All three are the same shape as the original defects: a fix applied to one call
site while an identical site elsewhere keeps the bug — and the third is the
harness catching a test written in this very round that detected nothing.

Mutations that correctly failed: a second `hidden.value` writer; a listbox
deriving its own option ids; a second `submissionId` mint site; dropping
`submissionId` regeneration; `brokenLookups` collapsed to a boolean; a bound
removed from `Renderer::config()`; a key dropped from the cargo fixture; a new
translatable string absent from the POT; the disclaimers left unrendered; the
rate table reading a top-level `columns` list; a rate published as the raw
fraction; a real `null` rate rendering as an empty cell; the cargo risk zones
read as a row list, and labelled by `zone` rather than `name`; the fetch lock
released without an ownership check; Cloudflare's IPv6 edges going
unrecognised; and the IPv6 prefix compared whole bytes only, which trusts eight
times the published `/29`.

The limiter's atomicity is the one claim no mutation can carry, because a
PHPUnit process cannot issue two simultaneous requests. It was verified against
MySQL 8 directly instead: 40 concurrent connections against a limit of 5 gave
exactly 5 allowed, 35 refused, final count 5. The tests pin what the race
*produces* — a caller deciding from a count it read earlier — rather than its
timing.

**When adding a test, break the thing it guards and watch it go red.** If it
stays green, the test is documentation, not a guard.

## Structural invariant tests

Three single-owner rules were stated in docblocks and drifted anyway — one of
them *after* being written down. They are enforced by tests that read the
source and count:

- exactly one assignment to `hidden.value`
- every `aria-activedescendant` derived by `optionId()`
- exactly one `submissionId` mint site

Counting source occurrences is crude. It is also the only check that fails when
somebody adds a second writer in a new function that is perfectly correct on
its own, which is how all three drifted.

The same reasoning covers the release artifact: `.distignore` is a blocklist and
fails open, so `bin/build-dist.sh` checks the built zip against an allow-list of
what may appear and fails the release on anything else.

## Fixtures must be derived, never written

Two fixtures, both generated from the backend source, neither hand-editable:

| Fixture | Script | Content |
|---|---|---|
| `tests/fixtures/backend-201-keys.json` | `bin/derive-backend-keys.sh` | the keys a genuine 201 carries — hull 16, cargo 20, neither `id` nor `status` |
| `tests/fixtures/backend-rates.json` | `bin/derive-backend-rates.sh` | the `/api/insurance/rates/` body — 15 hull zones, 6 cargo, columns as `{key,label}`, rates as numbers |

This matters more than it looks, and it has now cost twice.

A previous implementation hand-wrote a **201 response** shape the API never
emits; that fixture shaped the relay test, which pinned the wrong contract; and
the anti-bot decoy was then built to imitate the fixture. The suite was green
over all three layers.

The **rates body** was hand-written too, and went further: the invented shape
reached the renderer, the stand-in upstream, the browser suite and a contract
test that enforced it, and the rate table did not work against the real backend
at all. See "What the first review found".

Deriving the shape is the control that stops it recurring. Both fixtures are
regenerated against `origin/dev`:

```sh
BACKEND=~/spotship/otto/backend bash bin/derive-backend-keys.sh
BACKEND=~/spotship/otto/backend bash bin/derive-backend-rates.sh
```

## Running things

```sh
task test:js           # jsdom — no browser, no Docker. Runs anywhere.
task lint              # PHPCS
task stan              # PHPStan level 6
task test:unit         # PHPUnit unit
task test:integration  # PHPUnit integration, inside wp-env
npx playwright test    # will not start locally — ENG-6418; read CI instead
```

All of these need Docker locally. When it is unavailable, push the branch and
read the job logs — that is the supported path, not a degraded one.
