# 00 — Overview

## What is SITE7 Studio

SITE7 Studio is a Craft CMS plugin (Composer package `site7/studio`, PHP namespace `site7\studio`) that turns pieces of a Craft site — a Section (a Matrix block type), a Page, or an entire Website — into **packages**: versioned, exportable, re-installable units of content and configuration that can move between Craft sites, be updated later without destroying local customizations, and be rolled back to any earlier recorded version.

## The problem it solves

Craft CMS has no native concept of "take this component I built on Site A, ship it, and later update Site B's copy safely." Raw Project Config YAML has no versioning, no per-file change detection, and no rollback. SITE7 Studio adds exactly that layer, built entirely on top of Craft's own APIs (Fields service, Entries service, Project Config), never replacing them.

## Who uses it

Developers/agencies maintaining multiple Craft sites who want to reuse and safely update components across projects, and — via the optional Marketplace/Commerce layer — potentially a broader catalog of installable, licensed packages.

## What a package is

A directory, `packages/{handle}/`, containing a `manifest.json` plus type-specific content: captured Craft field definitions (`fields.yaml`), Matrix/Entry Type configuration (`matrix.yaml`), a real Twig template (`template.twig`), optionally explicitly-owned frontend files, and preview assets. Every package has a `type` (`section`, `template`, `pattern`, `starter-kit`, `theme`), a globally-unique kebab-case `handle`, and a semantic `version`.

## Relationship to Craft CMS

SITE7 Studio is a standard Craft plugin. It creates/updates real Craft resources (Fields, Entry Types) through Craft's own service APIs (`Craft::$app->getFields()`, `Craft::$app->getEntries()`), and touches Project Config only via `Craft::$app->getProjectConfig()->rebuild()` — it never hand-writes `config/project/*.yaml`.

## Relationship to the host website

A package's real, live-rendered output is an ordinary Craft site template file: `templates/_blocks/{handle}.twig`. SITE7 Studio installs *into* the site's existing rendering system — see `13_TEMPLATE_ARCHITECTURE.md` for why this is architecturally the single most important rule in the whole plugin.

## Relationship to Marketplace/Commerce

Fully separable. The core package engine works with zero Commerce configuration (a `LocalMarketplaceRepository` — a folder on disk — is always available). Commerce24 (`23_MARKETPLACE_ARCHITECTURE.md`, `24_LICENSING_AND_COMMERCE.md`) is an optional remote integration that gates *additional* packages behind entitlements; it never gates the core lifecycle.

## High-level product lifecycle

```
Create or Import  →  Version (immutable, archived)  →  Publish (optional)  →  Install
   →  Baseline recorded  →  Develop further  →  Sync (new version)
   →  Update (safe/conflict-aware)  →  Rollback (if needed)  →  Uninstall / Delete
```
Full detail: `06_PACKAGE_ARCHITECTURE.md` and the master lifecycle diagram in `99_COMPLETE_ARCHITECTURE_MAP.md`.

## How to use this documentation set

This is a **developer-first technical reference**, not a shallow README. Every statement in every document here is based on direct inspection of the current source code as of this writing (2026-08-17, immediately after the Step 8.2 owned-files work, commit `438ce75`). Where the code's own intent (docblocks/comments) differs from what it actually does, both are stated and the discrepancy is called out — never silently reconciled.

- New to the plugin? Read `01_ARCHITECTURE.md` and `02_DIRECTORY_STRUCTURE.md` next.
- About to change code? Read `41_AI_DEVELOPER_GUIDE.md` first, then the specific numbered document for the subsystem you're touching.
- Debugging? Go straight to `39_TROUBLESHOOTING.md`.
- Need the full picture in one place? `99_COMPLETE_ARCHITECTURE_MAP.md`.

## Relationship to other documentation in this repository

This `docs/site7-studio/` directory is a **new, independent documentation set**. It does not update, merge into, or depend on any pre-existing Markdown files in this project (`plugins/site7-studio/docs/*.md`, `plugins/site7-studio/docs/SITE7_STUDIO_ARCHITECTURE.md`, or the repo-root `docs/*.md`). Older documents may describe intentions, historical phases, or architecture that no longer matches the current code — they were treated as leads to verify against the source, never as ground truth. See `43_KNOWN_ISSUES_AND_TECHNICAL_DEBT.md` for the specific discrepancies found during this audit.
