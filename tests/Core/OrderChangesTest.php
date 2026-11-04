<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\BuildOrders;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\OrderChanges;
use TaxmodManufacturing\Core\Structure;
use TaxmodManufacturing\Core\Table;

final class OrderChangesTest extends TestCase
{
    private const S = [
        'nodes'  => ['project' => 1, 'board' => 2, 'board_revision' => 3, 'bom_position' => 4, 'order' => 5, 'assembly' => 6],
        'fields' => [
            'label' => 10, 'project_boards' => 11, 'board_revisions' => 12, 'revision_positions' => 13, 'position_part' => 14, 'position_count' => 15,
            'order_project' => 20, 'order_count' => 21, 'order_status' => 22, 'order_assemblies' => 23, 'order_started' => 24, 'order_finished' => 25,
            'assembly_revision' => 30, 'assembly_status' => 31, 'assembly_unused' => 32, 'assembly_note' => 33,
        ],
        'values' => [
            'order_planned' => 40, 'order_building' => 41, 'order_done' => 42, 'order_cancelled' => 43,
            'build_open' => 50, 'build_parts' => 51, 'build_assembled' => 52, 'build_tested' => 53, 'build_done' => 54,
        ],
    ];

    private function s(): Structure
    {
        return Structure::fromArray(self::S);
    }

    public function testStructureIsCompleteWithAllKeys(): void
    {
        self::assertSame([], $this->s()->missing());
        self::assertContains('nodes.order', Structure::fromArray(['nodes' => ['project' => 1]])->missing());
    }

    public function testNewOrderTakesTheNewestRevisionOfEachBoard(): void
    {
        $changes   = new OrderChanges($this->s());
        $projects  = new Table([100 => [11 => ['200', '201']]]);
        $boards    = new Table([200 => [12 => ['300', '305']], 201 => [12 => ['310']]]);
        $revisions = $changes->revisionsOf(100, $projects, $boards);

        self::assertSame([305, 310], $revisions);

        $list   = $changes->create(100, $revisions, '3', null, 'Fertigung s7vrm');
        $values = $list[1]['values'];

        self::assertSame(['op' => 'create_record', 'ref' => 'auftrag', 'node' => 5], $list[0]);
        self::assertSame('3', $values['21']);
        self::assertSame('40', $values['22']);
        self::assertSame([['30' => '305', '31' => '50'], ['30' => '310', '31' => '50']], array_values($values['23']));
    }

    public function testProjectWithoutBoardsIsRefused(): void
    {
        $this->expectException(ManufacturingError::class);
        (new OrderChanges($this->s()))->create(100, [], '1', null, 'x');
    }

    public function testCountMustBePositive(): void
    {
        $this->expectException(ManufacturingError::class);
        (new OrderChanges($this->s()))->create(100, [305], '0', null, 'x');
    }

    /** His s7vrm: every board fitted but the coil — «bestückt», the coil position open, the order goes «in Bau». */
    public function testAssembledBoardWithMissingCoilStartsThePlannedOrder(): void
    {
        $order   = $this->order('40', [['50', []]]);
        $changes = (new OrderChanges($this->s()))->updateAssembly($order, 600, 'build_assembled', [701], [700, 701], '2026-11-04', 'Spule erst durchmessen');

        self::assertSame(['op' => 'set_values', 'record' => '600', 'values' => ['31' => '52', '32' => ['values' => ['701']], '33' => 'Spule erst durchmessen']], $changes[0]);
        self::assertSame(['22' => '41', '24' => '2026-11-04'], $changes[1]['values']);
    }

    public function testOpenPositionMustBeOnTheRevision(): void
    {
        $this->expectException(ManufacturingError::class);
        (new OrderChanges($this->s()))->updateAssembly($this->order('40', [['50', []]]), 600, 'build_assembled', [999], [700, 701], '2026-11-04');
    }

    public function testOrderClosesOnlyWhenEveryBoardIsDone(): void
    {
        $changes = new OrderChanges($this->s());
        self::assertSame(['22' => '42', '25' => '2026-11-04'], $changes->setStep($this->order('41', [['54', []]]), 'order_done', '2026-11-04')[0]['values']);

        $this->expectException(ManufacturingError::class);
        $changes->setStep($this->order('41', [['52', [701]]]), 'order_done', '2026-11-04');
    }

    public function testReopenGoesBackToBuildingAndClearsTheEnd(): void
    {
        $changes = new OrderChanges($this->s());
        self::assertSame(['22' => '41', '25' => ''], $changes->setStep($this->order('42', [['54', []]]), 'reopen', '2026-11-04')[0]['values']);
        self::assertSame(['22' => '40', '25' => ''], $changes->setStep($this->order('43', [['50', []]]), 'reopen', '2026-11-04')[0]['values']);
    }

    /** @param list<array{0: string, 1: list<int>}> $parts status value id and open positions per assembly, ids from 600 */
    private function order(string $status, array $parts): \TaxmodManufacturing\Core\BuildOrder
    {
        $assemblies = [];

        foreach ($parts as $i => [$step, $open]) {
            $assemblies[600 + $i] = [30 => ['305'], 31 => [$step], 32 => array_map('strval', $open)];
        }

        $orders = new Table([500 => [10 => ['Fertigung s7vrm'], 20 => ['100'], 21 => ['3'], 22 => [$status], 23 => array_map('strval', array_keys($assemblies))]]);

        return (new BuildOrders($this->s()))->one(500, $orders, new Table($assemblies));
    }
}
