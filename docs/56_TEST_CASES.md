# 56. Test Cases (A to Z)

A complete manual and scripted test plan for Site7 Studio with Commerce24. It runs from a brand-new Craft site to a finished site installed from the Library, plus the author side that publishes the Library. Each case lists its steps, the expected result, how it was run, and the result of the last run.

**Setup for these tests**

| Role | Site | Commerce24 account |
|---|---|---|
| Author (builds and publishes the Library) | rp-craft, https://rp-craft.ddev.site (Dev Mode) | Site7 Author: Enterprise plan, key may publish, licence `C24-AUTHOR-0001` |
| Customer (installs from the Library) | site7-fresh, https://site7-fresh.ddev.site: new Craft 5.10.8.1, empty Library | Demo Customer: Business plan, licences `C24-DEMO-0001/0002` |
| Backend | Commerce24, https://commerce24.ddev.site | `/admin` (see `55` §2.1) |

Connecting a site: `55`. **How:** B = in the browser (Chrome), S = script or console against the real services, T = automated test (plugin `codecept`, Commerce24 `php artisan test`).

**Last run: 2026-10-05.** Results: ✅ pass, ❌ fail (then fixed, see the note), ⚠️ finding (works, but worth improving).

## A. Connection and account (Commerce24)

| # | Case | Steps | Expected | How | Result |
|---|---|---|---|---|---|
| A1 | Test connection | Settings → Commerce → Test Connection | "Connected to Commerce24." | B | ✅ rp-craft |
| A2 | Connection status | Account & License → Overview | Connected, plan, licence and subscription status, counts | B | ✅ rp-craft (Enterprise / Active / 97 packages) |
| A3 | Activate licence | License → enter key → Activate | Status Active, the site's domain under Activated Domains | S, B | ✅ both sites |
| A4 | Another account's key | Activate a key from another customer | Refused: "Commerce24: That license key is not on your account. (HTTP 422)" | S | ✅ |
| A5 | Website limit | Starter plan (1 site): activate one key on a 2nd site | Refused (422) until deactivated elsewhere | T | ✅ |
| A6 | Transfer / Refresh / Validate / Deactivate | License tab buttons | Licence moves or refreshes; deactivate frees the site | S, T | ✅ |
| A7 | Plans | Plan & Subscription | 4 plans from Commerce24, current one marked | B | ✅ |
| A8 | Change plan | Downgrade/Upgrade button | Plan changes in Craft and in the Commerce24 admin at once | B | ✅ Enterprise → Business → Enterprise |
| A9 | Cancel / Renew | Subscription buttons | Cancel removes plan access to paid packages, Renew restores it | S, T | ✅ |
| A10 | Manage Subscription link | "Manage Subscription in Commerce24 →" | Opens https://commerce24.ddev.site/portal/… | B | ✅ (❌ before: it pointed at the internal `ddev-commerce24-web` host; fixed in Commerce24) |
| A11 | Account tab | Account | Name, email, company of the Commerce24 customer | B | ✅ |
| A12 | Packages tab | Packages | Installed packages, purchases, add-ons, download history | B | ✅ |
| A13 | Wrong or revoked API key | Revoke the key in the admin, reload | 401, the plugin shows Commerce24's message | T | ✅ |
| A14 | Clear error messages | Any refused request | "Commerce24: <reason> (HTTP n)", not "Could not reach" | S | ✅ (fixed 2026-10-05) |

## B. Author: Library and publishing (rp-craft, Dev Mode)

| # | Case | Steps | Expected | How | Result |
|---|---|---|---|---|---|
| B1 | Dashboard | Site7 Studio → Dashboard | Counts per type, setup complete | B | ✅ 27 Sections, 68 Templates, 1 kit |
| B2 | Library lists | Library → Sections / Templates / Starter Kits | All packages, paging, New/Import buttons (Dev Mode) | B | ✅ 27 / 68 / 1 |
| B3 | No Patterns | Library sidebar, New Package, Template editor | No Pattern type anywhere | B | ✅ |
| B4 | Shared Resources | Library → Shared Resources | Registry list | B | ✅ 18 |
| B5 | Package page | Open a package | Details, version, status | B | ✅ |
| B6 | Package preview | Package → Preview | Rendered preview | B | ⚠️ Sections show "No Preview Available": Library Sections have no preview template or image (also grey cards in the Content Browser) |
| B7 | Editors | Edit a Section / a Template | Package Builder; Template Builder lists Sections only | B | ✅ |
| B8 | Set price | `library/pricing <handle> premium` | Manifest `pricingType` changes | S | ✅ kit premium |
| B9 | Publish Library | Publishing → Publish Library | Background job; changed packages get a new version, unchanged are skipped | B, S | ✅ 97 published (35 s); later "0 published, 97 unchanged" |
| B10 | Publish history | Publishing | 25 rows per page, handle shown | B | ✅ 1–25 of 228 |
| B11 | Only author keys publish | Publish with a customer key | 403 | T | ✅ |
| B12 | A version never changes | Publish the same version with other bytes | 409; same bytes accepted | T | ✅ |

