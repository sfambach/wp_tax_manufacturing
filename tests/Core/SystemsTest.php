<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Systems;

/** D-1460: the plan's systems reach the new hardware record as confirmed; empty stays empty. */
final class SystemsTest extends TestCase
{
    /** The whole tree below «Computer-Systeme»: XT and AT lie under PC. */
    private const OFFERED = [110519 => 'PC', 110520 => 'XT', 110521 => 'AT', 110522 => 'C64'];

    public function testAPlanWithXtGivesXt(): void
    {
        self::assertSame([110520], Systems::kept([110520], self::OFFERED));
    }

    public function testAnEmptyPlanStaysEmpty(): void
    {
        self::assertSame([], Systems::kept([], self::OFFERED));
    }

    public function testOnlyWhatIsOfferedEachOnce(): void
    {
        self::assertSame([110521, 110522], Systems::kept([110521, 999, 110522, 110521], self::OFFERED));
    }
}
