<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress;

use TaxmodManufacturing\Core\ProductionSettings;
use TaxmodManufacturing\Core\Structure;
use TaxmodManufacturing\WordPress\Admin\OrdersPage;
use TaxmodManufacturing\WordPress\Admin\SettingsTab;

/**
 * Wires the module into WordPress: its page inside the logistics navigation (area «Fertigung», D-1142), the form handlers,
 * and the structure option.
 */
final class Plugin
{
    /** The orders page's address, owned here so the boot names it without compiling the page (D-1187). */
    public const ORDERS_PAGE = 'taxmod-fertigung';

    public function boot(): void
    {
        // Checked at runtime rather than with «Requires Plugins», for the same reason as the stock module: the modeller lives in
        // a versioned folder on fambach.net.
        add_action('plugins_loaded', [$this, 'bootWhenModellerIsThere']);
    }

    public const STOCK_VERSION = '0.64.120';

    /** D-1463: `apply_filters(REVISION_STATUS_FILTER, [], $revisions)` → revision → ['status' => key, 'label' => words]. */
    public const REVISION_STATUS_FILTER = 'taxmod_manufacturing_revision_status';

    public function bootWhenModellerIsThere(): void
    {
        // The one door into the model (D-1186), its bracket for one act over several bookings (D-1419) and the shared page blocks button/iconButton/money (D-1500, 0.2.172). D-1501: fields through ListPage::field, 0.2.175.
        // Asked by version, not by loading the classes (D-1422).
        if (! class_exists(\Taxmod\WordPress\Plugin::class, false) || version_compare(\Taxmod\WordPress\Plugin::VERSION, '0.2.175', '<')) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__('Taxmod Fertigung braucht den aktiven Taxonomy Modeller ab 0.2.175.', 'taxmod-manufacturing') . '</p></div>';
            });

            return;
        }

        // On admin_init and rest_api_init, where settings are saved, not on every page view (D-1187).
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('rest_api_init', [$this, 'registerSettings']);
        // D-1305: the tab «Produktion» on Taxonomy Modeller › Configuration.
        add_filter('taxmod_config_tabs', [SettingsTab::class, 'register']);
        // D-1085: a module's page opens inside the logistics frame; the stock module draws the navigation.
        add_filter('taxmod_logistics_pages', static function (mixed $pages): array {
            $pages  = is_array($pages) ? $pages : [];
            // His word 2026-11-14: one menu point per arrow of the process bar, in its order, named like the arrow.
            $labels = OrdersPage::viewLabels();
            $fails  = (new ManufacturingService())->canFail();

            foreach (OrdersPage::VIEWS as $view => $slug) {
                if ($view === 'order_failed' && ! $fails) {
                    continue;
                }

                // The area head «Fertigung» opens the first point instead of an overview of tiles (stock 0.64.78; an older stock ignores it).
                $pages[] = ['slug' => $slug, 'label' => $labels[$view], 'area' => 'production', 'opens_area' => $view === 'boxes', 'render' => static fn (): string => (new OrdersPage())->render()];
            }

            return $pages;
        });

        // D-1483, Stefan 21.11. 15:08 «das Auge … sollte zum fachlichen Ziel springen»: a build order's link opens its own page.
        add_filter('taxmod_record_home', [self::class, 'recordHome'], 10, 3);

        // D-1463: a revision's status, derived from its orders when asked — for the post blocks (D-1466) and other modules.
        add_filter(self::REVISION_STATUS_FILTER, static fn (mixed $given, mixed $revisions): array => (new ManufacturingService())->revisionStatuses(array_values(array_filter(array_map('intval', (array) $revisions)))), 10, 2);

        // ⚠️ *Only where admin-post.php answers: `OrdersPage::ACTIONS` alone compiled the orders page on every page view (D-1187).*
        if (self::handlesAdminActions()) {
            foreach (OrdersPage::ACTIONS as $action) {
                // D-1147: a manufacturing step books through the stock, so it waits for the stock's other bookings like they wait for each other.
                add_action('admin_post_' . $action, static fn () => class_exists(\TaxmodStock\WordPress\Plugin::class) && method_exists(\TaxmodStock\WordPress\Plugin::class, 'serially')
                    ? \TaxmodStock\WordPress\Plugin::serially(static fn () => (new OrdersPage())->handle($action))
                    : (new OrdersPage())->handle($action));
            }
        }

        // D-1433, the gate 18.11.: one way only — the boards' statuses need the stock's whole-lot moves (Lager 0.64.120).
        if (! defined('TAXMOD_STOCK_VERSION') || version_compare((string) TAXMOD_STOCK_VERSION, self::STOCK_VERSION, '<')) {
            add_action('admin_notices', static function (): void {
                /* translators: %s: the stock module's least version */
                echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('Taxmod Fertigung braucht das aktive Taxmod Lager ab %s.', 'taxmod-manufacturing'), self::STOCK_VERSION)) . '</p></div>';
            });
        }
    }

    /** The modeller's answer whether admin-post.php or admin-ajax.php serve this request; an older modeller wires always (D-1187). */
    private static function handlesAdminActions(): bool
    {
        return ! method_exists(\Taxmod\WordPress\Plugin::class, 'handlesAdminActions') || \Taxmod\WordPress\Plugin::handlesAdminActions();
    }

    /** The structure ids, settable over REST (/wp/v2/settings) right after the model change is applied. */
    public function registerSettings(): void
    {
        $group = static fn (): array => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']];

        register_setting('taxmod_manufacturing', ManufacturingService::STRUCTURE, [
            'type'              => 'object',
            'default'           => [],
            'sanitize_callback' => static fn ($value): array => Structure::fromArray(is_array($value) ? $value : [])->toArray(),
            'show_in_rest'      => ['schema' => ['type' => 'object', 'properties' => ['nodes' => $group(), 'fields' => $group(), 'values' => $group()]]],
        ]);

        // D-1305/D-1397: the tab «Produktion», settable over REST like the structure, so the upload can set it.
        register_setting('taxmod_manufacturing', SettingsTab::OPTION, [
            'type'              => 'object',
            'default'           => [],
            'sanitize_callback' => static fn ($value): array => ProductionSettings::fromArray(is_array($value) ? $value : [])->toArray(),
            'show_in_rest'      => ['schema' => ['type' => 'object', 'properties' => [
                'deadlines' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                'label'     => ['type' => 'integer'],
                'wage'      => ['type' => 'number'],
                'setup'     => ['type' => 'number'],
            ]]],
        ]);
    }

    /** D-1483: `(?string $url, int $record, int $node)` — a build order's link opens the order, another module's answer stays. */
    public static function recordHome(mixed $url, mixed $record, mixed $node): mixed
    {
        // mixed: a WordPress filter passes whatever the previous callback returned.
        if (is_string($url) && $url !== '' || (int) $record <= 0) {
            return $url;
        }

        $structure = (new ManufacturingService())->structure();

        return $structure->hasNode('order') && $structure->node('order') === (int) $node ? (new OrdersPage())->url(['auftrag' => (int) $record]) : $url;
    }
}
