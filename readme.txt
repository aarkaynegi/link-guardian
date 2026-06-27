=== Link Guardian ===
Contributors: aarkaynegi
Tags: redirects, broken links, internal links, slug, 301
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Change a slug and Link Guardian auto-creates the 301 redirect and rewrites the old internal links across your content. No more broken links.

== Description ==

Most "redirect" plugins only do half the job: they catch the old URL after you have already changed a slug, but they leave the now-broken links sitting inside your other posts and pages. Link Guardian closes that gap.

When you change the slug (or parent) of a published post or page, Link Guardian will:

1. **Create a 301 redirect** from the old URL to the new one, automatically.
2. **Rewrite the old link** everywhere it appears in your other content, so visitors and crawlers follow clean links — not redirects.
3. **Heal chains and loops** so you never end up with `A -> B -> A` or long `A -> B -> C` redirect hops.

It also includes:

* **A redirect manager** — view, add, pause, and delete redirects, with hit counters.
* **A broken internal-link scanner** — batch-scan your whole site for internal links that no longer resolve, then turn any of them into a redirect in one click.
* **A redirect audit, in one API call** — see every loop, multi-hop chain, and "connected" link across the whole site at once.

= Loop protection =

Link Guardian refuses to save any rule that would create a loop — not just the obvious `A -> A`, but multi-hop cycles like `A -> B -> C -> A`. At save time it walks the chain forward and rejects anything that closes back on itself. At serve time it follows chains with a visited-set guard and a hard hop ceiling, so a stray imported loop can never send a browser into an infinite redirect.

= REST API =

Everything is available programmatically under `link-guardian/v1` (requires `manage_options`):

* `GET /wp-json/link-guardian/v1/redirects` — list every rule.
* `GET /wp-json/link-guardian/v1/audit` — whole-graph health: loops, chains, connected links, dead-ends.
* `GET /wp-json/link-guardian/v1/trace?url=/some/path` — follow one URL through every hop.

== Installation ==

1. Upload the `link-guardian` folder to `/wp-content/plugins/`, or install the zip from **Plugins → Add New → Upload**.
2. Activate the plugin through the **Plugins** screen.
3. Visit **Link Guardian** in the admin menu. The defaults (auto-redirect + auto link-fix, 301) are already on.

== Frequently Asked Questions ==

= Does it slow down saving a post? =
Link-rewriting runs on save and is capped (filterable via `link_guardian_rewrite_limit`, default 500 posts). For very large sites you can lower the cap or disable auto-rewrite in Settings.

= Will it redirect a URL that is live again? =
No. When a slug is changed back to a previously-used value, Link Guardian removes any stale rule pointing away from that now-live URL, which also prevents rename-back loops.

= Can it redirect off-site? =
Yes — manual redirects may point to an external URL. Targets are sanitised on save.

== Screenshots ==

1. The Redirects manager — auto-created and manual redirects, with hit counts and one-click pause/delete.
2. The broken-internal-link scanner, with a one-click "create redirect" for each broken link.
3. The Redirect Audit — every loop, multi-hop chain, and connected link in one view (powered by the REST API).

== Changelog ==

= 1.0.0 =
* Initial release: auto-redirect + auto internal-link rewriting on slug change, redirect manager, broken-link scanner, loop/chain protection, and a REST audit API.
