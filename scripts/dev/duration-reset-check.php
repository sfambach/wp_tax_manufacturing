<?php declare(strict_types=1);
/**
 * Feldkatalog 21.11. (Prüftor 22:18): once «Erfasste Zeit» is a duration it holds a unit value part (the modeller writes it,
 * ModelDoor::putAmount); the reset after a completion writes '' — this check proves the reset really empties the field.
 *
 *     php scripts/dev/duration-reset-check.php [path/to/wordpress]
 *
 * Uses the modeller's no-write bracket: nothing survives the run (WP_ROOT, TAXMOD_ROOT).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');
$modeller  = getenv('TAXMOD_ROOT') ?: '/home/user/wp-taxonomy-modeler';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require rtrim($modeller, '/') . '/scripts/dev/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\ModelDoor;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\Stopwatch;
use TaxmodManufacturing\Core\Structure;

wp_set_current_user(1);

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};
$apply = static function (array $changes) use ($pruefe): array {
    $answer = wp_get_ability('taxmod/apply')->execute(['changes' => $changes]);
    is_wp_error($answer) && $pruefe(false, 'apply — ' . $answer->get_error_message());

    return is_wp_error($answer) ? [] : (array) $answer;
};

// A node with the two stopwatch fields: the time as a unit value (4232 «Einheitenwert»), the start as a date and time.
$refs = $apply([
    ['op' => 'create_node', 'ref' => 'auftrag', 'name' => '__Fertigungsauftrag', 'parent' => 402],
    ['op' => 'add_field', 'ref' => 'zeit', 'node' => '@auftrag', 'name' => '__Erfasste Zeit', 'target' => 4232, 'kind' => 'composition', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'seit', 'node' => '@auftrag', 'name' => '__Zeit läuft seit', 'target' => 1183, 'kind' => 'composition', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'satz', 'node' => '@auftrag'],
])['refs'] ?? [];
$pruefe(isset($refs['zeit'], $refs['seit'], $refs['satz']), 'the check node stands with both fields and one record');

if (! isset($refs['zeit'], $refs['seit'], $refs['satz'])) {
    exit(1);
}

$s     = Structure::fromArray(['fields' => ['order_worked' => (int) $refs['zeit'], 'order_running' => (int) $refs['seit']]]);
$watch = new Stopwatch($s, true);
$order = static fn (float $worked, ?string $since): BuildOrder => new BuildOrder((int) $refs['satz'], 'A', 1, 1, 'order_building', null, null, null, [new Assembly(1, 1, [], [])], '', null, false, '', $worked, $since);
$zeit  = static fn (): array => (new ModelDoor())->valuesOf([(int) $refs['satz']])[(int) $refs['satz']][(int) $refs['zeit']] ?? [];

// The part as the modeller's putAmount leaves it: a number in the unit value (4234 «Betrag»).
$apply([['op' => 'set_values', 'record' => (string) $refs['satz'], 'values' => [(string) $refs['zeit'] => ['neu' => ['4234' => '4530']]]]]);
$teil = (int) ($zeit()[0] ?? 0);
$pruefe($teil > 0 && ((new ModelDoor())->valuesOf([$teil])[$teil][4234][0] ?? '') === '4530', 'the field holds a duration part of 4530');

$apply($watch->reset($order(75.5, null)));
$pruefe($zeit() === [], 'reset empties the unit value part — the field holds nothing afterwards');

echo $fehler === 0 ? "\nall green\n" : "\n$fehler failed\n";
exit($fehler === 0 ? 0 : 1);
