<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\ProductionSettings;

final class ProductionSettingsTest extends TestCase
{
    public function testNothingStoredGivesEbayTwoDays(): void
    {
        self::assertSame(['eBay' => 2], ProductionSettings::fromArray([])->deadlines);
        self::assertSame([], ProductionSettings::fromArray(['deadlines' => []])->deadlines);
    }

    public function testRowsWithoutChannelAreDroppedAndDaysMustBeWhole(): void
    {
        $settings = ProductionSettings::fromRows(['eBay', ' Shop ', ''], ['3', '0', '']);

        self::assertSame(['deadlines' => ['eBay' => 3, 'Shop' => 0], 'label' => 0], $settings->toArray());
        self::assertSame(49037, ProductionSettings::fromArray(ProductionSettings::fromRows(['eBay'], ['2'], 49037)->toArray())->label);
        self::assertSame($settings->deadlines, ProductionSettings::fromArray($settings->toArray())->deadlines);

        $this->expectException(ManufacturingError::class);
        ProductionSettings::fromRows(['eBay'], ['1,5']);
    }
}
