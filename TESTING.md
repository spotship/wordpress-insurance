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
| PHPUnit integration | 26 tests × PHP {8.1,8.3} × WP {6.4,latest} | pass |
| jsdom (`npm run test:js`) | 23 tests | pass, every guard mutation-checked |
| Playwright + axe | 15 specs × desktop + narrow | pass |
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

**Internal documentation in the release zip.** A staging dry-run of
`bin/build-dist.sh` showed `.claude/`, `CLAUDE.md`, `TESTING.md`,
`renovate.json`, `.coderabbit.yml`, `playwright.config.js` and
`phpunit-integration.xml.dist` all reaching an artifact that is unpacked
verbatim onto a customer's web server.

**Four harness faults**, which is the expected cost of writing tests blind and
not a surprise: a property typed `array` that holds a `WP_Error`; an assertion
pinning key *order* rather than the key set; a locator matching both location
comboboxes on hull; and `(string) wp_parse_url( $url )['scheme'] ?? ''`, where
the `??` guards the cast instead of the subscript.

## Proving a test can fail

**A passing test proves nothing until it has been shown to fail.** Every jsdom
guard here has been mutation-checked against a deliberately broken source. Two
of those exercises found real gaps that reading had not:

| Mutation | Outcome |
|---|---|
| Diverge the *destinations* listbox id scheme | **Survived** — only the ordinary combobox was covered. Test added. |
| Route cargo to the hull response shape | **Survived** — the test checked the key-building functions, not which one the route reached. Routing extracted into a pure function and covered. |

Both are the same shape as the original defects: a fix applied to one call site
while an identical site elsewhere keeps the bug.

Mutations that correctly failed: a second `hidden.value` writer; a listbox
deriving its own option ids; a second `submissionId` mint site; dropping
`submissionId` regeneration; `brokenLookups` collapsed to a boolean; a bound
removed from `Renderer::config()`; a key dropped from the cargo fixture; a new
translatable string absent from the POT; the disclaimers left unrendered.

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

`tests/fixtures/backend-201-keys.json` is generated by
`bin/derive-backend-keys.sh` from the backend serializer — hull 16 keys, cargo
20, neither `id` nor `status`.

This matters more than it looks. A previous implementation hand-wrote a response
shape the API never emits; that fixture shaped the relay test, which pinned the
wrong contract; and the anti-bot decoy was then built to imitate the fixture.
The suite was green over all three layers. Deriving the shape is the control
that stops it recurring.

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
