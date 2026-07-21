# Silverstripe Theme Transfer

Copy a site's theme setup — colours, fonts, layout and typography settings — between servers as JSON. Built for cloning a local or production theme onto staging without touching content.

Adds a **Theme Transfer** tab to Site Config with an export box to copy from and a paste box to import into. No routes, no public endpoints, no files written to `public/`.

## Requirements

- PHP 8.3+
- Silverstripe 6
- `toastnz/colourpalettes` ^6.0
- `toastnz/theme-fonts` ^6

Both module versions must match across the two sites you are transferring between. The payload carries a version number and the import warns on a mismatch, but an older site will not understand a newer payload.

## Installation

```bash
composer require toastnz/theme-transfer
```

Then run `dev/build?flush=1`.

## Configuration

The module knows about colours and fonts on its own. Everything project-specific is configured:

```yaml
Toast\ThemeTransfer\Services\ThemeTransferService:
  # Classes whose $db is harvested for theme settings, and whose has_one
  # relations pointing at a Colour become transferable colour slots.
  setting_sources:
    - Toast\Extensions\SiteConfigThemeExtension

  # Extra SiteConfig scalars that do not live on a setting_sources class.
  general_fields:
    - HeadCodeInjection
    - OpenBodyCodeInjection
    - FooterCodeInjection
    - CompanyStreetAddress
    - CompanyEmail

  # Fields to drop even if they appear on a setting_sources class.
  exclude_fields: []

  # many_many relations holding records that reference Colours.
  colour_relations:
    ThemeButtons:
      class: Toast\Models\ThemeButton
      match: Title
      fields: [Bordered, SortOrder]
      colours: [PrimaryColour, SecondaryColour]
```

Once `setting_sources` is set, **new theme fields transfer automatically** — add a field to your theme extension and it appears in the payload with no change here. The same applies to new `has_one` Colour slots.

### Do not put credentials in `general_fields`

API keys, mail settings and analytics IDs are per-environment. The export JSON gets pasted into chat, tickets and other sites — keep secrets out of it.

## Usage

1. On the **source** site: Site Config → Theme Transfer → select all in *Export* and copy.
2. On the **target** site: paste into *Import*, leave **Preview only** ticked, save.
3. Read *Last import result*. It lists what would be created, updated, skipped, and any warnings.
4. Untick **Preview only** and save again to apply.

Importing is idempotent — running the same payload twice creates nothing the second time.

## What does not transfer

**Assets.** Logos, images and font files are not included. Upload them on the target.

**Theme colours are never created.** `Colour::IsThemeColour` is derived from the `theme_colours` config by `Colour::onBeforeWrite()`, and the records are created on `dev/build`. Only each theme colour's `ReferenceColour` pointer transfers. If the target lacks a theme colour the payload references, the import warns you to add it to config and run `dev/build` first.

Because that config lives in your project code, deploying the code to staging creates the slots before you ever paste anything — this only bites when the target is running older code than the source.

**Font sources.** `ThemeFontFaceConfig::FontSrc` and `FontType` are re-derived on write from an uploaded font file, so they cannot be set from data. Face rows are created as placeholders with the right weight and style, and the import warns which file each one expects. Upload the font and link it in the CMS.

## How records are matched

Nothing is matched by database ID — IDs differ between servers. Natural keys are used throughout:

| Record | Matched on |
| --- | --- |
| Colour | `CSSName`, falling back to `Title` |
| ThemeFontFamily | `Title`, falling back to `FontFamily` |
| ThemeFontConfig | `FontConfigID` |
| ThemeFontFace | family + weight + style |
| Colour relations | the configured `match` field |

## Programmatic use

```php
use Toast\ThemeTransfer\Services\ThemeTransferService;

$service = ThemeTransferService::singleton();

$json = $service->exportJson($siteConfig);

$summary = $service->importJson($siteConfig, $json, true); // dry run
$summary = $service->importJson($siteConfig, $json);       // apply
```

`$summary` returns `created`, `updated`, `skipped` and `warnings` arrays.
