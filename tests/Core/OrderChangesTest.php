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
        'nodes'  => ['project' => 1, 'board' => 2, 'board_revision' => 3, 'bom_position' => 4, 'order' => 5, 'assembly' => 6, 'fitting' => 7],
        'fields' => [
            'label' => 10, 'project_boards' => 11, 'board_revisions' => 12, 'revision_positions' => 13, 'position_part' => 14, 'position_count' => 15,
            'order_project' => 20, 'order_count' => 21, 'order_status' => 22, 'order_assemblies' => 23, 'order_started' => 24, 'order_finished' => 25,
            'assembly_revision' => 30, 'assembly_status' => 31, 'assembly_unused' => 32, 'assembly_note' => 33,
            'assembly_fitted' => 34, 'fitting_position' => 35, 'fitting_boards' => 36, 'assembly_tested' => 37, 'assembly_done' => 38,
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
        self::assertSame([200 => 305, 201 => 310], $changes->newestRevisions(100, $projects, $boards));

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

    /** His s7vrm, 10 pieces, 2 built: every position on 2 boards but the coil; the order goes «in Bau». */
    public function testFittedCountsAreAddedAndStartThePlannedOrder(): void
    {
        $order   = $this->order('40', [[[], 0]]);
        $changes = (new OrderChanges($this->s()))->updateAssembly($order, 600, [700 => 2, 701 => 0], [], [700, 701], [], null, null, 'Spule erst durchmessen', '2026-11-04');

        self::assertSame(['32' => ['values' => []], '34' => ['f700' => ['35' => '700', '36' => '2']], '33' => 'Spule erst durchmessen'], $changes[0]['values']);
        self::assertSame(['22' => '41', '24' => '2026-11-04'], $changes[1]['values']);
    }

    public function testAnExistingCountIsRewrittenInPlace(): void
    {
        $changes = (new OrderChanges($this->s()))->updateAssembly($this->order('41', [[[700 => 2], 0]]), 600, [700 => 1], [], [700], [], null, null, null, '2026-11-04');

        self::assertSame(['op' => 'set_values', 'record' => '800', 'values' => ['36' => '1']], $changes[1]);
        self::assertCount(2, $changes);
    }

    public function testCountsStayWithinThePieces(): void
    {
        $this->expectException(ManufacturingError::class);
        (new OrderChanges($this->s()))->updateAssembly($this->order('40', [[[], 0]]), 600, [700 => 11], [], [700], [], null, null, null, '2026-11-04');
    }

    public function testOnlyAnOptionMayBeLeftOut(): void
    {
        $this->expectException(ManufacturingError::class);
        (new OrderChanges($this->s()))->updateAssembly($this->order('40', [[[], 0]]), 600, [], [700], [700, 701], [701], null, null, null, '2026-11-04');
    }

    public function testOrderClosesOnlyWhenEveryBoardIsDone(): void
    {
        $changes = new OrderChanges($this->s());
        self::assertSame(['22' => '42', '25' => '2026-11-04'], $changes->setStep($this->order('41', [[[700 => 3], 3]]), 'order_done', '2026-11-04')[0]['values']);

        $this->expectException(ManufacturingError::class);
        $changes->setStep($this->order('41', [[[700 => 3], 2]]), 'order_done', '2026-11-04');
    }

    public function testReopenGoesBackToBuildingAndClearsTheEnd(): void
    {
        $changes = new OrderChanges($this->s());
        self::assertSame(['22' => '41', '25' => ''], $changes->setStep($this->order('42', [[[700 => 3], 3]]), 'reopen', '2026-11-04')[0]['values']);
        self::assertSame(['22' => '40', '25' => ''], $changes->setStep($this->order('43', [[[], 0]]), 'reopen', '2026-11-04')[0]['values']);
    }

    /** @param list<array{0: array<int, int>, 1: int}> $parts fitted (position → boards) and finished boards per assembly, ids from 600, fitted parts from 800 */
    private function order(string $status, array $parts): \TaxmodManufacturing\Core\BuildOrder
    {
        $assemblies = [];
        $fittings   = [];
        $next       = 800;

        foreach ($parts as $i => [$fitted, $done]) {
            $ids = [];

            foreach ($fitted as $position => $boards) {
                $fittings[$next] = [35 => [(string) $position], 36 => [(string) $boards]];
                $ids[]           = (string) $next++;
            }

            $assemblies[600 + $i] = [30 => ['305'], 34 => $ids, 38 => [(string) $done]];
        }

        $orders = new Table([500 => [10 => ['Fertigung s7vrm'], 20 => ['100'], 21 => ['3'], 22 => [$status], 23 => array_map('strval', array_keys($assemblies))]]);

        return (new BuildOrders($this->s()))->one(500, $orders, new Table($assemblies), new Table($fittings));
    }
}
