# Homepage Template

A Template package that composes the `about-company` Pattern (Hero Banner + Team)
with the `pricing` and `faq` Sections into a single, orderable Section list.

Templates are not Craft Entry Types or Matrix Entry Types, and are never stored as
content. Installing this Template automatically installs and enables its required
Patterns and Sections. Inserting it creates ordinary Section blocks in the
configured Matrix field, in this order:

1. Hero Banner (from `about-company`)
2. Team (from `about-company`)
3. Pricing
4. FAQ

After insertion, only those Section blocks exist — the Template package itself is
never linked to the Matrix field and disappears from the resulting content.
