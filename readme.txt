=== GEO Engine ===
Contributors: geoengine
Tags: geo, llms, llms.txt, schema, json-ld, faq, ai, structured-data
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your site readable by AI: llms.txt, Markdown endpoints, JSON-LD schema, and optional LLM-generated FAQ for every post.

== Description ==

GEO Engine adds the machine-readable layer that AI answer engines look for, without touching your existing content.

**Layer A — machine-readable index (no API key)**

* `/llms.txt` — every published post and page, grouped by post type, no entry limit.
* `/llms-full.txt` — same index with body text, per-entry character cap and total cap.
* `/geo-md/{post_type}/{slug}.md` — a Markdown version of each entry.
* `<link rel="alternate" type="text/markdown">` in the page head.
* `robots.txt` rules for GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot and Bytespider, each switchable between Allow and Disallow.

**Layer B — visible FAQ written by your own LLM (optional)**

* Pick a provider from nine presets or enter any OpenAI-compatible endpoint.
* Two protocol drivers: `openai-chat` and `anthropic-messages`.
* Keys are encrypted with AES-256-CBC using a key derived from your `wp-config.php` salts; the plaintext never reaches the browser.
* Generate one post, on first publish, or the whole archive in resumable batches.
* FAQ output is a native `<details>` accordion, no JavaScript required.

**Layer C — structured data (no API key)**

* `WebSite`, `Organization`, `WebPage`, `Article`/`BlogPosting`, `BreadcrumbList` and `FAQPage` in a single `@graph`.
* Generated at render time straight from post content, so existing posts are covered immediately.
* Yields automatically to Yoast SEO, Rank Math, All in One SEO and SEOPress to avoid duplicate entities; per-type override available.

**Content safety**

FAQ is appended at the end of `post_content` and wrapped in the plugin's own shortcodes. Every other character of the original post is left byte-for-byte untouched. There is no cloaking, no hidden text and no remote configuration.

== Installation ==

1. Upload the plugin ZIP and activate it.
2. Open Settings → Permalinks and click Save Changes once (registers the rewrite rules).
3. Visit `https://example.com/llms.txt` to confirm the index renders.
4. Go to Settings → GEO Engine.

Layer A and Layer C work immediately. Layer B needs an API key from a provider of your choice.

== Frequently Asked Questions ==

= Is an API key required? =

Only for FAQ generation. The llms.txt endpoints and all structured data work without any key.

= Which providers are supported? =

Any OpenAI-compatible endpoint. Presets are included for SiliconFlow, DeepSeek, OpenAI, Moonshot, Zhipu GLM, Alibaba DashScope (compatible mode), Anthropic Claude and a local Ollama instance. Model names are free text, so a provider renaming a model never requires a code change.

= Will this conflict with my SEO plugin? =

No. When Yoast, Rank Math, AIOSEO or SEOPress is active, GEO Engine yields the entity types they already emit and only adds `FAQPage`. Each type can be forced or disabled individually.

= Does it modify my existing posts? =

Only by appending the FAQ shortcode block at the end of the content, and only when you explicitly generate. Nothing else is rewritten. Uninstalling keeps the block unless you opt in to removing it.

= Where is the API key stored? =

Encrypted with AES-256-CBC in `geo_engine_api_key_cipher`. The encryption key is derived at runtime from constants already present in `wp-config.php`; it is never written to the database.

= How much does generation cost? =

Roughly 2,500 characters of body text plus a short prompt per post. Check your provider's pricing and run five sample posts before batching the whole archive.

== Screenshots ==

1. LLM configuration with provider presets and connection test.
2. Bulk generation with resumable progress.
3. Visible FAQ accordion on the front end.

== Changelog ==

