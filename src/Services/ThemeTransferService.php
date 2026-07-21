<?php

namespace Toast\ThemeTransfer\Services;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\SiteConfig\SiteConfig;
use Toast\ColourPalettes\Models\Colour;
use Toast\ThemeFonts\Models\ThemeFontConfig;
use Toast\ThemeFonts\Models\ThemeFontFaceConfig;
use Toast\ThemeFonts\Models\ThemeFontFamily;

/**
 * Exports a SiteConfig's theme setup to a portable JSON payload, and imports
 * that payload into a SiteConfig on another server.
 *
 * Records are matched across servers by natural key, never by ID:
 *   - Colour           => CSSName, falling back to Title
 *   - ThemeFontFamily  => Title, falling back to FontFamily
 *   - ThemeFontConfig  => FontConfigID
 *   - ThemeFontFace    => family + weight + style
 *   - colour_relations => the configured `match` field
 *
 * What this module knows about colours and fonts is fixed; everything
 * project-specific is configured. See _config/config.yml and the readme.
 *
 * Two fields deliberately do not transfer, because the modules that own them
 * recompute them on write:
 *   - Colour::IsThemeColour is derived from the `theme_colours` config, so
 *     theme colours are never created here - only their ReferenceColour moves.
 *   - ThemeFontFaceConfig::FontSrc is derived from an uploaded font file, so
 *     face rows arrive as placeholders and the file must be uploaded locally.
 */
class ThemeTransferService
{
    use Configurable;
    use Injectable;

    /**
     * Payload format version. Bump on any incompatible change to the shape.
     */
    const PAYLOAD_VERSION = 1;

    /**
     * Classes whose $db and has_one are harvested for theme settings. Normally
     * the project's own SiteConfig theme extension. Any has_one pointing at a
     * Colour is picked up as a colour slot automatically.
     *
     * @config
     */
    private static array $setting_sources = [];

    /**
     * Extra scalar SiteConfig fields to carry, for fields that do not live on
     * a class listed in setting_sources.
     *
     * Keep credentials out of this list - API keys and mail settings are
     * per-environment and must not travel between servers.
     *
     * @config
     */
    private static array $general_fields = [];

    /**
     * Field names to drop even if they appear on a setting_sources class.
     *
     * @config
     */
    private static array $exclude_fields = [];

    /**
     * many_many relations on SiteConfig holding records that reference Colours -
     * theme buttons and the like.
     *
     *   ThemeButtons:
     *     class: Toast\Models\ThemeButton
     *     match: Title                        # natural key
     *     fields: [Bordered, SortOrder]       # scalars to carry
     *     colours: [PrimaryColour, SecondaryColour]   # has_one Colour relations
     *
     * @config
     */
    private static array $colour_relations = [];

    /* ------------------------------------------------------------------
     * Export
     * ---------------------------------------------------------------- */

    public function export(SiteConfig $siteConfig): array
    {
        return [
            'meta' => [
                'version' => self::PAYLOAD_VERSION,
                'exportedAt' => date('c'),
                'sourceTitle' => $siteConfig->Title,
            ],
            'settings' => $this->exportSettings($siteConfig),
            'colours' => $this->exportColours($siteConfig),
            'colourSlots' => $this->exportColourSlots($siteConfig),
            'fonts' => $this->exportFonts($siteConfig),
            'relations' => $this->exportColourRelations($siteConfig),
        ];
    }

