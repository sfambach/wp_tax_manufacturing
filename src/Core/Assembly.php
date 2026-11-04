<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/** One board of a build order: the revision built, how far it is, and the optional positions left out of it. */
final class Assembly
{
    /** @param list<int> $unused optional positions of the revision's parts list that this build leaves out */
    public function __construct(
        public readonly int $id,
        public readonly int $revision,
        public readonly string $step,
        public readonly array $unused,
        public readonly string $note = '',
    ) {
    }

    public function isDone(): bool
    {
        return $this->step === 'build_done';
    }
}
