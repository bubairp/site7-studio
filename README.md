# Site7 Studio

Site7 Studio is a visual website builder and package engine for Craft CMS 5.

## Architecture

Site7 Studio follows a strict **Craft CMS First** UI architecture. 
See [ARCHITECTURE.md](ARCHITECTURE.md) for the rules regarding UI component usage, custom styling, Vue.js boundaries, and the package engine (Manifest, validation, build/archive format).

## Project Structure

- `src/` - Plugin source code
  - `assetbundles/` - Web assets (CSS, JS)
  - `console/` - CLI commands (`make/package` scaffolding, `package/sync`)
  - `controllers/` - Craft CMS controllers
  - `events/` - Custom Yii events
  - `migrations/` - Database migrations
  - `models/` - Data models and settings
  - `services/` - Business logic and services (package engine, publishing, import, marketplace)
  - `templates/` - Twig templates
  - `translations/` - Translation files
  - `variables/` - Twig variables
  - `Site7Studio.php` - Main plugin class
  - `config.php` - Default configuration file
- `packages/` - The built-in package library (production content only; no demo/test packages)
- `tests/` - PHPUnit tests and fixtures (`tests/fixtures/packages` is test-only and is never scanned by production package discovery)
- `docs/` - Design/planning docs for individual phases

## Package Lifecycle

A package moves through: Create → Package Builder (fields/content) → Publish (build + archive as `.s7pkg`) → Marketplace (local or remote repository) → Install → Enable → used via "Add Section"/"Insert Pattern" in an entry → Update → Disable → Remove/Delete.

Every package requires a `README.md` to pass validation (`PackageValidator`), and its handle must match `^[a-z0-9]+(-[a-z0-9]+)*$`. This full lifecycle is scripted end-to-end via live CP testing as part of stabilization passes - see [ARCHITECTURE.md](ARCHITECTURE.md) for the package engine's internal rules.
