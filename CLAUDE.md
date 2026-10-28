# CLAUDE.md

Guidance for Claude Code working in this repository.

## What this is

A WordPress plugin that puts Spot Ship's war hull and war cargo quote capture on
customers' own public websites. The PHP layer proxies server-side to the
`backend/insurance` Django app using the customer's `Customer.api_key`.

**The browser never holds the credential.** This is non-negotiable.

## The one thing to read first

`includes/class-rest-proxy.php`'s class docblock. The ordering of branches in
`handle_submit()` is a security property:

> A flagged client's answer must be the answer the SAME payload would have
> received had the client not been flagged — for EVERY refusal this plugin can
> decide locally.

Any locally-decidable refusal placed below the anti-bot decoy gates becomes a
one-request detection oracle. This has been broken three times, each in a branch
the previous review had not examined. `tests/integration/RestProxyTest.php`
enforces it with a table-driven parity test; add a row rather than trusting the
comment.

## Toolchain

There is **no host PHP requirement**. `Taskfile.yml` and `bin/phpcs-hook.sh`
both fall back to `docker run php:8.1-cli`, so a fresh machine needs only Docker.

```sh
task init              # composer + npm + pre-commit hooks
task lint              # PHPCS (WPCS + PHPCompatibilityWP)
task stan              # PHPStan level 6
task test:unit         # PHPUnit, Brain Monkey, no WordPress
task test:integration  # PHPUnit inside wp-env
task test:js           # jsdom — no browser, no Docker
```

`task test:js` is the suite that runs anywhere. It drives the real
`assets/js/war-insurance.js` through real DOM events and has caught bugs
Playwright never executed.

## Conventions that will trip you up

- **Tabs**, including in JS and CSS — WPCS wants them. Do not run BSD
  `unexpand -t` over these files; it implies `-a` and rewrites interior
  alignment, mangling the plugin header.
- **No WP nonces on the public proxy routes.** A nonce baked into
  Cloudflare-cached HTML goes stale and every submit 403s. The admin screens DO
  use nonces — different request, different reasoning.
- **The version lives in four shipped places** — the `Version:` header, the
  `SPOTSHIP_WI_VERSION` constant, readme.txt's `Stable tag` and the catalogue's
  `Project-Id-Version`. `bin/set-version.sh` writes all four,
  `bin/assert-version.sh` asserts them, and anything it writes must also be in
  the `@semantic-release/git` assets list in package.json or the release commit
  drops it. Do not edit any of them by hand.
- **`bin/build-dist.sh` must stay non-mutating.** An earlier version ran
  `composer install --no-dev` in place and destroyed the working vendor/.
- **Never invent a rate figure.** `includes/data/rates-fallback.php` ships empty
  and is generated at release time from the live endpoint.

## Known gaps

- **ENG-6383** — the rate values have never been confirmed against the
  commercial Google Sheet.

## The browser suite does run here

ENG-6418 recorded the Playwright and axe suites as unrunnable on a development
machine. That was the wrong conclusion from a real symptom: Chromium cannot
register a Mach bootstrap port inside an agent's Seatbelt sandbox, so it dies
before the first page and cannot be reaped, which presents as a hang. Outside
that sandbox the suite is green in about two minutes.

`TESTING.md` → "The browser suite" has the four commands and the three things
that bite: port 8080 is the only one `wp_http_validate_url()` accepts, a *name*
counts as routable so `host.docker.internal` is refused, and `wp-env start` can
report success having installed no WordPress at all — check
`wp core is-installed`, never the exit code.
