# Private Messages — FlatRate Bridge

Temporary FlatRate.wiki-maintained Flarum 1.x private-message bridge.

Derived from [`neoncube/flarum-private-messages`](https://github.com/neoncube2/flarum-private-messages) (MIT).

| Lineage | SHA |
| --- | --- |
| Upstream base | `9694186ffb429337f8f39304f9848f0054c12425` |
| FlatRate hardened source | `ed20f58040b8efe8d69d7940b3fa7010ffb032f2` |

**Purpose:** provide a safe temporary Flarum 1 private-messaging package for FlatRate.wiki while upstream review or Flarum 2 messaging lands.

**Not** an official Neoncube release and **not** labeled as `neoncube/flarum-private-messages` 1.5.5.

## Installation

Do not install alongside `neoncube/flarum-private-messages` (Composer `conflict`).

```bash
composer remove kyrne/whisper --no-update
composer remove littlecxm/whisper --no-update
composer require flatrate/flarum-private-messages-bridge:1.0.0
php flarum migrate
php flarum cache:clear
```

Prefer an exact version pin in production. Do not use `*`, `^1.0`, `dev-*`, or a raw Git branch for production.

## Updating

```bash
composer update flatrate/flarum-private-messages-bridge --with-dependencies
php flarum migrate
php flarum cache:clear
```

## Retirement

This bridge is temporary. Retire it when either:

1. a reviewed fixed upstream `neoncube/flarum-private-messages` release is available, or
2. the forum moves to Flarum 2 and official `flarum/messages`.

See [BRIDGE.md](BRIDGE.md) for identity, retained compatibility IDs, and migration notes.

## Security

Report vulnerabilities privately. See [SECURITY.md](SECURITY.md).

## Credits

Thank you to [Kyrne](https://redevs.org) for the original [Whisper](https://flarum.org/extension/kyrne/whisper) extension, and to the Neoncube / Whisper contributors listed in `composer.json` (Charlie Kern, David Wheatleu, CXM, Eli Black) for the upstream Flarum private-messages work this bridge packages.

FlatRate.wiki maintains this bridge distribution and security hardening only; upstream authorship remains with the original authors.

[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