## C. Customer: fresh site to finished site (site7-fresh)

| # | Case | Steps | Expected | How | Result |
|---|---|---|---|---|---|
| C1 | New site | New DDEV Craft 5.10.8.1, plugin with an empty Library, `.env` connected (`55` §5) | Site7 Studio installed, Library empty | S | ✅ |
| C2 | Install screen | Install | Kits from Commerce24 listed with price | B | ✅ "RP Craft v2.0.2 · Commerce24 · Premium" |
| C3 | Paid kit without plan | No plan: Check / download the kit | "Not in your plan"; Commerce24 403 | S, T | ✅ |
| C4 | Check | Install → Check | Lists what will be downloaded and installed | B | ✅ 91 packages, 248.9 MB |
| C5 | Install the kit | Install → Install | Job page: download (signature checked each), Theme (Composer, plugins, structure, settings), pages, menus, frontend build | B | ✅ "Starter Kit installed." (after the fixes in C6, C12, C13) |
| C6 | Setup before install | Run Setup (creates `site7Components`) first, then install the kit | The Theme replaces Setup's empty field | B | ❌ → ✅ It stopped with "fields 'site7Components' already exists here with a different UID"; fixed in `ThemeSchemaService` (placeholder replaced) |
| C7 | Pages work | Open the site's pages | 200, same content as rp-craft | S, B | ✅ 68 pages 200 (4 category URLs 301, same as rp-craft); text matches rp-craft except the site name and the Wheelform reCAPTCHA (its key is never copied) |
| C8 | Library after install | Dashboard, Library | All packages enabled and signed | B, S | ✅ 21 Sections, 68 Templates, 1 Theme, 1 kit: 91 enabled, all `verified` |
| C12 | Re-run after a stopped install | An install stopped after the plugin step; Install again | Already installed plugins are skipped | B | ❌ → ✅ It stopped with "plugin ai-chat failed: AI Chat is already installed"; `ThemeInstaller` now skips installed plugins (as `ThemeUpdater` already did) |
| C13 | Package row without its folder | A download was interrupted, its folder removed, its row left; Install again | The download restores the folder | B | ❌ → ✅ The import called it a conflict and skipped it while the log said "Downloaded", then the kit "installed" no pages. Now a row without a folder is restored, and `KitInstaller::applyKit()` stops if the kit's folder is missing |
| C14 | After install on the customer site | Updates; Account & License; Settings → Test Connection; entry → Add Section | Up to date; Connected / plan / Active; "Connected to Commerce24."; Content Browser | B | ✅ ⚠️ Overview shows "Package Usage 91 / 20": the plan's package limit is shown, not enforced |
| C9 | Updates | rp-craft publishes a change; customer → Updates | Newer versions listed; update keeps local edits | S | ✅ 31 updated on the previous site (Theme, kit, 29 Templates) |
| C10 | Signatures | Every downloaded package | `signatureStatus` verified, key `c24-local`, signed price kept | S | ✅ kit `verifiedPricingType` premium |
| C11 | Page builder | Entry → Add Section → Content Browser | Sections and Templates tabs, insert works | B | ✅ on rp-craft (27 Sections; no Insert Pattern) |

## D. Commerce24 admin

| # | Case | Steps | Expected | How | Result |
|---|---|---|---|---|---|
| D1 | Login | Password, or `commerce24:admin-link` | Admin dashboard | B, T | ✅ |
| D2 | Customers | Customers → customer | Plan, licences, purchases, API keys, downloads | B | ✅ |
| D3 | Package price | Packages → change price | The catalog changes; non-entitled sites see the package locked | B | ✅ accordion premium → rp-craft "Locked", back to free |
| D4 | Purchase | Customer → Add purchase | The package becomes downloadable for that customer | T | ✅ |
| D5 | API key | Create / Revoke | Shown once; a revoked key gets 401 | T | ✅ |
| D6 | Portal links | Portal URL | Signed; an unsigned or changed id gets 403 | T | ✅ |

## E. Permissions and safety

