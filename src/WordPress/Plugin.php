<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress;

use TaxmodManufacturing\Core\Structure;
use TaxmodManufacturing\WordPress\Admin\OrdersPage;

/**
 * Wires the module into WordPress: its page inside the logistics navigation (area «Fertigung», D-1142), the form handlers,
 * and the structure option.
 */
final class Plugin
{
    public function boot(): void
    {
        // Checked at runtime rather than with «Requires Plugins», for the same reason as the stock module: the modeller lives in
        // a versioned folder on fambach.net.
        add_action('plugins_loaded', [$this, 'bootWhenModellerIsThere']);
    }

    public function bootWhenModellerIsThere(): void
    {
        if (! class_exists(\Taxmod\WordPress\Plugin::class)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__('Taxmod Fertigung braucht den aktiven Taxonomy Modeller.', 'taxmod-manufacturing') . '</p></div>';
            });

            return;
        }

        add_action('init', [$this, 'registerSettings']);
        // D-1085: a module's page opens inside the logistics frame; the stock module draws the navigation.
        add_filter('taxmod_logistics_pages', static function (mixed $pages): array {
            $pages   = is_array($pages) ? $pages : [];
            $pages[] = [
                'slug'   => OrdersPage::SLUG,
                'label'  => __('Aufträge', 'taxmod-manufacturing'),
                'area'   => 'production',
                'render' => static fn (): string => (new OrdersPage())->render(),
            ];

            return $pages;
        });

        foreach (OrdersPage::ACTIONS as $action) {
            // D-1147: a manufacturing step books through the stock, so it waits for the stock's other bookings like they wait for each other.
            add_action('admin_post_' . $action, static fn () => class_exists(\TaxmodStock\WordPress\Plugin::class) && method_exists(\TaxmodStock\WordPress\Plugin::class, 'serially')
                ? \TaxmodStock\WordPress\Plugin::serially(static fn () => (new OrdersPage())->handle($action))
                : (new OrdersPage())->handle($action));
        }

        if (! class_exists(\TaxmodStock\WordPress\Plugin::class)) {
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-warning"><p>' . esc_html__('Taxmod Fertigung erscheint in der Logistik-Navigation; dafür muss Taxmod Stock aktiv sein.', 'taxmod-manufacturing') . '</p></div>';
            });
        }
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
    }
}
