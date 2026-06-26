# Link Guardian

> Keep your internal links healthy. Change a slug and Link Guardian creates the 301 redirect **and** rewrites the old links inside your content — automatically. With multi-hop loop protection and a one-call REST audit.

A WordPress plugin built as a portfolio piece to solve a real, recurring gap: existing redirect plugins catch the old URL *after* a slug change but leave the broken links sitting inside your other posts. Link Guardian fixes both halves.

## Why it exists

When you rename a post/page slug, three things should happen — but most tools only do the first:

1. **Redirect** the old URL → new URL (a 301).
2. **Rewrite** every internal link that pointed at the old URL so readers and crawlers get a clean link, not a redirect hop.
3. **Stay loop-free** — never create `A → B → A` or long `A → B → C → A` chains.

Link Guardian does all three.

## Features

| Feature | What it does |
|---|---|
| **Auto-redirect on slug change** | Hooks `post_updated`, detects slug/parent changes on published content, writes a 301 (configurable). |
| **Auto internal-link rewrite** | Finds the old URL across all content and replaces it with the new URL (absolute + root-relative forms). Capped + filterable. |
| **Redirect manager** | List / add / pause / delete redirects, with hit counters and auto-vs-manual badges. |
| **Broken-link scanner** | AJAX, batched scan of all published content for internal links that 404. One-click "create redirect" for any hit. |
| **Loop & chain protection** | Refuses loop-forming rules at save time (any cycle length); collapses multi-hop chains to a single hop at serve time with a visited-set + hop ceiling. |
| **REST audit API** | `GET /audit` returns every loop, chain, connected link, and dead-end in one request. |

## Architecture

```
link-guardian.php                      Bootstrap: constants, autoloads, activation hooks
includes/
  class-link-guardian.php              Orchestrator — wires the components together
  class-link-guardian-activator.php    dbDelta table creation + default settings
  class-link-guardian-redirects.php    Redirect store, path normalisation, chain
                                       resolution, loop detection, serve-time handler
  class-link-guardian-slug-watcher.php Detects slug changes → redirect + link rewrite
  class-link-guardian-scanner.php      Broken internal-link scanner (AJAX, batched, cached)
  class-link-guardian-rest.php         REST API: /redirects, /audit, /trace
  class-link-guardian-admin.php        Admin menu, list UI, scanner UI, audit UI, settings
assets/
  admin.css  admin.js
uninstall.php                          Drops the table + options on delete
```

The chain logic lives in one place — `Link_Guardian_Redirects::walk_chain()` — and is reused by the front-end handler, the audit API, and the single-URL tracer, so loop behaviour is consistent everywhere.

## Loop protection in detail

- **Save time** — `would_create_cycle( $source, $target )` walks forward from the proposed target through existing active rules. If it returns to `$source` (or exceeds `MAX_HOPS`), the rule is rejected. This catches `A → A`, `A → B → A`, `A → B → C → A`, and longer cycles.
- **Serve time** — `walk_chain()` follows the chain with a `visited` set and a hard hop ceiling. A cycle (e.g. one that arrived via import) is detected and the redirect is *aborted* rather than looping the browser. Otherwise the chain is collapsed so the visitor makes a single hop straight to the terminal destination (better for SEO than 301 chains).
- **Rename-back** — when a slug returns to a previous value, any stale rule whose source is now a live URL is deleted, so the live page is never redirected away from itself.

## REST API

All routes require the `manage_options` capability and a REST nonce.

```
GET /wp-json/link-guardian/v1/redirects        # every rule
GET /wp-json/link-guardian/v1/audit            # loops, chains, connected links, dead-ends
GET /wp-json/link-guardian/v1/trace?url=/x     # follow one URL through every hop
```

Example `audit` response (abridged):

```json
{
  "summary": { "total": 7, "active": 6, "loops": 1, "chains": 2, "connected": 3 },
  "loops":   [ ["/a", "/b", "/a"] ],
  "chains":  [ { "source": "/a", "path": ["/a", "/b", "/c"], "terminal": "https://site/c", "hops": 2 } ],
  "connected": [ { "source": "/a", "target": "/b" } ],
  "broken_dest": []
}
```

## Hooks for developers

- `link_guardian_before_slug_change` ( $post_id, $old_url, $new_url )
- `link_guardian_links_rewritten` ( $post_id, $rewritten_count )
- `link_guardian_rewrite_limit` (filter, default `500`)
- `link_guardian_scannable_post_types` (filter)

## Requirements

WordPress 6.2+ · PHP 7.4+

## License

GPL-2.0-or-later
