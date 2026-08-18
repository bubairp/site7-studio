# 37 — Database Table Reference

Flat lookup table, one row per table, for quick "what table do I need" reference. For full column-level detail and the relationship diagram, see `05_DATABASE_ARCHITECTURE.md`.

| Table | Purpose | Created by migration | Key relationships |
|---|---|---|---|
| `site7_packages` | Core package registry row (one per package) | `m260716_100535_create_package_tables.php` | Parent of nearly every other table below via `packageId` FK |
| `site7_components` | (Section package sub-content) | `m260716_100535_create_package_tables.php` | FK→`site7_packages` |
| `site7_templates` | (Template package sub-content) | `m260716_100535_create_package_tables.php` | FK→`site7_packages` |
| `site7_package_dependencies` | Declared dependencies (package or shared-resource) | `m260716_100535_create_package_tables.php` | FK→`site7_packages` CASCADE |
| `site7_package_versions` | Immutable version history + archive path/checksum | `m260716_100535_create_package_tables.php`; `archivePath` column added by `m260813_142335_add_package_version_archive_path.php` | FK→`site7_packages` CASCADE; unique `(packageId, version)` |
| `site7_package_publications` | Publish-target tracking | `m260723_110923_create_package_publications_table.php` | FK→`site7_packages` |
| `site7_shared_resources` | Registered shared Craft resources | `m260724_130000_create_shared_resources_tables.php` | unique idx `handle`, idx `type` |
| `site7_shared_resource_dependencies` | Shared→Shared dependency edges | `m260724_130000_create_shared_resources_tables.php` | FK→`site7_shared_resources` CASCADE |
| `site7_install_sessions` | Starter Kit install session/stage state | `m260730_130000_create_install_sessions_table.php`; widened by `m260730_130418_widen_install_session_data.php` | keyed by session uid |
| `site7_installed_starter_kits` | Baseline for whole-site sync diffing | `m260730_140000_create_synchronization_tables.php` | `installedVersion`, `blueprintSnapshot` (JSON) |
| `site7_sync_history` | Sync run history | `m260730_140000_create_synchronization_tables.php` | |
| `site7_sync_sessions` | Sync session/stage state | `m260730_140000_create_synchronization_tables.php` | |
| `site7_section_import_sources` | 1:1 provenance: package ↔ live Entry Type | `m260731_150000_create_section_import_sources_table.php` | unique `sourceUid`, unique `packageId` |
| `site7_page_import_sources` | 1:1 provenance: package ↔ live Entry | `m260801_000000_create_page_import_sources_table.php` | unique `sourceUid`, unique `packageId` |
| `site7_website_import_sources` | 1:1 provenance: package ↔ selection (computed `selectionKey`) | `m260802_000000_create_website_import_sources_table.php` | unique `selectionKey`, unique `packageId` |
| `site7_installed_files` | Baseline checksum per installed file (templates + owned files) | `m260817_150000_create_installed_files_table.php` | FK→`site7_packages` CASCADE; unique `(packageId, targetPath)` |

**`site7_packages` columns added by later migrations** (not new tables): `authoringStatus` (`m260722_155849`), `creatorId` (`m260722_190000`), `entitlementRemovableOn` (`m260728_120000`), `category`/`tags` (`m260730_123644`).

All `CREATE TABLE` migrations guard with `!$this->db->tableExists(...)` and provide a symmetric `safeDown()`.
