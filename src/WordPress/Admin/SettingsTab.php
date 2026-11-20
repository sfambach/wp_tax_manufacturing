<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Renderer\DecimalText;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Draw\SelectMarkup;
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

    /**
     * The stock's label templates, «category: name» (D-1397); empty without the stock module.
     *
     * @return array<int, string>
     */
    private static function labelTemplates(): array
    {
        if (! class_exists(\TaxmodStock\WordPress\StockService::class) || ! method_exists(\TaxmodStock\WordPress\StockService::class, 'labelTemplates')) {
            return [];
        }

        $out = [];

        foreach ((new \TaxmodStock\WordPress\StockService())->labelTemplates()->all() as $id => $template) {
            $out[(int) $id] = trim(((string) ($template['category'] ?? '')) . ': ' . ((string) ($template['name'] ?? '#' . $id)), ': ');
        }

        asort($out, SORT_NATURAL);

        return $out;
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
                'control' => ListPage::field(InputKind::Line, 'taxmod_production_channel[]', (string) $channel, ['id' => $id, 'size' => 30, 'placeholder' => __('Verkaufskanal', 'taxmod-manufacturing')]) . ' '
                    . ListPage::field(InputKind::Number, 'taxmod_production_days[]', (string) $days, ['label' => __('Tage', 'taxmod-manufacturing'), 'min' => '0', 'step' => '1']) . ' ' . esc_html__('Tage', 'taxmod-manufacturing'),
                'hint'    => __('Frist der Kundenfertigung: Termin = Verkaufsdatum + diese Tage. Eine Bearbeitungszeit am Verkauf selbst geht vor. Kanal leeren löscht die Zeile.', 'taxmod-manufacturing'),
            ];
        }

        $templates = self::labelTemplates();

        if ($templates !== []) {
            $current = self::settings()->label;
            $options = [0 => __('— keins —', 'taxmod-manufacturing')] + $templates;

            $rows[] = ['label' => __('Standard-Produktionsetikett', 'taxmod-manufacturing'), 'for' => 'taxmod-production-label',
                'control' => SelectMarkup::of('taxmod_production_label', $options, (string) $current, false, '', ['id' => 'taxmod-production-label']),
                'hint' => __('Gilt für fertige Stücke, wenn weder Material noch Sparte ein Etikett nennen.', 'taxmod-manufacturing')];
        }

        // D-1425: the price of a completed board.
        $rows[] = ['label' => __('Stundenlohn', 'taxmod-manufacturing'), 'for' => 'taxmod-production-wage',
            'control' => ListPage::field(InputKind::Decimal, 'taxmod_production_wage', (string) round(self::settings()->wage, 2), ['id' => 'taxmod-production-wage', 'size' => 6]) . ' ' . esc_html__('€ je Stunde', 'taxmod-manufacturing'),
            'hint' => __('Arbeitslohn für den Fertigungspreis: Produktionszeit je Stück und Bereitstellung mal diesen Lohn. Gilt ab dem nächsten Abschluss; abgeschlossene Stücke behalten ihren Preis.', 'taxmod-manufacturing')];
        $rows[] = ['label' => __('Bereitstellung', 'taxmod-manufacturing'), 'for' => 'taxmod-production-setup',
            'control' => ListPage::field(InputKind::Decimal, 'taxmod_production_setup', (string) round(self::settings()->setupHours, 2), ['id' => 'taxmod-production-setup', 'size' => 6]) . ' ' . esc_html__('Stunden je Auftrag', 'taxmod-manufacturing'),
            'hint' => __('Wird auf die Stücke des Auftrags verteilt und als eigene Zeile im Preis ausgewiesen.', 'taxmod-manufacturing')];

        $stock = class_exists(\TaxmodStock\WordPress\Admin\SettingsPage::class) && method_exists(\TaxmodStock\WordPress\Admin\SettingsPage::class, 'url')
            ? ' <a href="' . esc_url(\TaxmodStock\WordPress\Admin\SettingsPage::url()) . '">' . esc_html__('Zu den Grundeinstellungen des Lagers', 'taxmod-manufacturing') . '</a>'
            : '';

        return $html . '<h2>' . esc_html__('Produktion', 'taxmod-manufacturing') . '</h2>'
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

        // D-1501: the number format is read at the boundary, in one place; the core gets the stored form. What is no number goes on
        // as typed, so the core names it in its refusal.
        $amount = static function (string $typed): string {
            $typed = sanitize_text_field($typed);

            return DecimalText::typed($typed) ?? $typed;
        };

        try {
            update_option(self::OPTION, ProductionSettings::fromRows(array_values($channels), array_values($days), absint($post['taxmod_production_label'] ?? 0),
                $amount((string) ($post['taxmod_production_wage'] ?? '')), $amount((string) ($post['taxmod_production_setup'] ?? '')))->toArray(), false);
        } catch (ManufacturingError $e) {
            $message = $e->reason === 'not_an_amount'
                /* translators: %s: the setting and the value given */
                ? __('Stundenlohn und Bereitstellung sind Zahlen ab 0, z. B. 15 oder 0,5 (%s).', 'taxmod-manufacturing')
                /* translators: %s: the channel and the value given */
                : __('Die Frist muss eine ganze Zahl von Tagen ab 0 sein (%s).', 'taxmod-manufacturing');
            set_transient(self::ERROR . get_current_user_id(), sprintf($message, $e->detail), 60);
        }
    }
}
