# 57. Authoring guide: blocks, pages, kits and plans

How to create a block, a page or a kit on the author site, put a price on it, publish it, and decide which plan gets it. Step by step, with the commands. The why behind each piece is in the docs linked at each step.

All commands run on the author site (rp-craft) with `ddev craft …`, unless a step says Commerce24.

## 1. The pieces, from the bottom up

| Piece | What it is | Package | Made with |
|---|---|---|---|
| **Theme** | Structure, header, footer, templates, frontend, settings. Every site has exactly one. | `rp-craft-theme` ("Site7 Theme") | `theme/build` (`49`) |
| **Block** | One block in the page builder (an entry type of `matrixContent` + its `_blocks/<handle>.twig`) | Section package, e.g. `accordion` | Import Existing Section (`14`) |
| **Page** | One page's content, exactly as on rp-craft | Template package, e.g. `template-home` | `template/build` (`50`) |
| **Kit** | A set of pages for a customer to install | Starter Kit package, e.g. `blog-pack-starter-kit` | `starter-kit/build` (`51`) |
| **Plan** | What a customer may install | Commerce24 plan | Commerce24 admin → Plans (`24` §10c) |

A kit requires its pages, a page requires its blocks, and everything requires the Theme. Installing a kit installs all of that.

There are three kinds of kit:

| Kind | Sets up the site? | Menus | Example |
|---|---|---|---|
| **Base kit** | yes, with a few pages | only items for its pages | Default Kit: Home, About Us, Contact (free) |
| **Page pack** | no, it adds pages to a site a kit set up | none, the customer adds links | Blog Pack, Pricing Pack… |
| **Full kit** | yes, with every page | all of them, plus demo content | Site7 Full Kit (`rp-craft-starter-kit`) |

On a site without a kit, installing a pack installs the Default Kit first.

## 2. Before you start

- Work on **rp-craft** with Dev Mode on (Site7 Studio → Settings → General). The Library screens for authoring only show in Dev Mode.
- rp-craft is connected to Commerce24 with a **publisher** API key (`55`). `ddev craft site7-studio/library/catalog` should list the packages.
- Settings → General → Page Builder Field is `matrixContent`.
- Build the frontend (`npm run build` in `frontend/`) before you rebuild the Theme. The Theme ships the built files (`49` §2b).

## 3. Create a block (Section package)

1. **Build it in Craft on rp-craft.** Add an entry type to the `matrixContent` field, with its fields, and write `templates/_blocks/<entry type handle>.twig`. Try it on a page.
2. **Import it into the Library.** Library → Import Existing Section → pick the block type. Alternatively, `ddev craft site7-studio/import/sections` imports every block of the page builder that isn't in the Library yet (`--dry-run` first). The package is `packages/<handle>/` with `schema.json`, `template.twig` and `manifest.json`.
3. **Customer texts.** In `packages/<handle>/manifest.json`, set `description` (one sentence on what the block shows) and `category`. Customers see both in the Content Browser.
4. **Preview.** Put a screenshot of the block at `packages/<handle>/preview/preview.png`.
5. **Price it.** Leave it free, or make it premium so only plans get it (§6):
   ```
   ddev craft site7-studio/library/pricing <handle> premium
   ```
6. **Publish it** (§7).

**Changing a block later:** change it on rp-craft, open its package in the Library and click Sync From Source, then publish. Customers see the new version under Updates. Their own edits are kept (`53`).

## 4. Create a page (Template package)

1. Make the page on rp-craft as a normal entry, built from Library blocks only. A block that isn't in the Library stops the build.
2. Build its package:
   ```
   ddev craft site7-studio/template/build <single handle> | <section>/<slug>
   ddev craft site7-studio/template/build standardPages/price
   ```
   The handle is `template-<section>-<slug>`, or `template-<single>` for a single.
3. **A simpler version of a page** (a variant), for a kit whose site doesn't have what some blocks show:
   ```
   ddev craft site7-studio/template/build home --variant=default --without-blocks=services
   ```
   This gives `template-home-default`, the same page without the Services block. A kit uses it only with `--variant=default` (§5.3).
4. Price it if it belongs to a paid pack (§6), then publish.

Building a full kit rebuilds every page first, so you only need step 2 for pages you build into packs.

## 5. Create a kit

### 5.1 Sections first: base or optional?

The Theme installs only its **base sections** (Home, Contact, Standard Pages, Sitemap, the error and maintenance pages, General, Header, Footer, Theme Settings, Color and Font Library, Additional CSS & JS, Google Structure Data, LLMs Text). Every other section is **optional**. It's created on a customer site only when a page or kit that needs it is installed, so customers see only the sections they use (`49` §2c).

- A page's own section always comes with it.
- A pack lists any **other** sections it needs with `--sections`: categories, reviews, or data its pages show. For example, the Blog Pack needs Blog Categories and Blog Review, and the Pricing Pack needs Packages, Package Features and Feature Groups.
- **New section on rp-craft?** Rebuild the Theme so it's in the Theme package: `bash .claude/build-packs.sh --theme`. It becomes optional automatically. To make it a base section, add its handle to `BASE` in that script.

### 5.2 A page pack

```
ddev craft site7-studio/starter-kit/build "Blog Pack" \
  --pages=blogs,authors,standardPages/blogs \
  --sections=blogCategories,blogReview
```

- `--pages` takes whole sections (`blogs`: every page in it) and single pages (`standardPages/blogs`: section/URI).
- The handle comes from the name: "Blog Pack" gives `blog-pack-starter-kit`.
- The pages' Template packages must exist already (§4). A pack never rebuilds them.

### 5.3 A base kit