= 1.0.6 =
* Fix: retries still hit the gateway timeout and surfaced a 504. The retry endpoint now flushes its JSON response before it starts working (fastcgi_finish_request / litespeed_finish_request), so nginx stops counting while PHP keeps generating. The browser follows the run through the status endpoint.
* Per-article retry timeout is now a setting (20–180s, default 60) instead of a hard-coded 40s.
* A retry slice handles exactly one article, so a slow first item can no longer eat the second one's runway.
* Failure log rows now carry a diagnostic tail: model, prompt size, max_tokens and elapsed time.

= 1.0.5 =
* Fix: "Retry failed items" processed a single slice and reported "retry finished", so with a batch size of 1 you had to click it once per article and the progress bar never moved.
* Retry is now a browser-driven queue like the batch run: one click works through every failed item, the bar shows "retried X / Y", and the log is appended per article.
* Retries get a wider runway (45s per request, 40s per article) because failed items are usually the slow ones.
* Buttons are mutually exclusive: retry, restart and reset are disabled while a run is in flight, and the server rejects the same combinations.

= 1.0.4 =
* Fix: a batch could still die with "network error" halfway through. The 20 second budget was only checked between posts, so a single LLM call could occupy the request for up to 190 seconds (60s timeout x 3 attempts + backoff) and the gateway answered with an HTML error page.
* In batch mode one post is now capped at 30 seconds, transport retries off, JSON repair pass off, and no new post is started with less than 8 seconds of budget left.
* Automatic recovery: after a broken response the admin reads the state back, waits while the server still holds the lock, then continues from the saved cursor (up to 8 consecutive failures).
* Error messages now name the real cause (HTTP 504 / 502 / 503 / 500) instead of a generic "network error", and the console keeps the response snippet.
* Lock is refreshed per post, TTL lowered to 90 seconds, and "continue" clears a stale lock left behind by a killed worker.
* New: "force regenerate" checkbox on the batch screen — already-generated posts are overwritten instead of skipped.

= 1.0.3 =
* Fix: every admin AJAX call failed with "network error". A UTF-8 BOM emitted by a globally loaded PHP file (wp-config.php, a mu-plugin or a wp-content drop-in) was prefixed to the JSON body, and JSON.parse() rejects a leading BOM.
* The admin script now strips a leading BOM before parsing and logs the first 300 characters when a response is not JSON.
* All 9 AJAX handlers discard open output buffers before responding.
* Site-side note: the whole site currently emits a BOM, including robots.txt, whose first line is therefore corrupted.

= 1.0.2 =
* Bulk generation now runs to the end from a single click, with live progress (percent, counts, remaining, per-post timing, ETA).
* Each batch request is capped by a 20 second time budget, so a large batch size can no longer cause a gateway timeout (504).
* The batch delay now happens in the browser instead of `sleep()` on the server.
* Progress survives a page reload: a run in flight is picked up automatically, a stale one asks for a manual resume.
* New `geo_engine_bulk_status` endpoint for live polling and `geo_engine_bulk_resume` to fix "continue" doing nothing after "stop".
* Locking prevents two batches from processing the same queue.

= 1.0.1 =
* Fix: Markdown endpoints for non-ASCII slugs returned 404. Slugs are now resolved from REQUEST_URI with decoded candidates and a WP_Query fallback.
* Fix: Markdown list items were emitted as `- - item`.
* Fix: `llms.txt` descriptions leaked page-builder shortcodes.
* Fix: `llms.txt` header now documents the `/geo-md/{post_type}/{slug}.md` pattern.
* Fix: rewrite rules are flushed on upgrade; output buffers are cleared before endpoints respond.

= 1.0.0 =
* Initial release.
* Layer A: /llms.txt, /llms-full.txt, per-post Markdown endpoints, robots rules, alternate link.
* Layer B: provider presets, two protocol drivers, encrypted key storage, connection test, single/auto/bulk generation, visible FAQ accordion.
* Layer C: WebSite, Organization, WebPage, Article, BreadcrumbList, FAQPage with SEO plugin conflict avoidance.
* Settings, log table, config export/import, uninstall policy, i18n template.

== Upgrade Notice ==

= 1.0.0 =
First public release.
