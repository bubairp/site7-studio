# 44 — Content Browser Matrix Injection

## 1. Purpose

Document how the "Add Section" / "Insert Pattern" buttons and the "Site7 Content Browser" modal are injected into a Matrix field's Control Panel UI, and the constraint that injection must respect to avoid colliding with Craft's own native field JS.

## 2. What It Does

`pattern-matrix.js` polls the CP DOM for Matrix fields (`div.matrix, .nested-element-cards`) and, for the field whose handle matches `window.site7Studio.matrixFieldHandle` (set by the CP template, see §6), injects a `site7-btn-group` containing "Add Section" and "Insert Pattern" buttons next to Craft's own native add-entry button, then hides the native button via CSS. Clicking either button opens `pattern-browser.js`'s `Site7PatternBrowser` modal (a `Garnish.Modal` styled as a `cs-modal`), which lets the author browse Sections/Patterns/Templates and insert one into the field.

## 3. Current Status

**Implemented.** A bug where clicking "Add Section" could silently also create a native block (see §11) was found and fixed on 2026-08-18.

## 4. Architecture

```
PatternInserter (Garnish.Base, instantiated once on $(document).ready())
   ↓ setInterval(500ms): pollForMatrixFields()
   ↓ for each div.matrix / .nested-element-cards on the page:
injectButton($matrixContainer)
   → matches field by window.site7Studio.matrixFieldHandle
   → finds $btnContainer (.buttons, or .flex-inline fallback)
   → builds $btnGroup (Add Section / Insert Pattern) and inserts it
     as a SIBLING of $btnContainer (NOT a child — see §11)
   → adds .site7-matrix-override class, which triggers injected CSS
     to hide $btnContainer's own (native) children
   ↓ click "Add Section" / "Insert Pattern"
openPatternModal() → new Site7PatternBrowser(defaultTab, onSelectCallback)
   ↓ user selects a card → onInsertClick() → hide() → onSelectCallback(handle, type, ...)
insertSection() / insertPattern() / insertTemplate()
   → Craft 5 Cards/NestedElementManager: manager.createElement(attributes)
   → Craft 4 / "Blocks" viewMode MatrixInput: finds the native per-type
     add button (`.buttons .btn[data-type="..."]`) and triggers it
```

## 5. Execution Flow

