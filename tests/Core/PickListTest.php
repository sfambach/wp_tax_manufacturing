<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\PickList;

final class PickListTest extends TestCase
{
    public function testTakesFromTheLargestStockFirstAndKeepsWhatIsFoundNowhere(): void
    {
        $demand = [
            1 => [['thing' => 10, 'missing' => 20.0], ['thing' => 11, 'missing' => 0.0]],
            2 => [['thing' => 10, 'missing' => 5.0], ['thing' => 12, 'missing' => 3.0]],
        ];

        self::assertSame([
            ['thing' => 10, 'place' => 501, 'there' => 18.0, 'take' => 18.0, 'missing' => 25.0],
            ['thing' => 10, 'place' => 500, 'there' => 10.0, 'take' => 7.0, 'missing' => 25.0],
            ['thing' => 12, 'place' => null, 'there' => 0.0, 'take' => 0.0, 'missing' => 3.0],
        ], PickList::of($demand, [10 => [500 => 10.0, 501 => 18.0, 502 => 4.0]]));
    }
}
