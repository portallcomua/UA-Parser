# UA Parser 5.0 — development branch

This branch contains an early alpha foundation for a universal page data collector and WordPress/WooCommerce publisher.

## Current alpha scope
- Analyze one public HTTP/HTTPS page.
- Extract title, description, a price candidate and image URLs.
- Store collected data temporarily per administrator.
- Export CSV using semicolon delimiter.
- Publish as a WordPress post, page, or simple WooCommerce product.
- Avoid duplicates using the source URL metadata.

## Not implemented yet
Visual click-to-select, repeated-card discovery, crawling categories/pagination/sitemaps, batch queue, saved selector templates, full product gallery/attributes/SKU, CMS export profiles, English localization, Freemius and GitHub updater.

## Safety and testing
This is an alpha build. Test on a staging site and publish as drafts first. Respect the source site's terms and content rights. Do not use it to bypass login, CAPTCHA, or access controls.
