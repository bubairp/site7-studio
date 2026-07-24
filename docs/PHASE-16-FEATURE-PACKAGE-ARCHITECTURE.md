# Phase 16 — Feature Package Architecture & Base Project Migration
### Architecture Design Document (no implementation)

Status: **Draft for review.** Nothing in this document has been implemented. Every "Recommended" decision below needs sign-off before Phase 16 implementation begins.

---

## 0. Terminology (read first — two things share the word "Section")

This project already overloads "Section":

- **Craft Section** — a native Craft CMS Section (`config/project/sections/*`): a URL-routable content type with its own Entry Type(s) and Field Layout. Examples in the Base Project: `blogs`, `products`, `casestudies`, `portfolios`, `services`, `teamMembers`, `testimonials`, `gallery`.
- **Site7 Section** (existing package type `type: section`) — a SITE7 Studio package wrapping *one* Entry Type used as a block inside the `site7Components` Matrix field (e.g. `heroBanner`, `faq`, `pricing`, `team`, `home`, `headingContent`, `testHeroBlock`, `gallery`). These are presentation building blocks, installed via `templates/site7-components/*.twig`.

Throughout this document, **"Craft Section"** and **"Site7 Section"** are always qualified to keep these apart. A **Feature Package** (this phase's new concept) is built *from* a Craft Section; it may *reference* Site7 Sections, but it is not one.

---

## 1. Resource Classification

Classification of every resource type found in the live Base Project inventory (see investigation appendix at the bottom for the full raw list).

### Group A — Platform Configuration
*Belongs inside the SITE7 Studio plugin. Never inside a package.*

| Resource today (Base Project) | Today's home | Target home |
|---|---|---|
| `themeSettings` (Single Craft Section) | Craft content entry | `Site7Studio` plugin Settings — new `PlatformConfig` model |
| `colorOptions` / "Color Library" (Structure) | Craft content entries | Plugin Settings — color token registry |
| `fontLibrary` (Structure) | Craft content entries | Plugin Settings — typography token registry |
| `additionalCssJs` (Single) — fields `codeCss`, `codeJs` | Craft content entry | Plugin Settings — custom CSS/JS injection |
| Spacing/animation fields (`spacingPreset`, `spacingDropdown`, `spacingNumber`, border/background presets found across many entry types) | Scattered per-entry-type option fields | Plugin Settings — spacing scale & animation preset registry |
| `header` / `footer` / `general` (Singles) | Craft content entries | **Split**: structural/nav parts → Group B (Navigation), pure styling parts → Group A |

**Key finding:** today none of this exists in `Site7Studio::Settings` (confirmed — `src/models/Settings.php` currently only has `matrixFieldId`, `defaultPackage`, and Commerce24 API fields). Group A is **entirely new plugin surface**, not a re-export of something that already exists at the plugin layer. It currently lives as ordinary Craft content, which is exactly why it "leaks" into every Feature/Section export today unless explicitly filtered out — the Resource Analyzer must learn to *recognize and exclude* these Craft Sections from every Group C/D extraction, not just Assets fields as Phase 15 did.

### Group B — Shared Project Resources
*Exist once per project. Packages reference them by handle; never duplicated into a package.*

| Resource | Real example(s) in Base Project | Notes |
|---|---|---|
| Shared Matrix "style" sub-block | `blockStyle` Matrix field (single entry type "Block Style", `maxEntries: 1`) — embedded in **31 different entry types** (`services`, `products`, `blogs`, `casestudies`, `portfolios`, `testimonials`, `images`, `accordion`, `contact`, `headingContent`, `map`, `form`, `teams`, `timeline`, `buttons`, `ctaBanner`, `clientLogos`, `image`, `awards`, `trendingProducts`, …) | The literal "shared building block" the spec describes as `blockStyle` |
| Shared button block | `button` field/entry type — embedded in 9 entry types (`productET`, `footer`, `packages`, `header`, `heroBannerItem`, `standardPageBannerItem`, `buttons`, `ctaBanner`, `carousel`) | The spec's "Button Matrix" |
| Shared layout primitives | `matrixContainer`, `matrixRow`/`matrixRowET`, `matrixColumn` entry types | Generic row/column grid system reused inside many Matrix contents |
| Shared/global fields | Any of the 167 project fields referenced by more than one Feature's Entry Types (`blockStyle`, `button`, style/option fields listed under Group A's spacing/border/background note when reused *structurally*, not just as config) | Ownership boundary: **used inside content structure → Group B**, **used purely as global config value → Group A** |
| Asset Volumes | `publicAsset` (fs handle `public`, subpath `files`) — the only volume in this project | Referenced by handle, never re-created |
| Category Groups | `blogCategories` ("Blog Categories", structure), `portfolioCategories` ("Portfolio Categories", structure) | True Craft Category Groups — distinct from the *Craft Sections* that happen to share similar names (`blogCategories`/`portfolioCategories`/`productCategory` sections are separate, Group C, structures) |
| Tag Groups | `web` ("Web") | — |
| Navigation | `simpleRpMenu` field (from `remoteprogrammer/simple-rp-menu`, the "Simple RP Menus" plugin) attached to `header`/`footer`/similar singles | **Correction to Phase 15's finding**: this project *does* have a real navigation system — it's a third-party plugin field type, not a Structure-nesting approximation. Phase 16's Navigation handling supersedes Phase 15's "approximate via Structure nesting" fallback wherever `simpleRpMenu` is present, and falls back to that approximation only on projects without the plugin. |

