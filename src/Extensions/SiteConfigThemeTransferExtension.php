<?php

namespace Toast\ThemeTransfer\Extensions;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldGroup;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextareaField;
use Toast\ThemeTransfer\Services\ThemeTransferService;

/**
 * Adds a "Theme Transfer" tab to Site Config for cloning a theme between
 * servers: copy the JSON from the source site, paste it into the target, save.
 *
 * The import runs on save rather than through a custom controller action, so
 * the module adds no routes or public endpoints.
 */
class SiteConfigThemeTransferExtension extends Extension
{
    private static $db = [
        'ThemeTransferPaste'  => 'Text',
        'ThemeTransferDryRun' => 'Boolean',
        'ThemeTransferLog'    => 'Text',
    ];

    private static $defaults = [
        'ThemeTransferDryRun' => true,
    ];

    /**
     * Tab to add the fields to. Projects that do not have Root.Customization
     * can point this elsewhere.
     *
     * @config
     */
    private static string $cms_tab = 'Root.Customization.Transfer';

    /**
     * Guards against re-entry when the import writes the SiteConfig.
     */
    private static bool $importing = false;

    public function updateCMSFields(FieldList $fields)
    {
        $owner = $this->owner;

        $tab = Config::inst()->get(static::class, 'cms_tab');

        // Fall back to a top-level tab if the configured parent does not exist.
        $parent = substr($tab, 0, strrpos($tab, '.'));
        if ($parent && $parent !== 'Root' && !$fields->fieldByName($parent)) {
            $tab = 'Root.ThemeTransfer';
        }

        $fields->findOrMakeTab($tab, 'Theme Transfer');

        $fields->addFieldsToTab($tab, [
            HeaderField::create('ThemeTransferExportHeader', 'Theme Transfer')->setHeadingLevel(2),

            FieldGroup::create(
                LiteralField::create('ThemeImportButton', '<button id="ThemeTransferImportButton" type="button" class="theme-transfer-actions__button active">Import</button>'),
                LiteralField::create('ThemeExportButton', '<button id="ThemeTransferExportButton" type="button" class="theme-transfer-actions__button">Export</button>'),
            )->addExtraClass('theme-transfer-actions'),

            LiteralField::create(
                'ThemeTransferWarning',
                '<p class="theme-transfer-message message notice">Importing a theme config will overwrite your current theme settings, colours, fonts and '
                . 'buttons on this site. Uploaded assets (logos, images, font files) are not transferred.</p>'
            ),

            FieldGroup::create(
                TextareaField::create('ThemeTransferExport', 'Export (copy this)', $this->exportJson())
                    ->setAttribute('readonly', 'readonly')
                    ->setAttribute('spellcheck', 'false')
                    ->setRows(12)
                    ->setDescription(
                        'Theme settings, colours, fonts and buttons for this site. '
                        . 'Select all and copy, then paste into the target site.'
                    ),
                LiteralField::create('ThemeExportCopyButton', '<button id="ThemeExportCopyButton" type="button" class="theme-transfer-copy">Copy</button>'),
            )->addExtraClass('theme-transfer-export'),

            TextareaField::create('ThemeTransferPaste', 'Import (paste here)')
                ->setRows(12)
                ->setAttribute('spellcheck', 'false')
                ->setDescription(
                    'Paste an export from another site and save to apply it. '
                    . 'The field is cleared once the import runs.'
                ),

            CheckboxField::create('ThemeTransferDryRun', 'Validate only (do not write changes)')
                ->setDescription(
                    'This will validate the import code without writing changes. Uncheck to write changes and import the theme.'
                ),
        ]);

        if ($owner->ThemeTransferLog) {
            $fields->addFieldToTab(
                $tab,
                TextareaField::create('ThemeTransferLog', 'Last import result')
                    ->setRows(10)
                    ->setAttribute('spellcheck', 'false')
                    ->setAttribute('readonly', 'readonly')
            );
        }
    }

    protected function exportJson(): string
    {
        return ThemeTransferService::singleton()->exportJson($this->owner);
    }

    public function onAfterWrite()
    {
        $owner = $this->owner;

        if (self::$importing || !trim((string) $owner->ThemeTransferPaste)) {
            return;
        }

        self::$importing = true;

        try {
            $dryRun = (bool) $owner->ThemeTransferDryRun;

            $summary = ThemeTransferService::singleton()
                ->importJson($owner, $owner->ThemeTransferPaste, $dryRun);

            $owner->ThemeTransferLog = $this->formatLog($summary, $dryRun);

            // Keep the payload on a dry run so it can be applied for real on the
            // next save; clear it once actually imported.
            if (!$dryRun) {
                $owner->ThemeTransferPaste = null;
            }

            $owner->write();
        } finally {
            self::$importing = false;
        }
    }

    protected function formatLog(array $summary, bool $dryRun): string
    {
        $lines = [
            $dryRun ? '=== PREVIEW ONLY - nothing was written ===' : '=== IMPORTED ===',
            date('Y-m-d H:i:s'),
            '',
        ];

        $sections = [
            'warnings' => 'Warnings',
            'created' => 'Created',
            'updated' => 'Updated',
            'skipped' => 'Skipped',
        ];

        $any = false;
        foreach ($sections as $key => $label) {
            $items = $summary[$key] ?? [];
            if (!$items) {
                continue;
            }
            $any = true;
            $lines[] = $label . ' (' . count($items) . '):';
            foreach ($items as $item) {
                $lines[] = '  - ' . $item;
            }
            $lines[] = '';
        }

        if (!$any) {
            $lines[] = 'No changes.';
        }

        return implode("\n", $lines);
    }
}
