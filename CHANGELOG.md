# Changelog

All notable changes to **Link Guardian** are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-06-27
### Added
- Automatic **301 redirect** creation when a published post/page slug (or parent) changes.
- Automatic **internal-link rewriting** to the new URL across existing content (offloaded to WP-Cron, KSES-safe direct writes).
- **Pattern redirects** — wildcard (`/old/* → /new/*`) and regex (with `$1`/`$2` capture refs), each **ReDoS-guarded** (bounded backtrack limit, compile-time validation, length caps).
- Per-pattern **exceptions** list — "redirect everything matching except these paths" (exact or wildcard).
- **Redirect manager**: add, edit, pause/resume, delete, search, hit counts, and auto/manual + match-type badges.
- **Broken internal-link scanner** (AJAX, batched) with one-click "create redirect" per finding.
- **Redirect audit** REST API (`link-guardian/v1`): loops, multi-hop chains, connected links, dead-ends — plus an admin Audit screen.
- **Multi-hop loop protection**: refuses `A→B→A` / `A→B→C→A` at save time; aborts loops at serve time via a visited-set guard.
- Full-width, collapsible Add/Edit panel and an inline search row.

### Security
- **Open-redirect guard for pattern rules**: a pattern's redirect host can never be supplied by a visitor capture — off-site targets are only honoured when the host is literal in the rule's template.
- Dangerous redirect targets (`javascript:`, `data:`, protocol-relative `//host`) rejected at the data layer.
- Cross-content link rewriting gated behind the `edit_others_posts` capability.
- Broken-link scanner restricted to same-host probes (no redirect-following; TLS verified).

[Unreleased]: https://github.com/aarkaynegi/link-guardian/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/aarkaynegi/link-guardian/releases/tag/v1.0.0
