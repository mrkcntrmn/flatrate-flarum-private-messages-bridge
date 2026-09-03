# FlatRate private-messages bridge

## Purpose

Temporary FlatRate.wiki-maintained, security-hardened Flarum 1.x private-message extension for production use until a reviewed upstream fix or Flarum 2 `flarum/messages` replaces it.

## Ownership

- **Bridge maintainer:** FlatRate.wiki / `mrkcntrmn/flatrate-flarum-private-messages-bridge`
- **Original upstream project:** `neoncube/flarum-private-messages` ([neoncube2/flarum-private-messages](https://github.com/neoncube2/flarum-private-messages))
- **License:** MIT (upstream authors preserved in `composer.json` and `LICENSE`)

## Lineage SHAs

| Role | SHA |
| --- | --- |
| Upstream base | `9694186ffb429337f8f39304f9848f0054c12425` |
| Qualified hardened source | `ed20f58040b8efe8d69d7940b3fa7010ffb032f2` |
| Bridge packaging | *(filled after packaging commit; see `git rev-parse HEAD` on `main`)* |

## Package identity

| Field | Value |
| --- | --- |
| Composer package | `flatrate/flarum-private-messages-bridge` |
| Flarum extension ID | `flatrate-flarum-private-messages-bridge` |
| Admin title | Private Messages — FlatRate Bridge |
| Supported Flarum | `flarum/core` `^1.8.5` (Flarum 1.x) |
| Packagist package | `flatrate/flarum-private-messages-bridge` (after publish) |
| Conflict | `neoncube/flarum-private-messages: *` (never install both) |
| Replace (historical) | `kyrne/whisper`, `littlecxm/whisper` |

## Renamed vs retained identifiers

### Renamed (package / admin identity)

- Composer name → `flatrate/flarum-private-messages-bridge`
- Flarum extension ID / `app.extensionData.for(...)` → `flatrate-flarum-private-messages-bridge`
- Admin extension title → `Private Messages — FlatRate Bridge`
- Support / homepage URLs → bridge GitHub repository
- Package description → FlatRate bridge framing

### Retained (compatibility / lower migration risk)

These stay on the upstream Neoncube identity so existing DB settings, permissions, locales, routes, CSS, and PHP patches remain comparable and retirement to upstream is simpler:

| Identifier | Why retained |
| --- | --- |
| PHP namespace `Neoncube\FlarumPrivateMessages\` | Minimize fork divergence; preserve patch readability |
| API route prefix `/neoncube-private-messages/...` | Client + server route contracts; avoid breaking stored clients |
| Forum/frontend routes `neoncube-private-messages.*` | Stable named routes already referenced by email/views |
| Locale namespace `neoncube-private-messages` | Existing translation keys and YAML roots |
| Settings keys `neoncube-private-messages.*` | Values already stored in `settings` table on upgraded forums |
| Permissions `startConversation`, `deleteMessage`, `neoncube-private-messages.allowUsersToReceiveEmailNotifications` | Group permission rows and serializer attributes already use these strings |
| CSS vars/classes `--neoncube-private-messages-*`, `.neoncube-private-messages-*` | Theme/CSS continuity |
| JS initializer id `neoncube-private-messages` (forum/admin `initializers.add`) | Harmless unique key; not the Composer-derived extension enablement ID |
| Migration filenames / historical setting inserts | Already-applied migrations must keep the same keys |
| View namespace `flarum-private-messages` | Existing view paths |

## Security qualification summary

Hardened candidate at `ed20f58040b8efe8d69d7940b3fa7010ffb032f2` completed FlatRate FORUM-DM-001 / 001B security and product runtime qualification (participant authorization, validation, layout hardening). This packaging commit only changes distribution identity and docs; requalify the renamed artifact before production pin.

## Product qualification summary

Private messaging UI/API behavior of the hardened candidate was product-qualified in disposable Flarum 1 runtime evidence (FORUM-DM-001B). Email and realtime were out of scope / disabled in that qualification.

## No-feature-creep policy

Bridge changes are limited to security hardening, packaging identity, documentation, and fixes required for safe Flarum 1 operation. Do not add product features here that belong in upstream or Flarum 2 messaging.

## Production exact-pin policy

Production must pin an exact stable version (target `flatrate/flarum-private-messages-bridge:1.0.0` after release gates). Forbidden for production: `*`, `^1.0`, `1.*`, `dev-*`, `@dev`, RC-only pins, local path repos, raw GitHub branches, or unreviewed commits. Never deploy unfixed upstream `neoncube/flarum-private-messages:1.5.4`.

## Retirement triggers

1. **Trigger A:** reviewed fixed upstream `neoncube/flarum-private-messages` release suitable for Flarum 1.
2. **Trigger B:** migration to Flarum 2 with official `flarum/messages`.

### Bridge → upstream migration warning

Retained Neoncube route/setting/permission/locale IDs ease data compatibility, but Composer package and Flarum **extension ID** differ. Plan enable/disable, settings ownership, and permission UI registration carefully when swapping packages. Do not install bridge and upstream simultaneously (`conflict`).

### Flarum 2 replacement

When FlatRate runs Flarum 2, prefer official `flarum/messages` and remove this bridge rather than carrying a Flarum 1 fork forward.
