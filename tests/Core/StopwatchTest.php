<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\BuildOrders;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\Stopwatch;
use TaxmodManufacturing\Core\Structure;
use TaxmodManufacturing\Core\Table;

final class StopwatchTest extends TestCase
{
    private function watch(bool $fields = true): Stopwatch
    {
        return new Stopwatch(Structure::fromArray(['fields' => $fields ? ['order_worked' => 80, 'order_running' => 81] : []]));
    }

    private function order(string $step = 'order_building', float $worked = 0.0, ?string $since = null): BuildOrder
    {
        return new BuildOrder(10, 'A', 1, 4, $step, null, null, null, [new Assembly(11, 300, [], [], 0, 0)], '', null, false, '', $worked, $since);
    }

    public function testStartWritesTheMoment(): void
    {
        self::assertSame([['op' => 'set_values', 'record' => '10', 'values' => ['81' => '2026-11-19 06:00:00']]], $this->watch()->start($this->order(), '2026-11-19 06:00:00'));
    }

    public function testStopAddsTheStretchToTheSum(): void
    {
        $changes = $this->watch()->stop($this->order('order_building', 30.0, '2026-11-19 06:00:00'), '2026-11-19 06:45:30');

        self::assertSame(['80' => '75.5', '81' => ''], $changes[0]['values']);
    }

    public function testRunningStretchCountsInTheShownTime(): void
    {
        self::assertSame(90.0, Stopwatch::minutes($this->order('order_building', 60.0, '2026-11-19 06:00:00'), '2026-11-19 06:30:00'));
        self::assertSame('1:30', Stopwatch::clock(90.0));
        self::assertSame('0:00', Stopwatch::clock(0.0));
    }

    public function testNotInTestAndNotTwice(): void
    {
        foreach ([$this->order('order_test'), $this->order('order_building', 0.0, '2026-11-19 06:00:00')] as $order) {
            try {
                $this->watch()->start($order, '2026-11-19 07:00:00');
                self::fail('started');
            } catch (ManufacturingError) {
                self::assertTrue(true);
            }
        }

        $this->expectException(ManufacturingError::class);
        $this->watch()->stop($this->order(), '2026-11-19 07:00:00');
    }

    public function testStagingMayStart(): void
    {
        self::assertNotSame([], $this->watch()->start($this->order('order_staging'), '2026-11-19 07:00:00'));
    }

    public function testWithoutTheFieldsThereIsNoStopwatch(): void
    {
        self::assertFalse($this->watch(false)->exists());
        self::assertSame([], $this->watch(false)->reset($this->order('order_building', 20.0)));
    }

    public function testCompletionClearsBothAndNothingWhenEmpty(): void
    {
        self::assertSame(['80' => '', '81' => ''], $this->watch()->reset($this->order('order_building', 0.0, '2026-11-19 06:00:00'))[0]['values']);
        self::assertSame([], $this->watch()->reset($this->order()));
    }

    public function testTimeOfAllBoardsIsDividedByThem(): void
    {
        self::assertSame(15.0, Stopwatch::perBoard(60.0, true, 4));
        self::assertSame(60.0, Stopwatch::perBoard(60.0, false, 4));
        self::assertSame(60.0, Stopwatch::perBoard(60.0, true, 1));
    }

    public function testAClockSetBackCountsNothing(): void
    {
        self::assertSame(5.0, Stopwatch::minutes($this->order('order_building', 5.0, '2026-11-19 08:00:00'), '2026-11-19 07:00:00'));
    }

    /** Feldkatalog 21.11.: «Erfasste Zeit» reads as the plain number and, where the modeller read a duration, as its seconds. */
    public function testWorkedReadsTheNumberAndTheDuration(): void
    {
        $s = Structure::fromArray([
            'nodes'  => ['order' => 5],
            'fields' => ['label' => 10, 'order_project' => 20, 'order_count' => 21, 'order_status' => 22, 'order_assemblies' => 23, 'order_worked' => 80, 'order_running' => 81],
            'values' => ['order_building' => 41],
        ]);
        $orders = new Table([500 => [21 => ['1'], 22 => ['41'], 80 => ['75.5']], 501 => [21 => ['1'], 22 => ['41'], 80 => ['900']], 502 => [21 => ['1'], 22 => ['41']]]);
        $read   = static fn (int $id, array $seconds): float => (new BuildOrders($s))->one($id, $orders, new Table([]), new Table([]), [], $seconds)->worked;

        self::assertSame(75.5, $read(500, []));
        self::assertSame(75.5, $read(500, [501 => 4530.0]));
        self::assertSame(75.5, $read(501, [501 => 4530.0]));
        self::assertSame(0.0, $read(502, [501 => 4530.0]));
    }

    /** Feldkatalog 21.11.: once the field is a duration the stopwatch writes a new part in seconds. */
    public function testStopWritesADurationOnceTheFieldIsOne(): void
    {
        $s     = Structure::fromArray(['fields' => ['order_worked' => 80, 'order_running' => 81, 'unit_amount' => 90, 'unit_unit' => 91], 'values' => ['unit_second' => 4040]]);
        $watch = new Stopwatch($s, true);

        self::assertSame(['80' => ['neu' => ['90' => '4530', '91' => '4040']], '81' => ''], $watch->stop($this->order('order_building', 30.0, '2026-11-19 06:00:00'), '2026-11-19 06:45:30')[0]['values']);
    }
}