```
ddev craft site7-studio/starter-kit/build "Default" \
  --pages=home,contact,standardPages/about-us --base --variant=default
ddev craft site7-studio/library/rename default-starter-kit "Default Kit"
```

`--base` keeps the menu and sitemap items that point at its pages and drops the rest. `--variant=default` uses `template-home-default` where it exists.

### 5.4 The full kit

```
ddev craft site7-studio/starter-kit/build "RP Craft"    # rebuilds every page first; --templates=0 to skip
```

### 5.5 Names

The build argument decides the **handle**. The name customers see is set once with `library/rename`, and rebuilds keep it:

```
ddev craft site7-studio/library/rename <handle> "Name customers see"
```

Never change a published handle: plans, installed sites and update history use it.

### 5.6 Keep the build script in step

`rp-craft/.claude/build-packs.sh` rebuilds the Default Kit and every pack. Add a new pack there as a `b "<Name>" "<pages>" "<sections>"` line, so the next rebuild includes it.

## 6. Prices: free or premium

| `pricingType` | Who gets it |
|---|---|
| `free` | Every customer, on every plan |
| `premium` | Customers whose plan includes it, or who bought it |

```
ddev craft site7-studio/library/pricing <handle> premium
```

- A plan, or a purchase, also brings **everything the package requires**. A plan with the Blog Pack gets the blog pages and their blocks, even premium ones. You don't list those in the plan.
- So make a pack **and the pages in it** premium. A free page could otherwise be installed on its own without the pack.
- Make a block premium when it's sold separately or only with higher plans. Today these are premium: Accordion, Testimonials, Pricing and Compare, Image Gallery and Products.
- A rebuild keeps the price. The Commerce24 admin can also change a price after publishing.

## 7. Publish to Commerce24

```
ddev craft site7-studio/library/publish --notes="What changed"            # everything that changed
ddev craft site7-studio/library/publish blog-pack-starter-kit,accordion   # only these
```

Each package changed since its last publish gets a new version (patch by default; `--bump=minor` or `--bump=major`). An unchanged package isn't published again. Customers get new versions under Site7 Studio → Updates.

## 8. Assign to a plan (Commerce24)

1. Open the Commerce24 admin → **Plans**.
2. For each plan, set:
   - **Included packages:** the handles of the kits, packs and premium blocks the plan gets, separated by commas. Don't list their pages or blocks; they come with the kit. `*` means every package, now and in future.
   - **Extra packages:** how many packages a site may install **beyond** what its kits brought (empty = unlimited).
3. Save. Customer sites see the change on their next refresh of Account & License. On a downgrade, what the plan no longer covers is disabled with 14 days' grace, but a kit that set up the site stays (`24` §10b).
4. Keep `commerce24/database/seeders/PlanSeeder.php` in step. It's the record of the plans, and production is seeded from it (`php artisan db:seed --class=PlanSeeder --force` resets the plans to it).

Today's plans:

| Plan | Included packages | Extra packages |
|---|---|---|
| Starter | Blog Pack | 5 |
| Professional | + Services, Portfolio and Team packs, Accordion, Testimonials | 20 |
| Business | + Products, Pricing, Case Studies, Gallery and Testimonials packs, Site7 Full Kit, Pricing and Compare, Image Gallery, Products | 50 |
| Enterprise | `*` | unlimited |

The Default Kit is free, so it isn't in any plan.

## 9. Check it on a fresh site

1. `bash ~/my-project/rp-craft/.claude/rebuild-site7-qa.sh` builds site7-qa from scratch, connected to Commerce24 as the Demo Customer.
2. Put the Demo Customer on the plan you want to test: Commerce24 admin → the Demo Customer's page → Subscription.
3. On site7-qa: Site7 Studio → Install → your kit. You can also use `ddev craft site7-studio/starter-kit/install <handle>`, or Account & License → Packages for a pack.
4. Check:
   - **Entries** shows only the base sections plus the kit's.
   - **Account & License → Packages** shows what other plans would unlock as "Locked".
   - **Extra Packages** on the Overview counts only what's installed beyond the kits.
   - Every page of the kit loads, and the menu is right.

## 10. Checklists

**New premium block**
1. Build it on rp-craft (§3.1).
2. Import it into the Library.
3. Add its description, category and preview.
4. Run `library/pricing <handle> premium`.
5. Publish it.
6. Add its handle to the plans that get it (Commerce24 admin and PlanSeeder).

**New page pack**
1. Make sure its pages are built from Library blocks.
2. Run `template/build` for each page.
3. Run `starter-kit/build "<Name>" --pages=… --sections=…`, and add the line to `build-packs.sh`.
4. Run `library/pricing` (premium) for the pack and its pages.
5. Publish.
6. Add the pack to the plans that get it.
7. Test it on a fresh site.

**New page in an existing pack**
1. Make the page on rp-craft.
2. Run `template/build` for it.
3. Rebuild the pack (`build-packs.sh`).
4. Make the page premium.
5. Publish. Sites with the pack get the page as an update.

**New section on rp-craft**
1. Rebuild the Theme with `bash .claude/build-packs.sh --theme`.
2. Add the section to the `--sections` of the packs that need it.
3. Publish.

## 11. Rules that save trouble

- Build on rp-craft only; never author on a customer site.
- Publish after every change you want customers to get. A change that's built but not published doesn't reach them.
- Don't rename handles or delete published packages: installed sites and plans refer to them.
- Pages may link to pages in other packs. A link whose page isn't installed is skipped, and it's added when the other page arrives.
- A section is never removed from a customer site: not by a downgrade, a removed pack or a Theme update.
- After a change, test on a fresh site (§9). Unit tests don't catch what a real install does.
