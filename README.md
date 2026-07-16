# Site7 Studio

Site7 Studio is a visual website builder and package engine for Craft CMS 5.

## Architecture

Site7 Studio follows a strict **Craft CMS First** UI architecture. 
See [ARCHITECTURE.md](ARCHITECTURE.md) for the rules regarding UI component usage, custom styling, and Vue.js boundaries.

## Project Structure

- `src/` - Plugin source code
  - `assetbundles/` - Web assets (CSS, JS)
  - `console/` - CLI commands
  - `controllers/` - Craft CMS controllers
  - `migrations/` - Database migrations
  - `models/` - Data models and settings
  - `services/` - Business logic and services
  - `templates/` - Twig templates
  - `translations/` - Translation files
  - `variables/` - Twig variables
  - `Site7Studio.php` - Main plugin class
  - `config.php` - Default configuration file
