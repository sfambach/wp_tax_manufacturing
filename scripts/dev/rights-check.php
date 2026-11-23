<?php declare(strict_types=1);
/**
 * Manufacturing checks its own right (D-1585 step 3, D-1586): its pages, order actions and the bare board need manufacture,
 * and no old right is left.
 *
 *     php scripts/dev/rights-check.php [path/to/wordpress]
 *
 * Needs the Taxonomy Modeller ab 0.2.237 active (WP_ROOT, TAXMOD_ROOT override the paths).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Rights;

$bad = 0;
$pruefe = static function (bool $ok, string $what) use (&$bad): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $what . "\n";
    $bad += $ok ? 0 : 1;
};

$old = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $old += preg_match_all('/\'manage_options\'|\bModeller::CAPABILITY\b|Plugin::CAPABILITY\b/', (string) file_get_contents($file->getPathname()));
    }
}
$pruefe($old === 0, "no old right left in src ($old)");

$production = array_values(array_filter((array) apply_filters('taxmod_logistics_pages', []), static fn (array $page): bool => ($page['area'] ?? '') === 'production'));
$pruefe($production !== [], 'manufacturing hangs its pages into the logistics frame');
$pruefe(array_unique(array_column($production, 'capability')) === [Rights::MANUFACTURE], 'every page of manufacturing asks manufacture');

Rights::ensureRoles();
$user  = static fn (string $login, string $role): int => (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role]);
$maker = $user('__mr_maker', 'taxmod_manufacture');
$stock = $user('__mr_stock', 'taxmod_stock');
$pruefe(user_can($maker, Rights::MANUFACTURE) && ! user_can($stock, Rights::MANUFACTURE), 'the manufacturing role holds it, the stock role does not');

echo $bad === 0 ? "\nAll green.\n" : "\n$bad failed.\n";
exit($bad === 0 ? 0 : 1);
