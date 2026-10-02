# 42 — Product Feature Reference

Technically accurate feature-by-feature summary — not marketing copy. Intended as a foundation for a future product/marketing site, but written for accuracy first.

| Feature | What it does | Why it exists | Who uses it | Key dependencies | Status |
|---|---|---|---|---|---|
| Package Authoring | Create/edit reusable content packages from scratch | Lets a developer build shareable components without importing from a live site | Developers/agencies building a reusable library | `PackageAuthoringService` | Implemented |
| Import Existing Section | Turn a live Craft Entry Type + its real Twig into a package | Captures existing, production-proven components instead of rebuilding them | Developers migrating an existing site's components into the package system | `MatrixEntryTypeImportService` | Implemented |
| Import Existing Page/Website | Capture a page or whole site into a Template/Starter Kit package | Enables cloning/templating an entire site setup | Agencies building starter kits for repeated client setups | `PageImportService`, `WebsiteImportService` | Implemented, with documented capture gaps (`15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md` §14) |
| Sync From Source | Refresh a package's stored content from its live origin | Keeps a package current as the live site evolves, without re-importing | Developers maintaining packages alongside an active site | `SectionUpdateService`/`PageUpdateService` | Implemented |
| Package Install | Install a package's Craft resources + files onto a site | The core distribution mechanism | End developers consuming packages | `PackageManagerService` | Implemented |
| Update & Conflict Handling | Safely push package updates to installed sites without losing local edits | Prevents the single biggest risk in any component-distribution system: silently destroying customizations | Developers updating an installed package to a newer version | `PackageUpdatePlanner` | Implemented |
| Rollback | Revert an installed package to a prior version | Recovery mechanism after a bad update | Developers who need to undo an update | `PackageRollbackService` | Implemented |
| Frontend File Ownership | Explicit tracking of CSS/JS/config files a package owns | Extends the safety guarantees beyond Twig to the rest of a component's assets | Developers packaging components with real frontend tooling | `ownedFiles` model | Implemented |
| Package Versioning | Semantic version history with immutable archives | Auditable, restorable history of every package state | All package authors | `VersionManagerService` | Implemented |
| Backup & Recovery | Automatic latest-only backup on every import | Guarantees a restorable artifact exists immediately after import | All users, transparently | `PackageBackupService` | Implemented |
| Marketplace (Local + Commerce24) | Browse/install packages from a local repo or a hosted commerce backend | Distribution channel beyond manual file transfer | Developers acquiring packages | `MarketplaceService` | Implemented (Commerce24 requires configuration) |
| Licensing & Commerce | License activation, plans, subscriptions, entitlements | Supports a commercial distribution model | Commercial package consumers | `LicenseService`, `FeatureGateService` | Implemented (package signing is a prepared stub, not active) |
| Shared Resources | Cross-package reuse of common Craft resources without duplication | Avoids every package re-creating its own copy of a common Field/Volume/etc. | Package authors composing multiple packages together | `DependencyResolverService` | Implemented |
| Starter Kit System | Whole-site capture, build, staged install, and sync | Enables cloning an entire site setup reliably, including plugins/Composer/npm | Agencies standing up new client sites from a proven baseline | `StarterKitBuilder`, `InstallationOrchestratorService` | Implemented |

This table intentionally avoids adjectives beyond what's needed for clarity — see `18_DOCUMENTATION_QUALITY_RULES` (Phase 18 instruction) for why: marketing language belongs in a separate future pass, not this technical reference.
