# Site7 Studio Architecture

This document covers two things: the **UI Architecture** (below) that all Control Panel screens must follow, and the **Package Engine** (at the end) that governs how packages are authored, validated, built, and distributed.

## UI Architecture

Site7 Studio strictly adheres to a **Craft CMS First** design strategy. 

The goal of this architecture is to make Site7 Studio feel like a first-party Craft CMS feature, ensuring a seamless user experience, long-term maintainability, and automatic compatibility with Craft CMS updates (including Dark Mode).

## Core Rules

1. **Native UI Components:** All Control Panel pages must use Craft CMS native UI components and patterns wherever possible (e.g., `_layouts/cp`, `_includes/nav`, `_includes/forms`).
2. **Do Not Recreate:** Do not recreate components that already exist in Craft CMS. If Craft provides a button, layout block, sidebar, or table, use the native Craft CSS classes and Twig macros.
3. **No Unnecessary Styling:** Remove all unnecessary custom styling. Inline styles are strictly forbidden. 
4. **Native CSS Classes:** Use Craft's built-in CSS classes (`.pane`, `.btn`, `.submit`, `.lightswitch`, `.field`, `.meta`, `.data`) for layouts, forms, tables, buttons, and navigation.
5. **Custom UI Scope:** Only create custom UI for highly specialized features that Craft CMS does not provide natively. Examples include:
    - Component preview cards and grids
    - Visual Builder workspaces
    - Canvas interactions
    - Property Panels
    - Drag & Drop interfaces
6. **First-Party Feel:** Core administrative pages (Library, Templates, Dashboard, and Settings) should feel indistinguishable from first-party Craft CMS screens.
7. **Vue.js:** Vue.js is strictly reserved for the Visual Builder and other highly interactive workspaces. It must not be used for standard administration screens (Dashboard, Library, Settings), which must rely on standard Twig templates and vanilla JavaScript (where necessary).

## Implementation Details

### Layouts
Instead of defining custom HTML structures for page layouts, pages must extend `_layouts/cp`:
```twig
{% extends "_layouts/cp" %}
{% set title = "Page Title" %}
{% set selectedSubnavItem = "plugin-section" %}
```

### Sidebars
Sidebars must be rendered using Craft's `_includes/nav` component inside the `{% block sidebar %}`:
```twig
{% block sidebar %}
    {% include "_includes/nav" with {
        label: 'Navigation',
        items: navItems,
        selectedItem: selectedItem,
    } only %}
{% endblock %}
```

### Assets and CSS
Any custom CSS must be registered via Craft Asset Bundles.
Custom CSS must use Craft's native CSS variables (e.g., `var(--gray-200)`) to ensure automatic dark mode support and a cohesive color palette.

## Package Engine

A package is a directory under `packages/<handle>/` containing a `manifest.json` plus type-specific files (`fields.yaml`, `matrix.yaml`, `template.twig`, `preview/`, `README.md`). There is no separate "Blueprint" file format - the manifest is the single source of truth for a package's definition; "blueprint" only appears in the UI as descriptive copy for the Template/Starter Kit package types.

### Handles
A package handle must match `^[a-z0-9]+(-[a-z0-9]+)*$` (kebab-case). It becomes the on-disk directory name, the DB primary lookup key, and a URL segment - never accept a handle without validating this format, whether it's auto-generated (`StringHelper::toKebabCase()`) or user-supplied.

### Validation
`PackageValidator::validatePackage()` (`src/services/engine/PackageValidator.php`) is the hard gate run during discovery (`PackageDiscovery::discoverFromPath()`); packages that fail it are silently skipped from the Library, not just flagged. It currently requires a `README.md`. This is distinct from `PublishValidatorService`, which produces a soft readiness score/warnings shown in the Publish wizard and never blocks publishing, and `ResourceImportValidator`, which validates a live Craft resource before it's imported into a package.

### Build & Distribution
Publishing a package (`PackagePublisherService::publish()`) builds it (`PackageBuilderService`) into a `.s7pkg` - a plain ZIP containing `bundle-manifest.json` plus one full copy of each package directory in its resolved dependency closure (`PackageExportService::resolveDependencyClosure()`, which walks `requires` and Starter Kit `pages[].templateHandle`). `PackageArchiveHelper` (`src/services/support/`) is the shared, stateless zip/checksum helper used by both export and import, and excludes OS/editor cruft (`.DS_Store`, `Thumbs.db`, `*.swp`, `*.tmp`, `*.bak`) from both the archive and its checksum.

Shared Resources (fields/volumes/etc. that must already exist live on the installing site - never bundled) are declared in `bundle-manifest.json`'s `requiredSharedResources` so a receiving site can warn upfront (at import validation) rather than only discovering a missing dependency at install time.

### Package discovery boundary
Production package discovery (`PackageManagerService::discoverPackages()`) only scans `packages/`. It must never scan `tests/fixtures/packages` - that path is test-only, and mixing it into discovery causes test fixtures to register as real, installable/exportable packages in any environment where the plugin's `tests/` directory happens to be present.