    public function exportJson(SiteConfig $siteConfig): string
    {
        return json_encode($this->export($siteConfig), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    protected function exportSettings(SiteConfig $siteConfig): array
    {
        $out = [];
        foreach ($this->settingsFields() as $field) {
            $out[$field] = $siteConfig->$field;
        }
        return $out;
    }

    /**
     * Every scalar theme field, harvested from the configured source classes so
     * new fields transfer without touching this service.
     */
    protected function settingsFields(): array
    {
        $fields = [];

        foreach ($this->settingSources() as $class) {
            $db = Config::inst()->get($class, 'db') ?: [];
            $fields = array_merge($fields, array_keys($db));
        }

        $fields = array_merge($fields, (array) $this->config()->get('general_fields'));
        $exclude = (array) $this->config()->get('exclude_fields');

        return array_values(array_unique(array_diff($fields, $exclude)));
    }

    /**
     * Every has_one on a source class that points at a Colour.
     *
     * has_one relations to File/Image are intentionally not picked up - assets
     * do not travel between servers.
     *
     * @return array<string> Relation names, e.g. 'HeaderPrimaryColour'
     */
    protected function colourSlots(): array
    {
        $slots = [];

        foreach ($this->settingSources() as $class) {
            $hasOne = Config::inst()->get($class, 'has_one') ?: [];

            foreach ($hasOne as $relation => $spec) {
                // Polymorphic relations are declared as an array and carry no
                // fixed class, so they cannot be resolved to a Colour.
                $target = is_array($spec) ? ($spec['class'] ?? null) : $spec;
                if ($target && is_a($target, Colour::class, true)) {
                    $slots[] = $relation;
                }
            }
        }

        return array_values(array_unique($slots));
    }

    /**
     * Configured source classes, skipping any that are not installed so a site
     * missing an optional extension does not fatal.
     */
    protected function settingSources(): array
    {
        $sources = (array) $this->config()->get('setting_sources');
        return array_values(array_filter($sources, fn($class) => class_exists($class)));
    }

    protected function exportColours(SiteConfig $siteConfig): array
    {
        $palette = [];
        $themeColours = [];

        foreach ($siteConfig->Colours() as $colour) {
            if ($colour->IsThemeColour) {
                $reference = $colour->ReferenceColour();
                $themeColours[] = [
                    'cssName' => $colour->CSSName,
                    'title' => $colour->Title,
                    'referenceCssName' => ($reference && $reference->exists())
                        ? $reference->CSSName
                        : null,
                ];
                continue;
            }

            $palette[] = [
                'cssName' => $colour->CSSName,
                'title' => $colour->Title,
                'hexValue' => $this->normaliseHex($colour->HexValue),
                'contrastColour' => $colour->ContrastColour,
                'groups' => $colour->getColourGroups() ?: [],
                'sortOrder' => (int) $colour->SortOrder,
            ];
        }

        return [
            'palette' => $palette,
            'themeColours' => $themeColours,
        ];
    }

    protected function exportColourSlots(SiteConfig $siteConfig): array
    {
        $slots = [];
        foreach ($this->colourSlots() as $slot) {
            $slots[$slot] = $this->colourRef($siteConfig->$slot());
        }
        return $slots;
    }

    protected function exportFonts(SiteConfig $siteConfig): array
    {
        $families = [];
        $faces = [];

        foreach ($siteConfig->ThemeFontFamilies() as $family) {
            $families[] = [
                'title' => $family->Title,
                'fontFamily' => $family->FontFamily,
                'sortOrder' => (int) $family->SortOrder,
            ];

            // @font-face definitions hang off the family, not the SiteConfig.
            foreach ($family->ThemeFontFaceConfigs() as $face) {
                $faces[] = [
                    'familyRef' => $family->Title,
                    'fontWeight' => $face->FontWeight,
                    'fontStyle' => $face->FontStyle,
                    'fontSrc' => $face->FontSrc,
                    'fontType' => $face->FontType,
                    'sortOrder' => (int) $face->SortOrder,
                ];
            }
        }

        $configs = [];
        foreach ($siteConfig->ThemeFontConfigs() as $config) {
            // FontFamilyID is the authoritative field the module reads at render
            // time; ThemeFontFamilyID is derived from it in onBeforeWrite.
            $family = $config->FontFamilyID
                ? ThemeFontFamily::get()->byID($config->FontFamilyID)
                : null;

            $configs[] = [
                'fontConfigID' => $config->FontConfigID,
                'title' => $config->Title,
                'familyRef' => ($family && $family->exists()) ? $family->Title : null,
                'boldFontWeight' => $config->BoldFontWeight,
                'sortOrder' => (int) $config->SortOrder,
            ];
        }

        return [
            'preconnects' => $siteConfig->ThemeFontPreconnects,
            'links' => $siteConfig->ThemeFontLinks,
            'families' => $families,
            'configs' => $configs,
            'faces' => $faces,
        ];
    }

    protected function exportColourRelations(SiteConfig $siteConfig): array
    {
        $out = [];

        foreach ($this->colourRelations() as $relation => $spec) {
            if (!$siteConfig->hasMethod($relation)) {
                continue;
            }

            $rows = [];
            foreach ($siteConfig->$relation() as $record) {
                $row = ['_match' => $record->{$spec['match']}];

                foreach ($spec['fields'] as $field) {
                    $row[$field] = $record->$field;
                }
                foreach ($spec['colours'] as $colourRelation) {
                    $row[$colourRelation] = $this->colourRef($record->$colourRelation());
                }

                $rows[] = $row;
            }

            $out[$relation] = $rows;
        }

        return $out;
    }

    /**
     * Configured colour relations, normalised and filtered to installed classes.
     */
    protected function colourRelations(): array
    {
        $out = [];

        foreach ((array) $this->config()->get('colour_relations') as $relation => $spec) {
            $class = $spec['class'] ?? null;
            if (!$class || !class_exists($class)) {
                continue;
            }

            $out[$relation] = [
                'class' => $class,
                'match' => $spec['match'] ?? 'Title',
                'fields' => (array) ($spec['fields'] ?? []),
                'colours' => (array) ($spec['colours'] ?? []),
            ];
        }

        return $out;
    }

    /* ------------------------------------------------------------------
     * Import
     * ---------------------------------------------------------------- */

    /**
     * @param bool $dryRun When true nothing is written - the summary reports
     *                     what would have happened.
     * @return array{created:array,updated:array,skipped:array,warnings:array}
     */
    public function import(SiteConfig $siteConfig, array $payload, bool $dryRun = false): array
    {
        $summary = [
            'created' => [],
            'updated' => [],
            'skipped' => [],
            'warnings' => [],
        ];

        $version = $payload['meta']['version'] ?? null;
        if ($version !== self::PAYLOAD_VERSION) {
            $summary['warnings'][] = sprintf(
                'Payload version %s does not match this module\'s version %d - import may be incomplete.',
                $version === null ? 'missing' : $version,
                self::PAYLOAD_VERSION
            );
        }

        // Colours first: slots, relations and configs all resolve against them.
        $colourMap = $this->importColours($siteConfig, $payload['colours'] ?? [], $summary, $dryRun);
        $this->importFonts($siteConfig, $payload['fonts'] ?? [], $summary, $dryRun);
        $this->importSettings($siteConfig, $payload['settings'] ?? [], $summary, $dryRun);
        $this->importColourSlots($siteConfig, $payload['colourSlots'] ?? [], $colourMap, $summary, $dryRun);
        $this->importColourRelations($siteConfig, $payload['relations'] ?? [], $colourMap, $summary, $dryRun);

        if (!$dryRun) {
            $siteConfig->write();
        }

        return $summary;
    }

    public function importJson(SiteConfig $siteConfig, string $json, bool $dryRun = false): array
    {
        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            return [
                'created' => [],
                'updated' => [],
                'skipped' => [],
                'warnings' => ['Could not parse JSON: ' . json_last_error_msg()],
            ];
        }

        return $this->import($siteConfig, $payload, $dryRun);
    }

    /**
     * @return array<string,Colour> lowercased CSSName => Colour
     */
    protected function importColours(SiteConfig $siteConfig, array $data, array &$summary, bool $dryRun): array
    {
        $map = [];

        foreach ($data['palette'] ?? [] as $item) {
            $cssName = $item['cssName'] ?? null;
            $title = $item['title'] ?? null;

            if (!$cssName && !$title) {
                $summary['skipped'][] = 'Colour with no cssName or title';
                continue;
            }

            $colour = $this->findColour($siteConfig, $cssName, $title);
            $isNew = !$colour;

            if ($isNew) {
                $colour = Colour::create();
                $colour->CSSName = $cssName;
            }

            $colour->Title = $title ?: $colour->Title;
            $colour->HexValue = $this->normaliseHex($item['hexValue'] ?? null) ?: $colour->HexValue;
            $colour->ContrastColour = $item['contrastColour'] ?? $colour->ContrastColour;
            $colour->SortOrder = $item['sortOrder'] ?? $colour->SortOrder;
            if (isset($item['groups'])) {
                $colour->Groups = json_encode($item['groups']);
            }

            if (!$dryRun) {
                $colour->write();
                $siteConfig->Colours()->add($colour);
            }

            $label = $cssName ?: $title;
            $isNew ? $summary['created'][] = "Colour: {$label}"
                   : $summary['updated'][] = "Colour: {$label}";

            if ($cssName) {
                $map[strtolower($cssName)] = $colour;
            }
        }

        // Theme colours are created from this site's own `theme_colours` config
        // on dev/build - never by an import. Only the pointer transfers.
        foreach ($data['themeColours'] ?? [] as $slot) {
            $slotCssName = $slot['cssName'] ?? null;
            $label = $slotCssName ?: ($slot['title'] ?? 'unknown');
            $refCssName = $slot['referenceCssName'] ?? null;

            if (!$slotCssName) {
                $summary['skipped'][] = "Theme colour '{$label}': no cssName in payload";
                continue;
            }

            $themeColour = $siteConfig->Colours()
                ->filter(['IsThemeColour' => 1, 'CSSName:nocase' => $slotCssName])
                ->first();

            if (!$themeColour) {
                $summary['warnings'][] = "Theme colour '{$slotCssName}' does not exist on this site - "
                    . 'add it to the `theme_colours` config and run dev/build, then re-import.';
                continue;
            }

            if (!$refCssName) {
                continue;
            }

            $reference = $map[strtolower($refCssName)] ?? $this->findColour($siteConfig, $refCssName, null);
            if (!$reference) {
                $summary['skipped'][] = "Theme colour '{$slotCssName}': reference '{$refCssName}' not found";
                continue;
            }

            if (!$dryRun) {
                $themeColour->ReferenceColourID = $reference->ID;
                $themeColour->write();
            }
            $summary['updated'][] = "Theme colour: {$slotCssName} => {$refCssName}";
        }

        return $map;
    }

    protected function importColourSlots(
        SiteConfig $siteConfig,
        array $slots,
        array $colourMap,
        array &$summary,
        bool $dryRun
    ): void {
        $known = $this->colourSlots();

        foreach ($slots as $slot => $cssName) {
            if (!in_array($slot, $known, true)) {
                $summary['skipped'][] = "Unknown colour slot: {$slot}";
                continue;
            }

            if (!$cssName) {
                continue;
            }

            $colour = $colourMap[strtolower($cssName)] ?? $this->findColour($siteConfig, $cssName, null);
            if (!$colour) {
                $summary['skipped'][] = "Slot {$slot}: colour '{$cssName}' not found";
                continue;
            }

            if (!$dryRun) {
                $field = $slot . 'ID';
                $siteConfig->$field = $colour->ID;
            }
            $summary['updated'][] = "Slot {$slot} => {$cssName}";
        }
    }

    protected function importFonts(SiteConfig $siteConfig, array $data, array &$summary, bool $dryRun): void
    {
        $familyMap = [];

        foreach ($data['families'] ?? [] as $item) {
            $title = $item['title'] ?? $item['fontFamily'] ?? null;
            if (!$title) {
                $summary['skipped'][] = 'Font family with no title';
                continue;
            }

            $family = ThemeFontFamily::get()
                ->filterAny(['Title:nocase' => $title, 'FontFamily:nocase' => $title])
                ->first();
            $isNew = !$family;

            if ($isNew) {
                $family = ThemeFontFamily::create();
            }

            $family->Title = $item['title'] ?? $title;
            $family->FontFamily = $item['fontFamily'] ?? $family->FontFamily;
            $family->SortOrder = $item['sortOrder'] ?? $family->SortOrder;

            if (!$dryRun) {
                $family->write();
                $siteConfig->ThemeFontFamilies()->add($family);
            }

            $isNew ? $summary['created'][] = "Font family: {$title}"
                   : $summary['updated'][] = "Font family: {$title}";

            $familyMap[strtolower($title)] = $family;
        }

        // Face rows carry only weight/style. FontSrc/FontType/FontFamily are
        // re-derived in ThemeFontFaceConfig::onBeforeWrite() from an uploaded
        // font file, so setting them here would be discarded. The row is created
        // as a placeholder and the expected source is reported as a warning.
        foreach ($data['faces'] ?? [] as $item) {
            $familyRef = $item['familyRef'] ?? null;
            $family = $familyRef ? ($familyMap[strtolower($familyRef)] ?? null) : null;

            if (!$family) {
                $summary['skipped'][] = "Font face: family '{$familyRef}' not found";
                continue;
            }

            $weight = $item['fontWeight'] ?? '400';
            $style = $item['fontStyle'] ?? 'normal';
            $label = "{$familyRef} {$weight} {$style}";

            if ($dryRun) {
                $summary['created'][] = "Font face: {$label}";
                continue;
            }

            $face = ThemeFontFaceConfig::get()->filter([
                'ThemeFontFamilyID' => $family->ID,
                'FontWeight' => $weight,
                'FontStyle' => $style,
            ])->first();
            $isNew = !$face;

            if ($isNew) {
                $face = ThemeFontFaceConfig::create();
                $face->ThemeFontFamilyID = $family->ID;
                $face->FontWeight = $weight;
                $face->FontStyle = $style;
            }

            $face->SortOrder = $item['sortOrder'] ?? 0;
            $face->write();

            $isNew ? $summary['created'][] = "Font face: {$label}"
                   : $summary['updated'][] = "Font face: {$label}";

            if (!$face->FontSrc) {
                $expected = $item['fontSrc'] ?? 'a font file';
                $summary['warnings'][] = "Font face '{$label}' has no font file on this site - "
                    . "upload and link it in the CMS (source site used: {$expected}).";
            }
        }

        foreach ($data['configs'] ?? [] as $item) {
            $configID = $item['fontConfigID'] ?? null;
            if (!$configID) {
                $summary['skipped'][] = 'Font config with no fontConfigID';
                continue;
            }

            $config = $siteConfig->ThemeFontConfigs()
                ->filter('FontConfigID:nocase', $configID)
                ->first();
            $isNew = !$config;

            if ($isNew) {
                $config = ThemeFontConfig::create();
                $config->FontConfigID = $configID;
            }

            $config->Title = $item['title'] ?? $config->Title;
            $config->BoldFontWeight = $item['boldFontWeight'] ?? $config->BoldFontWeight;
            $config->SortOrder = $item['sortOrder'] ?? $config->SortOrder;

            $familyRef = $item['familyRef'] ?? null;
            $family = $familyRef ? ($familyMap[strtolower($familyRef)] ?? null) : null;
            if ($familyRef && !$family) {
                $summary['warnings'][] = "Font config '{$configID}': family '{$familyRef}' not found, left unlinked.";
            }

            if ($family && !$dryRun) {
                // Set FontFamilyID - ThemeFontFamilyID is derived from it.
                $config->FontFamilyID = $family->ID;
                $config->FontFamily = $family->FontFamily;
            }

            if (!$dryRun) {
                $config->write();
                $siteConfig->ThemeFontConfigs()->add($config);
            }

            $isNew ? $summary['created'][] = "Font config: {$configID}"
                   : $summary['updated'][] = "Font config: {$configID}";
        }

        if (array_key_exists('preconnects', $data)) {
            $siteConfig->ThemeFontPreconnects = $data['preconnects'];
        }
        if (array_key_exists('links', $data)) {
            $siteConfig->ThemeFontLinks = $data['links'];
        }
    }

    protected function importSettings(SiteConfig $siteConfig, array $settings, array &$summary, bool $dryRun): void
    {
        $allowed = $this->settingsFields();
        $applied = 0;

        foreach ($settings as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                $summary['skipped'][] = "Unknown setting: {$field}";
                continue;
            }
            if (!$dryRun) {
                $siteConfig->$field = $value;
            }
            $applied++;
        }

        if ($applied) {
            $summary['updated'][] = "Settings: {$applied} field(s)";
        }
    }

