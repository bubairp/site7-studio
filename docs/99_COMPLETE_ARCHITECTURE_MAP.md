# 99 — Complete Architecture Map

The master index of this documentation set. Every branch below links to its detailed document.

```
SITE7 Studio Plugin (site7\studio)
│
├── Core
│   ├── Bootstrap & Lifecycle ─────────────── 03_BOOTSTRAP_AND_PLUGIN_LIFECYCLE.md
│   ├── Directory Structure ──────────────── 02_DIRECTORY_STRUCTURE.md
│   └── Overall Architecture ─────────────── 01_ARCHITECTURE.md
│
├── Package System
│   ├── Package Architecture (lifecycle index) 06_PACKAGE_ARCHITECTURE.md
│   ├── Package Manifest ─────────────────── 07_PACKAGE_MANIFEST.md
│   ├── Package Authoring ────────────────── 08_PACKAGE_AUTHORING.md
│   ├── Package Build & Export ───────────── 09_PACKAGE_BUILD_AND_EXPORT.md
│   ├── Package Import (.s7pkg) ──────────── 10_PACKAGE_IMPORT.md
│   ├── Package Installation ─────────────── 11_PACKAGE_INSTALLATION.md
│   └── Package Uninstallation (4 variants) ─ 12_PACKAGE_UNINSTALLATION.md
│
├── Import Existing Content
│   ├── Import Existing Section ──────────── 14_IMPORT_EXISTING_SECTION.md
│   └── Import Existing Page/Website ─────── 15_IMPORT_EXISTING_PAGE_AND_WEBSITE.md
│
├── Craft Resources
│   └── Craft CMS Integration ────────────── 04_CRAFT_CMS_INTEGRATION.md
│
├── Templates
│   └── Template Architecture (MOST IMPORTANT) 13_TEMPLATE_ARCHITECTURE.md
│
├── Frontend
│   ├── Frontend File Ownership ──────────── 21_FRONTEND_FILE_OWNERSHIP.md
│   └── Frontend Tooling & Asset Detection ── 22_FRONTEND_TOOLING_AND_ASSET_DETECTION.md
│
├── Versioning
│   └── Package Versioning ───────────────── 17_PACKAGE_VERSIONING.md
│
├── Synchronization
│   ├── Sync From Source (single package) ── 18_SYNC_FROM_SOURCE.md
│   ├── Installed-File Baseline ──────────── 16_INSTALLED_FILE_BASELINE.md
│   └── Starter Kit whole-site sync ──────── 32_STARTER_KIT_SYSTEM.md
│
├── Updates
│   └── Update & Conflict Handling ───────── 19_UPDATE_AND_CONFLICT_HANDLING.md
│
├── Rollback
│   └── Rollback ──────────────────────────  20_ROLLBACK.md
│
├── Marketplace
│   └── Marketplace Architecture ─────────── 23_MARKETPLACE_ARCHITECTURE.md
│
├── Licensing
│   └── Licensing and Commerce ───────────── 24_LICENSING_AND_COMMERCE.md
│
├── Dependencies
│   └── Dependencies and Shared Resources ── 25_DEPENDENCIES_AND_SHARED_RESOURCES.md
│
├── Backup
│   └── Backup and Recovery ──────────────── 26_BACKUP_AND_RECOVERY.md
│
├── Events
│   └── Events and Hooks ─────────────────── 27_EVENTS_AND_HOOKS.md
│
├── Database
│   ├── Database Architecture (narrative) ── 05_DATABASE_ARCHITECTURE.md
│   └── Database Table Reference (flat) ──── 37_DATABASE_TABLE_REFERENCE.md
│
├── Controllers
│   └── Controllers and Routes ───────────── 28_CONTROLLERS_AND_ROUTES.md
│
├── CP
│   └── CP UI Architecture ───────────────── 29_CP_UI_ARCHITECTURE.md
│
├── Console
│   └── Console Commands ─────────────────── 30_CONSOLE_COMMANDS.md
│
├── Security
│   └── Security and Validation ──────────── 31_SECURITY_AND_VALIDATION.md
│
├── Starter Kit System (large subsystem)
│   └── Starter Kit System ───────────────── 32_STARTER_KIT_SYSTEM.md
│
├── Testing
│   └── Testing Architecture ─────────────── 33_TESTING_ARCHITECTURE.md
│
└── Reference / Process
    ├── Development Workflow ─────────────── 34_DEVELOPMENT_WORKFLOW.md
    ├── Data Flow Reference ──────────────── 35_DATA_FLOW_REFERENCE.md
    ├── File Ownership Reference ─────────── 36_FILE_OWNERSHIP_REFERENCE.md
    ├── Service Reference ────────────────── 38_SERVICE_REFERENCE.md
    ├── Troubleshooting Guide ────────────── 39_TROUBLESHOOTING.md
    ├── Extension Guide ──────────────────── 40_EXTENSION_GUIDE.md
    ├── AI Developer Guide ───────────────── 41_AI_DEVELOPER_GUIDE.md
    ├── Product Feature Reference ────────── 42_PRODUCT_FEATURE_REFERENCE.md
    └── Known Issues and Technical Debt ──── 43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md
```

## How major systems interact

- **Package System** is the spine: every other system either produces packages (Import, Starter Kit Build) or consumes them (Installation, Marketplace).
- **Templates** and **Frontend File Ownership** are two instances of the SAME underlying mechanism (`Installed-File Baseline` + `Update & Conflict Handling`) applied to different file kinds — not two separate systems.
- **Versioning** underlies both Sync From Source and Rollback, with a single shared rule (bump-base-off-history) preventing collisions between the two.
- **Starter Kit System** is architecturally parallel to, and NOT merged with, the single-file three-way system (`Update & Conflict Handling`) — it operates on whole-site native-resource state via its own `SynchronizationPlanner`, reusing the install machinery for apply.
- **Marketplace**, **Licensing**, and **Backup** all converge on the same `storage/site7-studio/marketplace-repo/` directory for different reasons (catalog source, entitlement gate, and auto-backup destination respectively) — see `26_BACKUP_AND_RECOVERY.md` §4.
- **Dependencies and Shared Resources** deliberately never blocks Package Installation — this is the one explicit non-blocking design decision documented across this entire set.
- **Page packages are not first-class citizens of the Sync/Versioning system** the way Section packages are — `PageUpdateService` bypasses `VersionManagerService` and has no change-detection gate, unlike every other content-changing path in this map. See `18_SYNC_FROM_SOURCE.md` §5a.
- **`38_SERVICE_REFERENCE.md` is a partial index, not a complete service catalog** (~65 of ~110 actual service classes) — don't treat "not in 38" as "doesn't exist." See that document's coverage note and appendix.
- **Destructive package operations (install/enable/disable/remove) carry no permission gate** — `PackageActionController` calls `requirePermission()` nowhere in the file. See `28_CONTROLLERS_AND_ROUTES.md` §10 / `31_SECURITY_AND_VALIDATION.md`.

This tree is the numbered file list for this documentation set — every file 00 through 43, plus this one (99), is linked above by section. There is no separate deliverable summary outside this repository; if a future pass refers to one, treat this document as the authoritative index instead.
