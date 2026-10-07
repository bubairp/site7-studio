# Site7 Studio

A Craft CMS 5 plugin that turns a fresh Craft site into a finished website from the **Site7 Library**, and gives editors a page builder with ready-made sections and page templates.

- **Using it on a site:** [GUIDE.md](GUIDE.md): install, connect, Starter Kit, page builder, updates, troubleshooting.
- **What's new:** [CHANGELOG.md](CHANGELOG.md).
- **Developer documentation:** [docs/](docs/00_OVERVIEW.md), starting with the overview.

## How it works

```
Author site (Dev Mode)          Commerce24                    Customer site
build blocks and pages   ──▶    plans, licences,       ──▶    Install a Starter Kit,
→ Library packages               signed downloads              insert sections, update
  library/publish                                              (Site7 Studio CP)
```

- **The author site** builds the Library from a real Craft site: every page-builder block becomes a **Section** package, every page a **Template** package, the site's structure, design and settings a **Theme**, and a set of pages with menus and demo content a **Starter Kit**. `library/publish` sends what changed to Commerce24 as new, signed versions.
- **Commerce24** holds the Library, plans, licences and purchases. Its API contract is in [docs/52](docs/52_LIBRARY_DISTRIBUTION.md); connecting a site is in [docs/55](docs/55_CONNECT_COMMERCE24.md).
- **A customer site** starts with an empty Library. It installs a Starter Kit (Theme, pages, menus, content), adds packages its plan includes, and updates them. Updates keep whatever the customer changed.

## Requirements

- Craft CMS 5, PHP 8.2+, MySQL or MariaDB
- PHP extensions `zip` and `sodium` (signed packages)
- Composer on the server; Node and npm are not needed on customer sites

## Project structure

| Path | What's there |
|---|---|
| `src/Site7Studio.php` | The plugin class: services, CP navigation, routes, permissions, events |
| `src/services/` | The business logic: `library/` (publish, catalog, download, updates), `theme/`, `template/`, `starterkit/`, `sitekit/` (content import/export), `commerce/` (Commerce24 client, plans, licences, packages), `import/` (Import Existing Section/Page/Website), `publishing/` (signing), and the package engine |
| `src/controllers/` | CP pages and actions |
| `src/console/controllers/` | Console commands (below) |
| `src/templates/` | CP templates |
| `src/resources/` | CP CSS and JavaScript (Content Browser, page builder integration) |
| `src/models/`, `src/records/`, `src/migrations/` | Data models, database records and migrations |
| `src/assetbundles/`, `src/registries/`, `src/repositories/`, `src/providers/`, `src/jobs/`, `src/events/`, `src/interfaces/`, `src/widgets/`, `src/base/`, `src/translations/` | Supporting code |
| `src/config.php` | Default settings; a site overrides them in `config/site7-studio.php` |
| `packages/` | The author site's Library (Sections, Theme, Starter Kit). Template packages are build output and aren't in git. Customer sites get packages from Commerce24, not from here |
| `tests/` | Unit tests (Codeception) and test-only fixtures |
| `docs/` | Developer documentation (`00` overview … `56` test cases) |

## Console commands

All are `php craft site7-studio/<command>`.

| Command | Does |
|---|---|
| `library/publish [handles] [--bump] [--notes]` | Publish changed Library packages to Commerce24 (author site) |
| `library/catalog` | List what Commerce24 offers this site |
| `library/updates`, `library/update <handles>\|--all` | List and apply Library updates |
| `library/download <handle>` | Download a package and what it requires into the Library |
| `library/pricing <handle> <type>` | Set a package's price type |
| `import/sections` | Turn every page-builder block into a Section package |
| `template/build <page>`, `template/build-all` | Build Template packages from pages |
| `theme/build "<name>"`, `theme/validate`, `theme/install` | Build, check and install a Theme |
| `starter-kit/build "<name>"`, `starter-kit/validate`, `starter-kit/install <handle>` | Build, check and install a Starter Kit |
| `signing/keygen`, `signing/sign`, `signing/verify` | Package signing keys and signatures |
| `site-kit/*` | Full Site Kits: a whole site as one archive (Dev Mode tool) |

## Tests

```bash
php vendor/bin/codecept run unit -c plugins/site7-studio/codeception.yml
```

Run it from the Craft project that has the plugin (with DDEV: `ddev exec 'cd plugins/site7-studio && php ../../vendor/bin/codecept run unit -c codeception.yml'`). The A-to-Z manual and live test cases, with their last results, are in [docs/56](docs/56_TEST_CASES.md).

## Contributing

Read [CLAUDE.md](CLAUDE.md) (development rules, architecture invariants) and [ARCHITECTURE.md](ARCHITECTURE.md) (CP UI rules) before changing code, and [docs/43](docs/43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md) for known issues.

## Licence

Proprietary. © Site7.
