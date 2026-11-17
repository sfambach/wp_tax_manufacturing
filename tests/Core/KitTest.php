<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\BuildOrders;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\OrderChanges;
use TaxmodManufacturing\Core\Structure;
use TaxmodManufacturing\Core\Table;

/** D-1428: a kit is a production order of the art «Kit». */
final class KitTest extends TestCase
{
    private const S = [
        'nodes'  => ['project' => 1, 'board' => 2, 'board_revision' => 3, 'bom_position' => 4, 'order' => 5, 'assembly' => 6, 'fitting' => 7],
        'fields' => [
            'label' => 10, 'project_boards' => 11, 'board_revisions' => 12, 'revision_positions' => 13, 'position_part' => 14, 'position_count' => 15,
            'order_project' => 20, 'order_count' => 21, 'order_status' => 22, 'order_assemblies' => 23, 'order_art' => 27,
            'assembly_revision' => 30, 'assembly_status' => 31, 'assembly_unused' => 32, 'assembly_fitted' => 34, 'fitting_position' => 35, 'fitting_boards' => 36, 'assembly_done' => 38,
        ],
        'values' => [
            'order_planned' => 40, 'order_building' => 41, 'order_done' => 42, 'order_cancelled' => 43, 'order_test' => 45,
            'build_open' => 50, 'build_parts' => 51, 'build_assembled' => 52, 'build_tested' => 53, 'build_done' => 54,
            'art_fitting' => 60, 'art_kit' => 61,
        ],
    ];

    public function testAKitOrderCarriesTheArtAndIsReadAsKit(): void
    {
        $s      = Structure::fromArray(self::S);
        $values = (new OrderChanges($s))->create(100, [305], '5', null, 'Kit A', true)[1]['values'];

        self::assertSame('61', $values['27']);
        self::assertArrayNotHasKey('27', (new OrderChanges($s))->create(100, [305], '5', null, 'A')[1]['values']);

        $orders = new Table([500 => [10 => ['Kit A'], 20 => ['100'], 21 => ['5'], 22 => ['41'], 23 => ['600'], 27 => ['61']]]);
        self::assertTrue((new BuildOrders($s))->one(500, $orders, new Table([600 => [30 => ['305']]]), new Table([]))->kit);
        $orders = new Table([500 => [10 => ['A'], 20 => ['100'], 21 => ['5'], 22 => ['41'], 23 => ['600']]]);
        self::assertFalse((new BuildOrders($s))->one(500, $orders, new Table([600 => [30 => ['305']]]), new Table([]))->kit);
    }

    public function testAKitNeedsTheArtInTheModel(): void
    {
        $raw = self::S;
        unset($raw['fields']['order_art']);
        $this->expectException(ManufacturingError::class);
        (new OrderChanges(Structure::fromArray($raw)))->create(100, [305], '5', null, 'Kit A', true);
    }

    public function testAKitHasNoTestStep(): void
    {
        $kit = new BuildOrder(9, 'Kit', 1, 2, 'order_building', 70, null, null, [new Assembly(600, 305, [], [], 0, 2)], '', null, true);
        $this->expectException(ManufacturingError::class);
        (new OrderChanges(Structure::fromArray(self::S)))->setStep($kit, 'order_test', '2026-11-17');
    }
}