1. On CP page load (or after a slideout/PJAX navigation), `PatternInserter.pollForMatrixFields()` runs every 500ms, scanning for Matrix field containers.
2. `injectButton()` matches the target field via `window.site7Studio.matrixFieldHandle` (set inline by the CP template rendering the entry edit page — search the CP template for `site7Studio` to find where it's assigned) and injects the button group once per container.
3. Clicking "Add Section" or "Insert Pattern" opens `Site7PatternBrowser` (`pattern-browser.js`), which loads available content via `site7-studio/package-action/get-browser-data` and renders cards.
4. Selecting a card's "Insert" button calls back into `pattern-matrix.js`'s `insertSection()`/`insertPattern()`/`insertTemplate()`, which create the actual Matrix entry/block using whichever native API the field's current View Mode exposes (`resolveCreateAttributes()` handles both the Craft 5 NestedElementManager array-of-types shape and the single-object shape used when a field allows exactly one Entry Type — see the extensive comment at `pattern-matrix.js:120-179` for why both shapes must be handled).

## 6. Important Classes/Files

**`PatternInserter`** — `src/resources/js/pattern-matrix.js`. Polls for and injects the button group; owns `insertSection()`/`insertPattern()`/`insertTemplate()`/`resolveCreateAttributes()`.
**`Site7PatternBrowser`** — `src/resources/js/pattern-browser.js`. The `Garnish.Modal` subclass implementing the "Site7 Content Browser" UI (Sections/Patterns/Templates tabs, category sidebar, search, card grid).
**`PatternMatrixBundle`** — `src/assetbundles/PatternMatrixBundle.php`. Publishes both JS files (and others) as-is — **no build/bundling step**; editing the `.js` source directly is sufficient, but Craft's Yii `AssetManager` caches the published copy under a content-hashed `web/cpresources/<hash>/` directory and will keep serving a stale copy until that cache is cleared (`ddev craft clear-caches/all`, specifically the "Control panel resources" cache) — see §11.
**`window.site7Studio.matrixFieldHandle`** — inline JS config telling `pattern-matrix.js` which Matrix field on the current page to target; set by the CP template.

## 7. Data Model

Not applicable — no database tables. `localStorage['site7-recently-used']` stores up to 10 `{handle, type, timestamp}` entries for the modal's "Recently Used" category.

## 8. Filesystem Impact

None directly. Block/entry creation goes through Craft's own `elements/create` + `elements/save-draft` actions (Craft 5 path) or triggers Craft's native Matrix block-creation UI (Craft 4/"Blocks" viewMode path) — no files are written by this feature itself.

## 9. Events

None custom. Uses Garnish's built-in `Modal` `hide` event and jQuery `click`/`input` events.

## 10. Validation and Safety

`resolveCreateAttributes()` deliberately never falls back to "the field's only allowed type" unless it can positively confirm that's the type the caller actually asked for (matched by `typeId`, or by normalized label/handle) — see the comment at `pattern-matrix.js:144-156`. This was a deliberate fix for a prior bug where a field with exactly one allowed Entry Type would silently substitute that type for whatever the caller requested.

`insertSection()`'s Craft-4/"Blocks"-viewMode fallback search for the native add button is scoped to the current field's own container (`matrixInstance.$container.find(...)`), never the whole document — an earlier version searched page-wide and risked matching an unrelated field's identically-named block type, silently inserting content into the wrong field.

## 11. Failure Scenarios

**Fixed 2026-08-18 — clicking "Add Section" silently created an unwanted block on its own (independent of the modal).**

Root cause: Craft's own Matrix field JS locates its native add-entry button with a selector scoped to the *whole* `.buttons` container, not just its own button — e.g. classic/"Blocks"-viewMode `MatrixInput.js`:
```js
this.$addEntryBtnContainer = this.$container.children(".buttons"),
this.$addEntryBtn = this.$addEntryBtnContainer.find(".btn:not(.menubtn)"),
```
`injectButton()` used to append the `site7-btn-group` *inside* that same `.buttons` container, and its buttons carried Craft's own `btn` class (needed at the time for visual styling). Craft's `.find(".btn:not(.menubtn)")` therefore matched Site7's buttons too — depending on init-order timing, Craft bound its own native `click`/`keydown`/`activate` handlers directly onto Site7's "Add Section" button, in addition to Site7's own handler. When a Matrix field has only one allowed Entry Type (as happens after manually editing the field to `viewMode: blocks` and deleting all-but-one Entry Type — see reproduction context), Craft's native handler is a plain "instant add" action with no confirmation step, so clicking "Add Section" silently kicked off an async `elements/create` request for that one type in the background. Because it's async, the resulting block appeared moments later — which looked like *closing* the modal was what caused it, when actually the "Add Section" click itself had already triggered it.

A prior fix attempt (still visible in git history / superseded comments) avoided reusing Craft's `add` class specifically and added `e.stopPropagation()` as defense-in-depth. Neither was sufficient: Craft's real selector (`.btn:not(.menubtn)`) matches on the generic `btn` class, not `add`, and `stopPropagation()` only stops an event bubbling to *ancestors* — it does nothing to prevent a second, independent handler bound directly to the *same* element from also firing.

**Fix**: `site7-btn-group` is now inserted as a **sibling** of `.buttons`/`.flex-inline` (`$btnContainer.after($btnGroup)`), not a child of it, and its buttons no longer carry Craft's `btn` class (visual parity is replicated via inline styles instead). This makes it structurally impossible for Craft's `.buttons`-scoped `.find()` to ever match Site7's buttons, regardless of DOM-injection timing relative to Craft's own field init. The CSS override that hides the native button was updated to match (`.site7-matrix-override .buttons > *` — no longer needs a `:not(.site7-btn-group)` exclusion, since that element isn't a child of `.buttons` anymore).

**If this class of bug recurs** (a Site7-injected control unexpectedly triggering native Craft field behavior): suspect a shared CSS class between a Site7 element and whatever selector Craft's own field JS uses to find its native controls, *especially* when that Site7 element lives inside a container Craft's JS also scopes its own selector to. Verify by patching the suspected native method (e.g. `mi.addEntry = function(...) { console.trace(); return orig.apply(this, arguments); }`) live in DevTools and reproducing.

**Caches to clear after editing these JS files**: `ddev craft clear-caches/all` (or specifically the "Control panel resources" cache) — Yii's `AssetManager` publishes `src/resources/js/*` to a content-hashed `web/cpresources/<hash>/` directory and does not automatically detect source-file changes; without clearing this cache the CP will keep serving the pre-edit copy indefinitely.

## 12. Developer Change Guide

If adding a new injected control near a native Craft field control: never place it *inside* a container Craft's own field JS scopes a selector to (commonly `.buttons`, `.flex-inline`), and avoid Craft's generic utility classes (`btn`, `add`, `menubtn`) on it — either keep it as a sibling (as this fix does) or use only Site7-prefixed classes. After editing any file listed in `PatternMatrixBundle::$js`, clear the CP resources cache (§11) before testing in a browser — otherwise you will be testing stale code.

## 13. Related Features

`29_CP_UI_ARCHITECTURE.md` (unrelated CP nav/permission registration, different subsystem), `08_PACKAGE_AUTHORING.md`, `13_TEMPLATE_ARCHITECTURE.md` (block-type-to-template relationship referenced by `insertSection()`'s Craft-4 fallback path), `39_TROUBLESHOOTING.md` §13.

## 14. Known Limitations

`insertPattern()`/`insertTemplate()`'s Craft-4/"Blocks"-viewMode fallback path (`createBlocksSequentially()`) creates blocks by triggering native add-button clicks with a fixed 500ms delay between each — this is a polling/timing-based approach, not an awaited completion signal, and could in principle race under heavy server latency. Not confirmed to have caused a real failure.
