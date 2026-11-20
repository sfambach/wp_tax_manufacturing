<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Findings;

final class FindingsTest extends TestCase
{
    public function testNewestFirstEachOnceAtMostFive(): void
    {
        $rows = [
            ['note' => 'C5 kalte Lötstelle', 'tested_on' => '2026-11-18'],
            ['note' => 'R1 nachlöten', 'tested_on' => '2026-11-20'],
            ['note' => 'C5 kalte Lötstelle', 'tested_on' => '2026-11-19'],
            ['note' => '', 'tested_on' => '2026-11-21'],
            ['note' => 'a', 'tested_on' => '2026-11-10'],
            ['note' => 'b', 'tested_on' => '2026-11-09'],
            ['note' => 'c', 'tested_on' => '2026-11-08'],
            ['note' => 'd', 'tested_on' => ''],
        ];

        self::assertSame(['R1 nachlöten', 'C5 kalte Lötstelle', 'a', 'b', 'c'], Findings::recent($rows));
    }

    public function testTheLastLineOfANoteIsItsLatest(): void
    {
        self::assertSame(['IC2 getauscht'], Findings::recent([['note' => "Kurzschluss\n IC2 getauscht \n", 'tested_on' => '2026-11-20']]));
    }

    public function testNothingWrittenNothingOffered(): void
    {
        self::assertSame([], Findings::recent([]));
    }
}