### Group C — Feature Resources
*Each Feature Package contains everything required for that feature.*

Real Craft Sections in the Base Project that qualify as **Features** (self-contained, URL-routable content types with their own Entry Type + Field Layout + rendering template):

| Feature | Craft Section(s) | Type | Related Category/Tag Group | Notes |
|---|---|---|---|---|
| Blog | `blogs` | Channel | `blogCategories` | `authors` (Structure) and `blogReview` (Channel) are satellite Craft Sections belonging to this Feature |
| Products | `products` | Channel | none (Category *Section* `productCategory` is content, not a true Category Group — see below) | `productReview` (Channel) is a satellite Craft Section |
| Case Studies | `casestudies` | Channel | — | — |
| Portfolio | `portfolios` | Channel | `portfolioCategories` (real Category Group) | `portfolioCategories` and `galleryCategory` *Craft Sections* (structures) are content-driven category pages, separate from the Category Group of the same family name |
| Gallery | `gallery` | Structure | `galleryCategory` (Structure Craft Section) | — |
| Services | `services` | Channel | — | — |
| Team | `teamMembers` | Structure | — | — |
| Testimonials | `testimonials` | Structure | — | — |
| Standard Pages | `standardPages` | Channel | — | Generic catch-all "page" feature, not a vertical like the others |

**Not present in this Base Project today** (spec-listed examples with no current Craft Section): FAQ, Events, Downloads, Forms. FAQ specifically already exists, but as a **Site7 Section** (Group D — an entry type feeding `site7Components`), not a Craft Section — confirming that "FAQ" in this project is a *presentation block* pattern that gets dropped into any page, not a standalone URL-routable feature. This is an important, real example of the same word meaning a Group C resource on one project and a Group D resource on another; classification must be driven by **actual resource shape** (does it have its own Craft Section + routable URL?), never by name matching.