    protected function importColourRelations(
        SiteConfig $siteConfig,
        array $data,
        array $colourMap,
        array &$summary,
        bool $dryRun
    ): void {
        $configured = $this->colourRelations();

        foreach ($data as $relation => $rows) {
            if (!isset($configured[$relation])) {
                $summary['skipped'][] = "Relation '{$relation}' is not configured on this site";
                continue;
            }

            $spec = $configured[$relation];

            if (!$siteConfig->hasMethod($relation)) {
                $summary['skipped'][] = "Relation '{$relation}' does not exist on SiteConfig";
                continue;
            }

            foreach ($rows as $row) {
                $match = $row['_match'] ?? null;
                if (!$match) {
                    $summary['skipped'][] = "{$relation}: record with no {$spec['match']}";
                    continue;
                }

                $record = $siteConfig->$relation()
                    ->filter($spec['match'] . ':nocase', $match)
                    ->first();
                $isNew = !$record;

                if ($isNew) {
                    $record = $spec['class']::create();
                    $record->{$spec['match']} = $match;
                }

                foreach ($spec['fields'] as $field) {
                    if (array_key_exists($field, $row)) {
                        $record->$field = $row[$field];
                    }
                }

                foreach ($spec['colours'] as $colourRelation) {
                    $cssName = $row[$colourRelation] ?? null;
                    if (!$cssName) {
                        continue;
                    }

                    $colour = $colourMap[strtolower($cssName)]
                        ?? $this->findColour($siteConfig, $cssName, null);

                    if (!$colour) {
                        $summary['warnings'][] = "{$relation} '{$match}': colour '{$cssName}' not found.";
                        continue;
                    }

                    if (!$dryRun) {
                        $record->{$colourRelation . 'ID'} = $colour->ID;
                    }
                }

                if (!$dryRun) {
                    $record->write();
                    $siteConfig->$relation()->add($record);
                }

                $isNew ? $summary['created'][] = "{$relation}: {$match}"
                       : $summary['updated'][] = "{$relation}: {$match}";
            }
        }
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------- */

    /**
     * Find a palette colour on this SiteConfig by CSSName, then Title. Theme
     * colours are excluded - they are slots, not palette entries.
     */
    protected function findColour(SiteConfig $siteConfig, ?string $cssName, ?string $title): ?Colour
    {
        $colours = $siteConfig->Colours()->filter('IsThemeColour', 0);

        if ($cssName) {
            $match = $colours->filter('CSSName:nocase', $cssName)->first();
            if ($match) {
                return $match;
            }
        }

        if ($title) {
            $match = $colours->filter('Title:nocase', $title)->first();
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Express a has_one Colour as a portable CSSName reference.
     */
    protected function colourRef($colour): ?string
    {
        if (!$colour || !$colour->exists()) {
            return null;
        }
        return $colour->CSSName ?: $colour->Title;
    }

    protected function normaliseHex($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : ltrim($value, '#');
    }
}
