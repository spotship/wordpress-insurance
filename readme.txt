=== Spot Ship War Insurance ===
Contributors: spotship
Tags: insurance, shipping, maritime, quotes
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.1-dev.1
License: Proprietary

War hull and war cargo insurance quote capture for Spot Ship customers. The API
credential never reaches the browser.

== Description ==

Adds two forms to your site — war hull and war cargo — that capture insurance
quote requests and send them to Spot Ship's insurance desk. Visitors do not need
an account.

Both forms are available as shortcodes and as blocks:

* `[spotship_war_hull]`
* `[spotship_war_cargo]`

An indicative rate table is served from Spot Ship and rendered above the form,
always showing the date the rates were published.

= Privacy and cookies =

**This plugin sets no cookies and stores nothing on the visitor's device.** State
is held in server-side transients, and form integrity uses a hidden-field HMAC
rather than a WordPress nonce. Under PECR/ePrivacy it therefore requires no
consent banner, and "necessary/functional" is the accurate classification if
your consent manager lists it at all.

Submitted quote requests are sent to Spot Ship. The consent checkbox text, the
time consent was given and a link to your privacy notice are recorded with each
submission, so configure the privacy notice URL in settings.

= Requirements =

* A Spot Ship customer API key
* Outbound HTTPS from your web server

== Installation ==

1. Upload and activate the plugin.
2. Go to Settings -> Spot Ship War Insurance.
3. Enter the API base URL and your API key, then use "Test connection".
4. Set your privacy notice URL.
5. Add `[spotship_war_hull]` or `[spotship_war_cargo]` to a page.

Enabling automatic updates for this plugin is recommended: it runs on your
server against an API we version, and auto-update is the simplest way to avoid
version skew.

The API key is stored in your WordPress database. To keep it out of the
database entirely, define it in `wp-config.php` instead:

`define( 'SPOTSHIP_WI_API_KEY', '...' );`

The settings field then becomes read-only.

== Frequently Asked Questions ==

= The rate table is not showing =

The form still works without it. The table hides when Spot Ship cannot be
reached and no cached copy is available, or when the API key is not valid for
this site. Settings -> Spot Ship War Insurance shows which is the case.

= I copied the site to staging and submissions stopped =

That is deliberate. The plugin binds to the site URL it was configured on, so a
clone carrying a copy of the key cannot submit. An admin notice offers one-click
re-binding on the site you intend to use.

= Who do I contact if something is wrong? =

Spot Ship, through the contact you were given with your API key. The address is
also recorded in Settings -> Spot Ship War Insurance, under **Support email**,
and the site administrator who set the plugin up will have it.

That same settings screen has a **Copy diagnostics** button. Send its output
with any report: it contains the plugin, PHP and WordPress versions, which cache
tier is currently serving the rate table and when that table was published, the
last few upstream errors with their status codes, and the result of an
outbound-HTTPS test. Your API key is masked in it, and no visitor data is
included.

Note that this address is also shown to your visitors: when the form cannot be
served at all, the page offers it so a would-be enquirer is not left with a dead
end. Set it to something that can field that, not to a developer's inbox. A
submitted enquiry does not use it — those reach the Spot Ship insurance desk
directly and are answered from there.

== Changelog ==

= 1.0.1-dev.1 =

Bug Fixes
* **release:** ENG-6582 write and assert every shipped version string

= 1.0.0 =
* First production release.
* War hull and war cargo quote forms, as shortcodes and as blocks.
* Indicative rate table served from Spot Ship, with a bundled fallback used when
  the endpoint cannot be reached.
* Submissions proxied server-side; the API credential never reaches the browser.
* Binds to the site URL it was configured on, so a cloned staging copy cannot
  submit.
* Settings screen with a connection test and copyable diagnostics.

= 0.1.0 =
* Initial release.
