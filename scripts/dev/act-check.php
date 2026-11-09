<?php declare(strict_types=1);
/**
 * A manufacturing act never ends on an empty page (D-1254): the handler is registered, and a refused act goes back with its message.
 *
 *     php scripts/dev/act-check.php [path/to/wordpress]
 *
 * Needs this module and the Taxonomy Modeller (0.2.40 or later, for ListPage::nonce) active. Writes nothing: the act is refused at the nonce.
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

use Taxmod\WordPress\Admin\ListPage;

ob_start();
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

// The first redirect is the answer; the refusal then ends the request with `exit`, and the checks run on the way out.
$to = '';
add_filter('wp_redirect', static function (string $location) use (&$to): string {
    $to = $to !== '' ? $to : $location;

    return $location;
}, 1);

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

check('the handler is registered', has_action('admin_post_taxmod_manufacturing') !== false);
check('the modeller brings ListPage::act', method_exists(ListPage::class, 'act'));

register_shutdown_function(static function () use (&$to): void {
    global $bad;
    check('a refused act goes back to its page', str_contains($to, 'module=taxmod-fertigung'), $to);
    $page = ListPage::messages();
    check('that page shows why in the message field', str_contains($page, 'notice-error') && preg_match('/no longer valid|expired|abgelaufen|nicht mehr gültig/i', $page) === 1, $page);
    echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
    ob_end_flush();
    exit($bad === 0 ? 0 : 1);
});

// A real form posts from its own page, which is the way back when the modeller checks the nonce.
$_SERVER['HTTP_REFERER'] = admin_url('admin.php?page=taxmod-lager-modul&module=taxmod-fertigung');
$_POST = $_REQUEST = ['_wpnonce' => 'expired'];
do_action('admin_post_taxmod_manufacturing');
check('the act ends the request', false, 'it returned');