| # | Case | Expected | How | Result |
|---|---|---|---|---|
| E1 | Action requests need their own permission check (Craft checks plugin access only for CP pages) | Install/Update wizard steps, Setup, Settings test and kit install are admin only; Shared Resources need plugin access | S (code), B | ✅ (fixed 2026-10-05, `43` #22) |
| E2 | Paths from packages | A handle with `/`, `\` or `..` resolves to nothing; owned files only into frontend `src/`, never PHP | T | ✅ |
| E3 | Content Browser escapes package names and descriptions | No HTML injection from imported packages | B | ✅ |
| E4 | Unsigned paid package | Refused at import | T (`47`) | ✅ |
| E5 | Duplicate versions | DB unique index on (packageId, version) | S | ✅ |

## F. Automated suites

| # | Suite | Command | Result |
|---|---|---|---|
| F1 | Plugin unit tests | `ddev exec 'cd plugins/site7-studio && php ../../vendor/bin/codecept run unit -c codeception.yml'` (in rp-craft) | ✅ 198 tests |
| F2 | Commerce24 feature tests | `ddev exec php artisan test` (in commerce24) | ✅ 14 tests |

## Run of 2026-10-06 (new site `site7-qa`)

A second fresh customer site, https://site7-qa.ddev.site (Craft 5.10.8.1, empty Library, Demo Customer key), tested by HTTP requests against the real CP (one-time admin link) and the real Commerce24.

| # | Case | Result |
|---|---|---|
| G1 | Kit install with admin changes off | ❌ → ✅ stopped at "structure" (read-only project config); now refused at Check / before the Theme copies anything (`43` #29). With `.env` fixed, the re-run finished ("Starter Kit installed", already installed plugins skipped) |
| G2 | Account & License → Packages → Install a package not in the Library | ❌ → ✅ "Could not install"; now downloaded from Commerce24 and installed (`43` #28) |
| G3 | Delete a Section, Install it again from Packages | ✅ `map`, `cta-banner`: downloaded, signature verified, enabled, entry type restored |
| G4 | Every CP page: all screens and tabs, every package's page, preview and editor (97 packages) | ✅ 301 pages, HTTP 200, no errors |
| G5 | CP actions: Test Connection, licence activate/validate/refresh, check updates, portals, disable/enable/remove/install, export, repair, Content Browser data, import-tool lookups | ✅ |
| G6 | Publish Library with a customer key | ✅ refused by Commerce24 (403); now stops after the first refusal (`43` #30) |
| G7 | Section install warnings on a Library site | ❌ → ✅ "Shared Resource not registered" for fields the Theme created (`43` #31) |
| G8 | Frontend | ✅ 68 pages, no errors |
| G9 | Logs and queue | ✅ no plugin errors left; queue empty, 0 failed |
| G11 | Library → package → **Remove** a Section (`form`), then Install, Enable | ❌ → ✅ Remove deleted nothing; now block type, template and page-builder link are gone, the package stays "Available"; Install rebuilds, Enable relinks (config and database). Refused for `heading-content` (27 entries) (`43` #32) |
| G12 | Disable an installed Starter Kit | ❌ → ✅ allowed, site unchanged, Install screen said "Not installed"; re-install added no duplicates and kept an edited title. Now refused, hidden, and the Install screen shows whether each kit fits (`43` #33) |
| G13 | Library updates, two rounds (author: `headingContent` + `buttons` templates, About Us + Web Development titles; customer: edited `buttons` template + Web Development title) | ✅ 4 published (93 unchanged) each round; author changes arrived, customer edits kept ("kept your version"), backup + signatures, pages 200, project config clean, re-run 0 updates. ❌ → ✅ updates showed only after the 5-minute catalog cache expired (`43` #34) |
| G14 | Browser (Chrome): Settings test, Account & License tabs, Install, Updates, package Install/Enable/Disable/Remove, Content Browser Sections and Templates, frontend | ✅ no errors. Template Insert ❌ → ✅: was empty blocks; now Home (general) inserts 5 blocks with content, a blog post (detail) inserts layout + styles with text and image cleared, a 0-block template has no Insert (`43` #36) |
| G15 | Theme ships its built frontend: update 1.1.2 → 1.1.3 on an installed site, then a fresh site + kit install | ✅ update: 4 built files added, the site's own manifest kept, pages and assets 200; fresh install: "npm not needed", no node_modules, all CSS/JS 200, 6 min |
| G16 | Plan package limit (Business limit set to 0, 1, back to 50) | ✅ kit's 90 packages don't count (0 extra); limit 0 refuses `contact`; limit 1 installs `contact`, refuses `map`; reinstall allowed; Overview "Extra Packages 1 / 50" |
| G10 | Unit tests (F1) | ✅ 198 tests |

## Findings from this run

1. **Fixed:** Starter Kit install stopped on a fresh site when Setup had run first (C6).
2. **Fixed:** re-running a stopped kit install failed on plugins it had already installed (C12).
3. **Fixed:** a package row left without its folder made a download silently skip and the kit install nothing (C13).
4. **Fixed in Commerce24:** "Manage Subscription" opened an internal address (A10).
5. **Open:** Library Sections have no preview (B6). Previews (`preview/preview.twig` or an image) would make the Content Browser and package pages show the block.
6. **Open:** the plan's package limit ("Package Usage 91 / 20") is displayed but nothing enforces it (C14). Decide whether Commerce24 or the plugin should, or whether a kit's packages don't count.
