# Site7 Studio: getting started

Site7 Studio turns a fresh Craft CMS site into a finished website from the Site7 Library, and gives your editors a page builder with ready-made sections and page templates.

This guide takes you from an empty Craft install to a working site, then covers everyday use: building pages, adding packages and installing updates.

## 1. What you need

- **Craft CMS 5** on a fresh install, with **MySQL** (or MariaDB). Install on a new site: a Starter Kit sets up the whole site and won't install where content already exists.
- **PHP 8.2** or newer, with the `zip` and `sodium` extensions.
- **Composer** on the server. Node and npm are **not** needed.
- Admin changes allowed: in `.env`, `CRAFT_ALLOW_ADMIN_CHANGES=true` (or `CRAFT_ENVIRONMENT=dev`) while you install.
- From Site7: your **licence key** and your site's **connection details** (an API key and a few `.env` lines).

## 2. Install the plugin

Add Site7 Studio to your Craft project the way Site7 sent it to you, then:

```bash
php craft plugin/install site7-studio
```

**Site7 Studio** now appears in the control panel menu, with an empty Library.

## 3. Connect to Site7

Add the lines Site7 sent you to your site's `.env`:

```
COMMERCE24_API_ENDPOINT=https://...
COMMERCE24_API_KEY=...
COMMERCE24_STORE=your-domain.com
COMMERCE24_ENVIRONMENT=production
```

Then, in the control panel:

1. **Site7 Studio → Settings → Commerce → Test Connection** should say *Connected to Commerce24*.
2. **Site7 Studio → Account & License → License:** enter your licence key and click **Activate**. The status becomes *Active*, with your domain listed.

## 4. Install your Starter Kit

1. Go to **Site7 Studio → Install**. Your Starter Kits are listed, with what each one includes.
2. Click **Check**. It lists what will be downloaded and installed, and anything that would stop the install (for example, admin changes switched off).
3. Click **Install**. A progress page shows each step: download, Theme, plugins, structure, pages, menus. It takes a few minutes; you can leave the page open.
4. When it says **Starter Kit installed**, open your site's front end: every page is there, with its content and design.

After installing, the progress page may list `.env` settings the site can use, such as payment or reCAPTCHA keys. Add the ones you need, with your own values.

A site can have one Theme. A second Starter Kit built on the same Theme adds its pages to your site and keeps the pages you already have; a kit with a different Theme needs a fresh site. The Install screen shows which applies before you click anything.

## 5. Build pages

Open any page in **Entries**. In its page builder, click **Add Section** to open the **Site7 Content Browser**:

- **Sections:** single blocks (a hero banner, a gallery, buttons, testimonials…). Click **Insert** to add one at the end of the page.
- **Templates:** whole pages from the Library.
  - A general page (Home, About, Contact…) is inserted **with its content**: replace the text and images with your own.
  - A detail page (a blog post, a product, a service…) brings its **layout and styles** only, ready for your own text and images.

Inserted sections are part of your page draft until you **Save**.

## 6. The Library

**Site7 Studio → Library** lists every block and page you have, with buttons on each package:

| Button | What it does |
|---|---|
| **Install** | Sets the package up on your site |
| **Enable** | Makes a block available in the Content Browser and the page builder |
| **Disable** | Hides a block from editors; nothing is deleted, and Enable brings it back |
| **Remove** | Deletes the block's fields, block type and template from your site, and keeps the package in your Library so you can install it again |

Disable and Remove are refused while pages still use the block. Your Starter Kit and Theme can't be disabled or removed: they set up your site.

## 7. Your plan

**Site7 Studio → Account & License** shows your plan, licence and subscription.

- **Packages** lists everything your account owns and everything else in the Site7 Library. Packages your plan includes show **Install**, even ones you deleted earlier.
- **Extra Packages** (on the Overview) counts packages installed beyond your Starter Kit against your plan's limit. The kit's own Theme, pages and blocks don't count. At the limit, remove a package or upgrade your plan to add another.
- **Plan & Subscription** lets you upgrade, downgrade, renew or cancel, or open your account at Site7.

## 8. Updates

When Site7 improves a block, a page or the Theme, **Site7 Studio → Updates** lists the new versions with their release notes.

Updating is safe for your work:
- the database is backed up first;
- only what you haven't changed is updated. A block template, a field or a page you edited keeps your version, and the update report says so (*kept your version*).

## 9. If something goes wrong

| You see | What to do |
|---|---|
| *Could not reach Commerce24* | Check `COMMERCE24_API_ENDPOINT` in `.env`, and that your server can make outgoing HTTPS requests. |
| *Missing or invalid API key (HTTP 401)* | The API key in `.env` is wrong or was revoked. Ask Site7 for a new one. |
| *That license key is not on your account* | Use the licence key Site7 sent for this site. |
| *Admin changes are turned off on this site* | Set `CRAFT_ALLOW_ADMIN_CHANGES=true` in `.env`, then run Check again. |
| *This site already has content structure* | Starter Kits install on a fresh Craft site. Start from a new install. |
| *This kit is for Craft 5.x* | Your site runs a different major Craft version than the kit. Ask Site7 for a kit for your version. |
| *Not in your plan* / *is not included in your current plan* | Upgrade your plan, or buy the package from Site7. |
| *Your plan includes N packages beyond your Starter Kit* | Remove a package you don't use, or upgrade your plan. |
| *signature … untrusted* or *unsigned* | The download couldn't be verified, so it wasn't installed. Contact Site7. |
| *The page '…' isn't on this site yet* (inserting a template) | Install that page from **Library → Templates** first. |

For anything else, send Site7 the message you see and the last lines of the progress page or of `storage/logs/`.
