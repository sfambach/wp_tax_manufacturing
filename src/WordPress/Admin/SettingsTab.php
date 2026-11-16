<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\ProductionSettings;

/**
 * The module's tab «Produktion» on Taxonomy Modeller › Configuration (D-1305, D-945): the deadline of a build for a
 * customer per sales channel. The places WE-PROD and «Test» stay in the stock's settings (D-1342), linked, not doubled.
 *
 * The modeller draws the tab, checks capability and nonce, and calls {@see save()}.
 *
 * @see /mnt/project-files/taxmod-fertigung/spezifikation-fertigungsprozess.md §8b
 */
final class SettingsTab
{
    public const SLUG   = 'production';
    public const OPTION = 'taxmod_manufacturing_production';
    private const ERROR = 'taxmod_manufacturing_settings_error';
    private const SPARE_ROWS = 2;

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public static function register(array $tabs): array
    {
        $tab = new self();

        return $tabs + [self::SLUG => [
            'title'   => __('Produktion', 'taxmod-manufacturing'),
            'render'  => $tab->render(...),
            'save'    => $tab->save(...),
            'version' => defined('TAXMOD_MANUFACTURING_VERSION') ? (string) TAXMOD_MANUFACTURING_VERSION : '',
        ]];
    }

    public static function settings(): ProductionSettings
    {
        $stored = get_option(self::OPTION, []);

        return ProductionSettings::fromArray(is_array($stored) ? $stored : []);
    }

    public function render(): string
    {
        $html  = '';
        $error = get_transient(self::ERROR . get_current_user_id());

        if (is_string($error) && $error !== '') {
            delete_transient(self::ERROR . get_current_user_id());
            $html .= '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        $rows    = [];
        $entries = array_map(null, array_keys(self::settings()->deadlines), array_values(self::settings()->deadlines));
        $entries = array_merge($entries, array_fill(0, self::SPARE_ROWS, [null, null]));

        foreach ($entries as $i => [$channel, $days]) {
            $id     = 'taxmod-production-channel-' . $i;
            $rows[] = [
                'label'   => $channel === null ? __('Neuer Kanal', 'taxmod-manufacturing') : (string) $channel,
                'for'     => $id,
                'control' => '<input type="text" class="regular-text" name="taxmod_production_channel[]" id="' . esc_attr($id) . '" value="' . esc_attr((string) $channel) . '" placeholder="' . esc_attr__('Verkaufskanal', 'taxmod-manufacturing') . '"> '
                    . '<input type="number" class="small-text" min="0" step="1" name="taxmod_production_days[]" value="' . esc_attr((string) $days) . '" aria-label="' . esc_attr__('Tage', 'taxmod-manufacturing') . '"> ' . esc_html__('Tage', 'taxmod-manufacturing'),
                'hint'    => __('Frist der Kundenfertigung: Termin = Verkaufsdatum + diese Tage. Eine Bearbeitungszeit am Verkauf selbst geht vor. Kanal leeren löscht die Zeile.', 'taxmod-manufacturing'),
            ];
        }

        $stock = class_exists(\TaxmodStock\WordPress\Admin\SettingsPage::class) && method_exists(\TaxmodStock\WordPress\Admin\SettingsPage::class, 'url')
            ? ' <a href="' . esc_url(\TaxmodStock\WordPress\Admin\SettingsPage::url()) . '">' . esc_html__('Zu den Grundeinstellungen des Lagers', 'taxmod-manufacturing') . '</a>'
            : '';

        return $html . '<h2>' . esc_html__('Frist der Kundenfertigung je Verkaufskanal', 'taxmod-manufacturing') . '</h2>'
            . ListPage::form($rows)
            . '<p>' . esc_html__('Die Plätze «Wareneingang Produktion» und «Test» stellst du im Lager ein.', 'taxmod-manufacturing') . $stock . '</p>';
    }

    /** @param array<string, mixed> $post already unslashed by the modeller */
    public function save(array $post): void
    {
        if (! isset($post['taxmod_production_channel'])) {
            return;
        }

        $channels = array_map(static fn ($v): string => sanitize_text_field((string) $v), (array) $post['taxmod_production_channel']);
        $days     = array_map(static fn ($v): string => sanitize_text_field((string) $v), (array) ($post['taxmod_production_days'] ?? []));

        try {
            update_option(self::OPTION, ProductionSettings::fromRows(array_values($channels), array_values($days))->toArray(), false);
        } catch (ManufacturingError $e) {
            /* translators: %s: the channel and the value given */
            set_transient(self::ERROR . get_current_user_id(), sprintf(__('Die Frist muss eine ganze Zahl von Tagen ab 0 sein (%s).', 'taxmod-manufacturing'), $e->detail), 60);
        }
    }
}
