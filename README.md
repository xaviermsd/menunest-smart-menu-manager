# MenuNest: Smart Menu Manager

[![CI](https://github.com/xaviermsd/menunest-smart-menu-manager/actions/workflows/ci.yml/badge.svg)](https://github.com/xaviermsd/menunest-smart-menu-manager/actions/workflows/ci.yml)
![WordPress 6.7+](https://img.shields.io/badge/WordPress-6.7%2B-21759b)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

Build WordPress menus without the drag-and-drop hassle, and keep them safe.
MenuNest adds tools to **Appearance > Menus** and a **MenuNest** screen for backups,
import, export and history.

## Features

**Build menus faster**

- **Add new items under a parent.** Pick the parent once; every "Add to Menu" button puts items straight under it.
- **Plus button on every item.** Add a sub-item right where you want it.
- **Bulk add from a pasted list.** One item per line, `Name | https://link`, indent for sub-items. Names that match an existing category are linked to it.
- **Add a category with all its subcategories**, already nested.

**Find your way around big menus**

- Collapse and expand branches, or everything at once.
- Search the menu and jump between matches.

**Keep menus safe**

- Automatic backup every time a menu is saved (skipped when nothing changed).
- One-click backup and one-click restore, into the same menu or as a new menu. Restoring backs up the current version first.
- Download any backup as JSON. Choose how many backups to keep.

**Move menus between sites**

- Export a menu as JSON, including extra item settings from themes and plugins.
- Import with a full preview: menu name, item count, source site, export date, matched and unmatched items.
- Matching by post type and slug (full path for child pages), taxonomy and slug, and rewritten links to the old site.
- Manual mapping for any item, warnings for missing content types and theme locations, and a progress bar for big menus.
- Imports always create a new menu.

**Know what happened**

- History of every import, export, backup, restore and duplicate, with who and when. Download as JSON or text.

## Requirements

- WordPress 6.7 or newer
- PHP 7.4 or newer
- A theme that uses the classic menu screen (Appearance > Menus)

## Installation

Install from **Plugins > Add New** by searching for "MenuNest", or upload the zip from the
[releases page](https://github.com/xaviermsd/menunest-smart-menu-manager/releases).

## Development

```bash
composer install          # PHPUnit, PHPStan, coding standards
composer lint             # WordPress Coding Standards + PHP 7.4+ compatibility
composer analyse          # PHPStan level 8
composer test             # PHPUnit with the official WordPress test suite
composer check            # all three
bin/build-zip.sh          # release zip in build/, without development files
```

### Running the tests

The tests use the official WordPress test suite, so they need a WordPress install and a
database **that the tests are allowed to wipe**. Never use a real site.

```bash
# 1. Download WordPress for the tests.
mkdir -p /tmp/wordpress && curl -sSL https://wordpress.org/latest.tar.gz | tar -xz --strip-components=1 -C /tmp/wordpress

# 2. Create an empty database, for example "wordpress_test".

# 3. Run the tests.
WP_CORE_DIR=/tmp/wordpress WP_DB_NAME=wordpress_test WP_DB_USER=root WP_DB_PASS=secret composer test
```

All settings are in `tests/wp-tests-config.php` and can be set with environment variables
(`WP_CORE_DIR`, `WP_DB_NAME`, `WP_DB_USER`, `WP_DB_PASS`, `WP_DB_HOST`).

## Testing and quality gates

Every push and pull request runs on GitHub Actions:

| Check | What it covers |
| --- | --- |
| PHPUnit | 137 tests, 734 assertions: backups and restore, import validation, matching and safe setting keys, batched import, history, settings, uninstall, permissions and security tokens |
| Test matrix | PHP 7.4, 8.1, 8.3, 8.4 on WordPress 6.7 and the latest release |
| PHPStan | Level 8, with WordPress-aware rules, on every PHP file |
| WordPress Coding Standards | `WordPress` ruleset, zero errors and warnings |
| PHP compatibility | PHP 7.4 and newer |
| Plugin Check | The official WordPress.org check, on the release build |

All PHP files use `declare( strict_types=1 )` and native type declarations.

## Project layout

```
menunest-smart-menu-manager.php   Plugin header and loader
uninstall.php                     Removes data only when the user asked for it
includes/                         One class per job (backups, import, export, history, ...)
assets/                           Scripts and styles for the two admin screens
languages/                        Translation template
tests/                            PHPUnit tests
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
