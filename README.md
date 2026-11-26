# UA Parser 5.0

Universal public-page content collector and importer for WordPress and WooCommerce.

This is an actively developed branch, not a claim of full completion. The plugin has a CMS-independent HTML extraction, visual preview/selector foundation, AJAX crawl queue, CSV export, WordPress/WooCommerce publishing and GitHub release updater. The source website may use any CMS or no CMS.

## Remaining work before the full specification is complete

- Reliable semantic field selection and repeated product/article card mapping.
- Editing collected records before export or publishing.
- Manual editing of collected records, image order and main-image selection.
- Broader extraction of variants from non-standard JavaScript product configurators and validation on real stores.
- Robust category/pagination/sitemap crawling and per-item error reporting.
- Verified export templates for target CMS and marketplaces.
- Complete Ukrainian/English localization and comprehensive runtime/security testing.

## Beta.7 extraction scope

The universal extractor reads HTML and structured data (JSON-LD, Open Graph, microdata), lazy-loaded images, gallery links, native option controls and common swatch attributes. It preserves multiple attribute values and ProductGroup variants when the source exposes them. Destination publishing is handled separately; the source CMS is not assumed to be WordPress.

## Project policy

UA Parser contains no payment processing, Freemius integration, license checks, paid plans or paid feature limits.

Use only public pages you are authorized to collect. Test imports on a staging WordPress site and publish as drafts before production use.