**Explicitly excluded from Group C** (SITE7 Studio's own internal commerce/marketplace content, not Base Project features): `packages`, `packageFeatures`, `featureGroups`, `colorOptions`, `fontLibrary` Craft Sections — these back the Marketplace/Commerce UI itself and Group A, respectively, not an end-site Feature.

### Group D — Presentation Packages
*Continue to work exactly as they do today. No changes in this phase.*

Existing SITE7 package types, unchanged: `section`, `pattern`, `template`, `starter-kit`. Real current inventory: 6 Site7 Sections (`pricing`, `gallery`, `faq`, `team`, `hero-banner`, `test-hero`), 1 Pattern (`company`), 2 Templates (`test-3`, `reconstructed-homepage`), 1 Starter Kit (`demo-website-kit`).

---

## 2. Platform Configuration Map

```
Site7Studio Plugin
└── PlatformConfigService  (NEW)
    ├── ColorSystem        ← sourced from Craft Section `colorOptions` (Structure) entries, one-time migrated
    ├── Typography          ← sourced from Craft Section `fontLibrary` (Structure) entries
    ├── ContainerWidth       ← sourced from `themeSettings` entry fields
    ├── SpacingScale          ← sourced from `themeSettings` entry fields (spacingPreset/spacingDropdown/spacingNumber family)
    ├── AnimationPresets       ← sourced from `themeSettings` entry fields
    ├── AdditionalCss           ← sourced from `additionalCssJs.codeCss`
    └── AdditionalJs             ← sourced from `additionalCssJs.codeJs`
```

Each item is:
1. **Detected** by the Resource Analyzer as "platform-config-shaped" content (Single Craft Section whose Field Layout matches a known Group A signature, or a Structure Craft Section acting as a flat library of tokens).
2. **Migrated once** into `PlatformConfigService`'s own storage (a new DB table or project-config-backed settings, mirroring how `Settings.php` is already project-config-driven) via a one-time, explicit **Migration** action (see §8) — not an ongoing per-Feature-Package dependency.
3. **Never re-exported** inside any Feature/Section/Template package. Every package that visually depends on a color/spacing/font token references it by **token handle**, resolved at render time from `PlatformConfigService`, exactly like `craft\fields\Matrix` fields resolve their entry types by UID rather than embedding them.

**Open question requiring approval:** should the *source-of-truth* Craft Sections (`themeSettings`, `colorOptions`, `fontLibrary`, `additionalCssJs`) be **retired** after migration (CP edit screens redirect into the new plugin Settings UI) or **kept as the editing UI** with `PlatformConfigService` only *mirroring* their content for fast, package-independent lookup? Recommendation: keep Craft Sections as the editing UI initially (zero content-authoring disruption), with `PlatformConfigService` as a read-through cache invalidated the same way `PackageManagerService::invalidateCraftCaches()` already invalidates Matrix/field caches today. Full retirement is a later, opt-in step once the plugin UI is proven.

---

## 3. Shared Resource Map

```
Shared Project Resources (Group B)
├── Matrix Building Blocks
│   ├── blockStyle        (Matrix, 1 entry type "Block Style", used in 31 entry types)
│   └── matrixContainer / matrixRow / matrixColumn   (layout primitives)
├── Shared Entry Types / Fields
│   └── button            (used in 9 entry types)
├── Asset Volumes
│   └── publicAsset (fs: public)
├── Category Groups
│   ├── blogCategories      (structure)
│   └── portfolioCategories (structure)
├── Tag Groups
│   └── web
└── Navigation
    └── simpleRpMenu field (remoteprogrammer/simple-rp-menu), attached to header/footer
```

**Reference discipline** (the rule every Feature/Section/Template/Starter-Kit package must follow going forward, mirroring Phase 9's frozen "Patterns/Templates never duplicate a Section's definition, only reference it by handle" rule):

- A Feature Package's manifest lists Group B dependencies under `requires.shared` = `{matrix: [...], fields: [...], volumes: [...], categoryGroups: [...], tagGroups: [...], navigation: [...]}`.
- Install-time behavior: **check-then-link**, never generate-if-missing-then-link. If a required shared resource isn't present, installation fails fast with an explicit, actionable error ("`blockStyle` Matrix field not found — install the Shared Resources bootstrap package first"), the same fail-fast contract `PackageManagerService::installPackage()` already uses for missing `requires.sections`/`requires.templates`.
- Shared resources are **not** a new package type. They are provisioned by a single, one-time **Shared Resources Bootstrap** artifact (see §9) — closer to a fixture/migration than an installable Library entry, because there is exactly one of each per project by definition.

---

## 4. Feature Package Structure

Worked example: **Blog**.

```
packages/blog/
├── manifest.json
├── README.md
├── craft/
│   ├── section.yaml          # Craft Section definition: handle, name, type=channel, siteSettings, URL format
│   ├── entry-types/
│   │   └── blogPost.yaml     # Entry Type + Field Layout (field handles + types, via CraftResourceService::describeFieldLayout())
│   └── fields.yaml           # Feature-owned fields only (NOT blockStyle/button — those are requires.shared refs)
├── templates/
│   ├── blogs/
│   │   ├── index.twig        # listing/index route template
│   │   └── _entry.twig       # single-entry route template
├── seo/
│   └── seo.yaml              # ether/seo field defaults for this Feature's routes, if applicable
├── permissions.yaml          # Craft user permission keys this Feature registers (author/editor roles)
├── navigation/
│   └── nav-entries.yaml      # suggested nav entries for simpleRpMenu (opt-in at install)
├── requires/
│   ├── shared.yaml           # Group B refs: blockStyle, button, publicAsset, blogCategories (Category Group)
│   ├── sections.yaml         # Group D Site7 Section refs this Feature's templates render (e.g. headingContent)
│   └── platform.yaml         # Group A refs: which platform tokens the templates consume (informational — never blocking)
├── preview/
│   ├── preview-data.yaml
│   └── preview.twig
└── demo-content/
    └── sample-entries.yaml   # optional seed content for a fresh install (3-5 sample blog posts)
```

`manifest.json` (new `type: feature`, additive to `PackageManifest`'s existing schema — same additive-field discipline Phase 14/15 used):

```json
{
  "schemaVersion": "1",
  "type": "feature",
  "handle": "blog",
  "name": "Blog",
  "version": "1.0.0",
  "craftSection": { "handle": "blogs", "type": "channel" },
  "entryTypes": ["blogPost"],
  "routes": ["blogs", "blogs/<slug>"],
  "requires": {
    "shared": { "matrix": ["blockStyle"], "fields": ["button"], "volumes": ["publicAsset"], "categoryGroups": ["blogCategories"] },
    "sections": ["heading-content"],
    "platform": ["colorSystem", "typography"]
  },
  "satelliteSections": ["authors", "blogReview"],
  "importedFrom": { "sourceType": "craft-section", "sourceId": "<id>", "sourceHandle": "blogs", "importedAt": "...", "importedBy": "..." }
}
```

This directly extends the Phase 15 shape (`importedFrom` is unchanged; `requires` grows new sub-keys `shared`/`platform` alongside the existing `sections`/`patterns`/`templates` keys already used by Sections/Patterns/Templates/Starter-Kits).

---

## 5. Dependency Graph

Concrete, real graph for **Blog**:

```
Feature: Blog
│
├── Craft Section: blogs (channel)
│     └── Entry Type: blogPost
│           └── Field Layout
│                 ├── Feature-owned fields (title, body, excerpt, publishDate, ...)
│                 ├── blockStyle  ─────────────► Group B (shared Matrix)
│                 └── button      ─────────────► Group B (shared field)
│
├── Templates: templates/blogs/index.twig, _entry.twig
│     └── {% include %} of Site7 Section templates it renders
│           └── headingContent ────────────────► Group D (Site7 Section)
│
├── Category Group: blogCategories ─────────────► Group B
├── Satellite Craft Section: authors (structure)
├── Satellite Craft Section: blogReview (channel)
│
├── Navigation: simpleRpMenu entries pointing at /blogs ─► Group B
│
└── Platform Configuration consumed by templates:
      ├── colorSystem  ─────────────────────────► Group A
      └── typography   ─────────────────────────► Group A
```

**Automatic discovery rule** (extends Phase 15's `ResourceAnalyzerService`): walking this graph is a traversal, not a flat scan —

1. Start at the Craft Section → its Entry Type(s) → Field Layout (existing Phase 15 `CraftResourceService::describeFieldLayout()`).
2. For every field on that layout, classify it: Feature-owned (goes into the package) vs. Group B shared (becomes a `requires.shared` reference) vs. Group A platform config (becomes a `requires.platform` informational reference, never packaged). Classification key: **is this field's defining entry type/Matrix referenced by more than one unrelated Feature?** (the same "used in 31 entry types" signal found for `blockStyle` in the investigation) → Group B. Is the field's *value* itself global/singleton in nature (one color palette, one spacing scale for the whole site) → Group A.
3. Scan the Feature's rendering templates for `{% include "@packages/<handle>/template.twig" %}`-style references to installed Site7 Sections (already resolvable via the existing `PackageManagerService::getAllPackages()` + `matrix.yaml` block-handle map built by `TemplateGeneratorService::buildEntryTypeToSectionMap()`) → Group D references.
4. Scan for Category/Tag field types on the Field Layout → resolve to real Category/Tag Groups → Group B references (this supersedes Phase 15's WebsiteImportService behavior of only *warning* about Category/Tag fields without resolving them — Phase 16 requires real resolution since Feature Packages are meant to be fully self-installing).
5. Scan for `simpleRpMenu` (or, on projects without that plugin, Structure-parent nesting per Phase 15's fallback) → Group B Navigation reference.
6. Anything left over that doesn't resolve to Feature/Group B/Group D is flagged as an **unclassified dependency warning** in the Preview step (mirroring Phase 15's `ResourceImportValidator` warning pattern) — never silently dropped, never silently packaged.

---

## 6. Installation Flow

```
Install Feature: Blog
│
├── 1. Check Platform Configuration
│      └── Are colorSystem/typography tokens this Feature's templates reference present in PlatformConfigService?
│            ├── Yes → continue
│            └── No  → warning only (Group A is informational — a Feature must render with sensible
│                       defaults even before Platform Config is customized; this is NOT a hard block,
│                       unlike Group B below)
│
├── 2. Check Shared Resources
│      └── requires.shared: blockStyle (Matrix), button (field), publicAsset (volume), blogCategories (Category Group)
│            ├── All present → continue
│            └── Any missing → HARD FAIL, actionable error naming the missing resource + offering
│                                "Install Shared Resources Bootstrap" as the remediation action
│
├── 3. Create Craft Resources
│      └── Delegates to the existing (frozen) CraftResourceService.generateResources() path, extended to
│           also create/verify the Craft Section itself (net-new capability — today CraftResourceService
│           only creates Fields + Entry Types for Site7 Sections, never a full Section) inside the
│           existing DB transaction + rollback contract PackageManagerService::installPackage() already uses
│
├── 4. Install Sections (Group D)
│      └── Cascade-install any requires.sections the Feature's templates depend on, via the existing,
│           unmodified PackageManagerService::installPackage() recursive cascade (same mechanism Templates/
│           Starter-Kits already use for their own requires.sections/requires.patterns)
│
├── 5. Install Templates
│      └── Copy templates/ into the site's template root under a Feature-namespaced path
│           (e.g. templates/blogs/) — net-new capability (today only Site7 Section template.twig files are
│           copied, to templates/site7-components/); needs an explicit collision policy (see §11 open question)
│
└── 6. Ready
       └── PackageManagerService::invalidateCraftCaches() (existing, unmodified) + register the Feature's
            permissions.yaml keys with Craft's permission registry + (optional, user-confirmed) seed
            demo-content/sample-entries.yaml
```

Steps 3–6 reuse the frozen `PackageManagerService`/`CraftResourceService` orchestration exactly as Phase 15 mandated for the Resource Importer — this phase's only *new* orchestration surface is steps 1–2 (a pre-flight gate ahead of the existing install pipeline) and the Craft-Section-creation and template-copy capabilities inside step 3/5, which are genuinely new because nothing before Phase 16 has ever needed to create a full routable Craft Section or copy templates outside `site7-components/`.

---

## 7. Import Flow

```
Developer
  │
  ├── Creates Blog in Craft CMS natively (Craft Section + Entry Type + Field Layout + Twig templates)
  │      (no SITE7 involvement at all during this step — this is the whole point of "Base Project First")
  │
  ├── Tests Blog on the live site
  │
  ├── SITE7 Studio → Library → Sections tab-equivalent for Features (new "Features" tab, see §10)
  │      → "Import Existing Feature"
  │
  ├── Select: pick a Craft Section (reuses Phase 15's actionGetCraftSections listing endpoint, unchanged)
  │
  ├── Analyze
  │      └── Runs the §5 dependency graph traversal:
  │            - Feature-owned fields vs. Group A/B/D references
  │            - detected Category/Tag Group resolution
  │            - detected Navigation entries
  │            - detected Site7 Section template includes
  │            - unclassified-dependency warnings
  │
  ├── Preview
  │      └── Extends Phase 15's Preview step: package summary, detected resources/dependencies (now grouped
  │           by A/B/C/D instead of a flat list), validation results, package size, PLUS a new "Platform &
  │           Shared Resources" panel showing what will be referenced vs. what's missing
  │
  ├── Generate Feature Package
  │      └── Writes the §4 structure to @packages/<handle>/ — same discoverPackages() → installPackage() →
  │           enablePackage() → getPackageByHandle() commit sequence every Phase 15 importer already uses
  │
  └── Available in Library (new Features tab)
```

No manual packaging at any step — this is a direct extension of Phase 15's Analyze → Preview → Save wizard shape (`ResourceAnalyzerService` / `ResourceImportValidator` / a new `FeatureImportService` alongside `CraftSectionImportService`), not a parallel system.

---

## 8. Migration Strategy

Two distinct migration concerns:

### 8a. One-time Platform Configuration migration
A dedicated, explicit, developer-triggered action (console command `site7-studio/platform-config/migrate` + a CP button under Settings) that:
1. Reads `themeSettings`, `colorOptions`, `fontLibrary`, `additionalCssJs` Craft Section entries.
2. Writes them into the new `PlatformConfigService` storage.
3. Is **idempotent and re-runnable** (safe to run again after the source entries change, to re-sync) — never a silent, automatic, on-every-request sync, to avoid surprising drift.
4. Produces a migration report (what was migrated, what was skipped/ambiguous) — mirroring the existing `PackageDiscovery::discoverFromPath()` per-item try/catch-and-report pattern rather than an all-or-nothing transaction.

### 8b. Ongoing Feature/Group-B extraction
Not a "migration" in the traditional sense — this is the normal Import Flow (§7), run once per Feature, at whatever pace the team chooses. Group B resources are provisioned once via the Shared Resources Bootstrap (§9), *before* the first Feature that depends on them is imported.

**Sequencing constraint:** Platform Config migration (8a) and Shared Resources Bootstrap (§9) must both happen **before** any Feature Package is imported for the first time, since Feature import's dependency graph (§5) resolves against whatever already exists in Group A/B. Importing a Feature before its Group A/B dependencies exist doesn't fail — it just produces `requires.platform`/`requires.shared` references to *not-yet-existing* resources, caught later at install time (§6, step 2) rather than at import time. This is intentional (mirrors Phase 15's "warn about missing dependencies, don't block the import" philosophy) but should be called out to developers via a strongly-worded Preview-step warning.

---

## 9. Shared Resources Bootstrap (supporting concept, not a new package type)

A one-time provisioning artifact — conceptually similar to a Starter Kit but for Group B instead of pages:
```
site7-studio/shared-resources-bootstrap.yaml   (or a console command)
  matrix: [blockStyle]
  fields: [button]
  volumes: [publicAsset]
  categoryGroups: [blogCategories, portfolioCategories]
  tagGroups: [web]
  navigation: [simpleRpMenu field config]
```
Generated the same way Phase 15's `ResourceAnalyzerService` inspects live Craft resources — but targeted specifically at Group B, and installed exactly once per project (re-running it is a no-op / update-in-place, never a duplicate).

---

## 10. Package Relationship Diagram

```
                    ┌─────────────────────┐
                    │  Platform Config (A) │   ← plugin-level, singleton, informational-only dependency
                    └──────────┬───────────┘
                               │ referenced by
                    ┌──────────▼───────────┐
                    │ Shared Resources (B)  │   ← project-level, singleton, hard-required dependency
                    └──────────┬───────────┘
                               │ referenced by
        ┌──────────────────────▼──────────────────────┐
        │              Feature Package (C)              │   ← "Blog", "Products", "Portfolio", ...
        │  Craft Section + Entry Types + Templates +     │
        │  SEO + Permissions + Navigation                │
        └───────┬───────────────────────────┬───────────┘
                │ renders via                │ composed by
     ┌──────────▼──────────┐      ┌──────────▼───────────┐
     │   Site7 Sections (D)  │◄────┤   Patterns (D)          │
     │  (existing, unchanged) │     │  (existing, unchanged)  │
     └──────────┬────────────┘     └──────────┬─────────────┘
                │                              │
                └──────────────┬───────────────┘
                                │ composed by
                     ┌──────────▼───────────┐
                     │   Templates (D)         │   ← existing, unchanged (a captured PAGE, not a Feature)
                     └──────────┬────────────┘
                                │ referenced by
                     ┌──────────▼───────────┐
                     │  Starter Kits (D)       │   ← existing, unchanged
                     └────────────────────────┘
```

Key relationship rules:
- **A and B are dependencies of C, never the reverse.** A Feature Package references Platform Config/Shared Resources; neither ever references a Feature.
- **C and D are siblings that compose, not a hierarchy.** A Feature's *templates* render Site7 Sections/Patterns (D), but a Feature Package's identity (its Craft Section + Entry Type) is independent of any Site7 Section — a Feature can exist and install successfully with zero Site7 Section dependencies (a plain-Twig Blog with no Site7 Matrix content at all is still a valid Feature Package, exactly mirroring Phase 15's `PageImportService` "entry with no Site7 content" path).
- **Templates (D) still capture whole pages**, and can capture a page that happens to belong to a Feature (e.g. capturing one specific blog post as a Template) — that remains entirely orthogonal to importing the Blog *Feature* itself. A Starter Kit can reference Feature-produced Templates exactly like any other Template, no special-casing needed.

---

## 11. Recommended Folder Structure

```
plugins/site7-studio/
├── src/
│   ├── models/
│   │   ├── packages/
│   │   │   └── FeaturePackage.php          (NEW — mirrors SectionPackage/TemplatePackage's ~10-line stub shape)
│   │   └── platform/                        (NEW namespace)
│   │       └── PlatformConfig.php
│   ├── services/
│   │   ├── import/                          (existing Phase 15 namespace, extended)
│   │   │   ├── FeatureImportService.php     (NEW — mirrors CraftSectionImportService's shape)
│   │   │   ├── SharedResourceAnalyzer.php   (NEW — Group B detection, used by ResourceAnalyzerService)
│   │   │   └── PlatformConfigAnalyzer.php   (NEW — Group A detection)
│   │   └── PlatformConfigService.php        (NEW — top-level, mirrors PackageManagerService's role but for Group A)
│   ├── controllers/
│   │   └── FeatureImportController.php      (NEW — mirrors ResourceImportController's shape)
│   └── console/controllers/
│       └── PlatformConfigController.php     (NEW — `site7-studio/platform-config/migrate`)
├── packages/
│   └── blog/                                 (NEW package type instance — see §4 structure)
└── docs/
    └── PHASE-16-FEATURE-PACKAGE-ARCHITECTURE.md   (this document)
```

**Open question requiring approval:** template installation target path. Today, Site7 Section templates always land at `templates/site7-components/<handle>.twig` (a flat, single-purpose directory). Feature Packages need their *own* routable templates (`templates/blogs/index.twig`, `templates/blogs/_entry.twig`) — copying into the site's real template root risks colliding with hand-authored templates the developer already has (since Features are extracted *from* a Base Project that, by definition, already has these templates working). Recommendation: Feature install **does not copy templates into the live template root at all** by default — it only copies them into `templates/site7-components/features/<handle>/` as a reference/fallback copy, and updates `config/routes.php`-equivalent routing to point at the *original* Craft Section's existing template path if it's still present, only falling back to the packaged copy if the original is missing (e.g. installing a Feature into a *fresh* project that never had those templates). This avoids Phase 16 ever silently overwriting a developer's live templates.

---

## 12. Future Implementation Plan (sequenced, not yet built)

1. **`PlatformConfigService` + migration command** (§2, §8a) — no dependency on anything else in this list; can ship first and be validated against the real `themeSettings`/`colorOptions`/`fontLibrary`/`additionalCssJs` content immediately.
2. **`SharedResourceAnalyzer` + Shared Resources Bootstrap** (§3, §9) — extends Phase 15's `CraftResourceService`/`ResourceAnalyzerService`; second because Feature import (step 3) depends on being able to *classify* Group B during its dependency graph traversal.
3. **`FeaturePackage` model + `FeatureImportService` + `FeatureImportController`** (§4, §6, §7) — the core deliverable; depends on 1 and 2 existing so the dependency graph traversal (§5) has something real to classify against.
4. **Install-flow extensions to `CraftResourceService`** (§6 step 3: create-a-Craft-Section capability, currently absent) and **template placement policy** (§11 open question) — these are the two genuinely novel capabilities nothing in Phases 1–15 needed; sequence last since they're the highest-risk (write access to the developer's live template tree and Craft Section structure) and most benefit from the first three steps being battle-tested first.
5. **Library UI**: new "Features" tab alongside Sections/Patterns/Templates/Starter Kits (Group D's `library/index.twig` toolbar convention, extended with a 4th `currentType` branch) + "Import Existing Feature" wizard (mirrors Phase 15's `resource-import-wizard.js`, extended with the Platform/Shared Resources preview panel from §7).
6. **Validation extensions**: extend the existing `ResourceImportValidator` with Group A/B-aware checks (missing shared resource = hard error at install per §6, not just a warning at import per §5/§7) — a genuine, intentional divergence from Phase 15's "everything is a warning, nothing blocks" posture, because Feature Packages are meant to be self-installing on a *different* project than the one they were extracted from, where Group B truly might not exist yet.

Each step should land as its own reviewed change, in this order, per the same incremental-phase discipline this plugin has followed through Phases 1–15.

---

## Appendix — Investigation source data

Full raw inventory (Sections, Entry Types, Fields, Volumes, Category/Tag Groups, installed Composer packages) gathered from `config/project/` and root `composer.json` is preserved in the PR/session notes that produced this document. Headline counts: 35 Craft Sections, 113 Entry Types, 167 Fields, 1 Volume (`publicAsset`), 2 Category Groups, 1 Tag Group, 8 installed Site7 Section templates, 30 third-party Composer plugins/libraries (notably `remoteprogrammer/simple-rp-menu` for Navigation and `ether/seo` for SEO field defaults).
