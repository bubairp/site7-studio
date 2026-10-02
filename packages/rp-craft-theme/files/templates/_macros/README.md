# Twig Helpers Documentation

This directory contains reusable Twig macros designed to streamline development and ensure consistency across the project.

## 🖼️ Images (`images.twig`)
Macros for rendering optimized, responsive images with WebP support and automatic lazy loading.

### `responsiveImg(...)`
The primary macro for responsive images using the `<picture>` tag.
- **Features**: Automatic srcset, WebP detection, Lazy Loading, Placeholder support.

### `singleImg(...)`
A simplified macro for single-sized images (e.g., avatars, icons).

### `transformOrPlaceholder(...)`
A utility for rendering an image or falling back to a placeholder based on settings.

---

### 📖 Full Examples

#### 1. Hero Banner (Responsive)
Use this for full-width banners that need different ratios for desktop and mobile.

```twig
{% import "_macros/images" as helper %}

{% set heroStyles = [
    { width: 1920, ratio: 16/9 },
    { width: 1200, ratio: 16/9 },
    { width: 768,  ratio: 4/3 },
    { width: 480,  ratio: 1/1 }
] %}

{{ helper.responsiveImg(entry.heroImage.one(), heroStyles, {
    mode: 'crop',
    quality: 90
}, '100vw', 'w-100 h-auto') }}
```

#### 2. Card Image (Single Size + Lazy Loading)
Perfect for blog cards or team member photos. Lazy loading is on by default.

```twig
{% import "_macros/images" as helper %}

<div class="card">
    {{ helper.singleImg(entry.thumbnail.one(), 400, { ratio: 3/2 }, 'card-img-top') }}
    <div class="card-body">...</div>
</div>
```

#### 3. Critical Above-the-Fold Image (No Lazy Loading)
Disable lazy loading for images that should be visible immediately upon page load.

```twig
{% import "_macros/images" as helper %}

{{ helper.singleImg(entry.logo.one(), 150, { lazy: false }, 'navbar-brand') }}
```

#### 4. Passing Custom Attributes
You can pass data attributes or ARIA labels via the `attr` parameter.

```twig
{% import "_macros/images" as helper %}

{{ helper.responsiveImg(entry.featureImage.one(), null, {}, '100vw', 'img-fluid', {
    'data-zoom': 'true',
    'aria-label': 'Feature Image Description'
}) }}

---

## 🏗️ Technical Features
- **WebP Support**: Automatically checks server support and serves `<source type="image/webp">`.
- **Lazy Loading**: Automatic `lazyload` class and `data-src/srcset` attributes (supports **lazysizes**).
- **Placeholder**: Automatic 30px low-quality placeholder.
- **Fail-safe**: Renders **nothing** if the transform fails, preventing broken image icons.

---

## 👁️ Visibility (`visibility.twig`)
Helper for managing responsive visibility classes based on Craft CMS Multi-Select fields.

### `classes(blockStyle, contentData)`
Generates Bootstrap utility classes (e.g., `d-block`, `d-md-none`) based on selected visibility options.

- **Parameters**:
    - `blockStyle`: The related entry containing the visibility field (Style Entry).
    - `contentData`: The fallback entry (Matrix Block itself) in case the field is attached directly.
- **Returns**: A trimmed string of classes.

#### Usage Example
```twig
{% import "_macros/visibility" as visibility %}

<div class="matrix-block {{ visibility.classes(blockStyle, block) }}">
    ...
</div>
```

#### Supported Options
The helper expects a Multi-Select field with the following values:
- `mobile` (< 768px)
- `tablet` (768px - 992px)
- `desktop` (>= 992px)

It automatically handles combinations (e.g., "Mobile & Desktop") by generating the precise Bootstrap breakpoints needed.

---

## 🛠️ Utils (`utils.twig`)
Miscellaneous small utility macros.

### `formatTime24Hour(time)`
Formatted output for time values.
- **Usage**: `{{ utils.formatTime24Hour(entry.postDate) }}` -> `14:30`

---

## Guidelines for Helpers
- **Conditional Logic**: Helpers should handle missing data gracefully (e.g., checking if an image exists).
- **Performance**: Avoid heavy database calls within macros; pass pre-fetched data instead.
