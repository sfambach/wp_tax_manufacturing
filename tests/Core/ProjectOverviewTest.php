<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\ProjectOverview;

/** Fertigung › «Projekte» (Stefan 26.11. «A»): every project has its row, with or without a box. */
final class ProjectOverviewTest extends TestCase
{
    public function testEveryProjectHasItsRowEvenWithoutABox(): void
    {
        $rows = ProjectOverview::rows([7 => 'Atari', 9 => 'MCEBlaster'], [50 => ['project' => 7, 'label' => 'Box A', 'pieces' => 3.0]]);

        self::assertSame([7, 9], array_column($rows, 'project'));
        self::assertSame([50 => 'Box A'], $rows[0]['boxes']);
        self::assertSame(3.0, $rows[0]['pieces']);
        self::assertSame([], $rows[1]['boxes']);
        self::assertNull($rows[1]['pieces']);
    }

    public function testSeveralBoxesAddUpAndFillNoBoxIntoTheOrder(): void
    {
        $rows = ProjectOverview::rows([7 => 'Atari'], [
            50 => ['project' => 7, 'label' => 'Box A', 'pieces' => 3.0],
            51 => ['project' => 7, 'label' => 'Box B', 'pieces' => null],
            52 => ['project' => 7, 'label' => 'Box C', 'pieces' => 2.5],
        ]);

        self::assertSame(5.5, $rows[0]['pieces']);
        self::assertSame(0, ProjectOverview::onlyBox($rows[0]));
        self::assertSame(50, ProjectOverview::onlyBox(ProjectOverview::rows([7 => 'Atari'], [50 => ['project' => 7, 'label' => 'Box A', 'pieces' => null]])[0]));
    }

    public function testABoxOfAnUnknownProjectShowsNowhere(): void
    {
        self::assertSame([], ProjectOverview::rows([], [50 => ['project' => 7, 'label' => 'Box A', 'pieces' => 1.0]]));
    }
}
