<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Costing;
use TaxmodManufacturing\Core\ProductionSettings;
use TaxmodManufacturing\Core\ManufacturingError;

/** D-1425: the price of a completed board. */
final class CostingTest extends TestCase
{
    /** 3 of 9 boards: 2 resistors each from two lots at different prices, the bare board, 12 min, 15 €/h, 0,5 h set-up. */
    public function testPartsSetUpAndWorkAddUp(): void
    {
        $cost = Costing::perBoard(4712, '2026-11-17', 3, [10 => 6.0, 20 => 3.0], [
            10 => [['lot' => 1, 'quantity' => 4.0, 'unit_price' => 0.10, 'source' => 'lot'], ['lot' => 2, 'quantity' => 2.0, 'unit_price' => 0.40, 'source' => 'lot']],
            20 => [['lot' => 3, 'quantity' => 3.0, 'unit_price' => 2.00, 'source' => 'last_purchase']],
        ], 12.0, 15.0, 0.5, 9);

        self::assertSame(2.0, $cost['parts'][0]['quantity']);
        self::assertSame(0.2, $cost['parts'][0]['unit_price']);
        self::assertSame('last_purchase', $cost['parts'][1]['source']);
        self::assertSame(2.4, $cost['parts_total']);
        self::assertTrue($cost['complete']);
        self::assertEqualsWithDelta(0.8333, $cost['setup']['amount'], 0.0001);
        self::assertSame(3.0, $cost['work']['amount']);
        self::assertEqualsWithDelta(6.2333, $cost['total'], 0.0001);
    }

    /** «never 0»: a lot without a price leaves its position «ohne Preis» and the total incomplete. */
    public function testMissingPriceMarksIncomplete(): void
    {
        $cost = Costing::perBoard(1, '2026-11-17', 1, [10 => 1.0, 20 => 1.0], [
            10 => [['lot' => 1, 'quantity' => 1.0, 'unit_price' => 1.5, 'source' => 'lot']],
            20 => [['lot' => 2, 'quantity' => 1.0, 'unit_price' => null, 'source' => 'none']],
        ], 0.0, 15.0, 0.0, 1, [30 => 2.0]);

        self::assertNull($cost['parts'][1]['unit_price']);
        self::assertSame('none', $cost['parts'][1]['source']);
        self::assertFalse($cost['complete']);
        self::assertSame(1.5, $cost['total']);
        self::assertSame([['thing' => 30, 'quantity' => 2.0]], $cost['scrap']);
    }

    /** A part the stock names no lot for is unpriced, not free. */
    public function testPartWithoutLotIsUnpriced(): void
    {
        $cost = Costing::perBoard(1, '2026-11-17', 2, [10 => 2.0], [], 0.0, 15.0, 0.0, 2);

        self::assertNull($cost['parts'][0]['unit_price']);
        self::assertFalse($cost['complete']);
    }

    public function testWageAndSetUpAreSettingsWithDefaults(): void
    {
        self::assertSame(15.0, ProductionSettings::fromArray([])->wage);
        self::assertSame(0.5, ProductionSettings::fromArray([])->setupHours);
        $set = ProductionSettings::fromRows([], [], 0, '17,5', '0,25');
        self::assertSame(['deadlines' => [], 'label' => 0, 'wage' => 17.5, 'setup' => 0.25], $set->toArray());
        self::assertSame(17.5, ProductionSettings::fromArray($set->toArray())->wage);
        $this->expectException(ManufacturingError::class);
        ProductionSettings::fromRows([], [], 0, 'viel');
    }
}
