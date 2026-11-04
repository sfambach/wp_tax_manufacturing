<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/** One board of a build order: the revision built, how far it is, and the parts-list positions still missing (D-1142). */
final class Assembly
{
    /** @param list<int> $open positions of the revision's parts list not yet fitted */
    public function __construct(
        public readonly int $id,
        public readonly int $revision,
        public readonly string $step,
        public readonly array $open,
        public readonly string $note = '',
    ) {
    }

    public function isDone(): bool
    {
        return $this->step === 'build_done';
    }
}
