# Site7 Studio UI Architecture

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
