<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * One board of a build order: the revision built, on how many boards each position is fitted, the options left out, and
 * how many boards are tested and finished (D-1145, D-1146).
 */
final class Assembly
{
    /**
     * @param array<int, array{part: int, boards: int}> $fitted position → the part record holding the count, and the count
     * @param list<int>                                  $unused optional positions this build leaves out
     */
    public function __construct(
        public readonly int $id,
        public readonly int $revision,
        public readonly array $fitted,
        public readonly array $unused,
        public readonly int $tested = 0,
        public readonly int $done = 0,
        public readonly string $note = '',
        // D-1572: the bare board as a material of its own («Platinen» → «für Revision»); 0 while the revision has none.
        public readonly int $bareBoard = 0,
    ) {
    }

    /** D-1572: the bare board material of this assembly — its own record under «Platinen»; 0 while the revision has none. */
    public function boardThing(): int
    {
        return $this->bareBoard;
    }

    public function fittedOn(int $position): int
    {
        return $this->fitted[$position]['boards'] ?? 0;
    }
}
