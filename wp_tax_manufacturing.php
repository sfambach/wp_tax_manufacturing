<?php declare(strict_types=1);

/**
 * Plugin Name:       Taxmod Manufacturing
 * Description:       Manufacturing for the Taxonomy Modeller: build orders with a build status per board and per parts-list position.
 * Version: 0.0.25
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Stefan Fambach (SteFam), Claude (Anthropic AI)
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       taxmod-manufacturing
 * GitHub Plugin URI: sfambach/wp_tax_manufacturing
 * Primary Branch:    main
 */

if (! defined('ABSPATH')) {
    exit;
}

define('TAXMOD_MANUFACTURING_VERSION', '0.0.25');

$taxmodManufacturingAutoloader = __DIR__ . '/vendor/autoload.php';

if (! is_readable($taxmodManufacturingAutoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Taxmod Fertigung: vendor/autoload.php fehlt — bitte das Release-Paket installieren.', 'taxmod-manufacturing') . '</p></div>';
    });

    return;
}

require $taxmodManufacturingAutoloader;

(new TaxmodManufacturing\WordPress\Plugin())->boot();
