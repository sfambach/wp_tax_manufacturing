<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/** A build order as read from the model: project, pieces, step, and its assemblies (D-1142). */
final class BuildOrder
{
    /** @param list<Assembly> $assemblies */
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly int $project,
        public readonly int $count,
        public readonly string $step,
        public readonly ?int $box,
        public readonly ?string $started,
        public readonly ?string $finished,
        public readonly array $assemblies,
    ) {
    }

    public function assembly(int $id): ?Assembly
    {
        foreach ($this->assemblies as $assembly) {
            if ($assembly->id === $id) {
                return $assembly;
            }
        }

        return null;
    }

    /** Every assembly has all its boards finished. */
    public function isFinished(): bool
    {
        return $this->assemblies !== [] && array_filter($this->assemblies, fn (Assembly $a): bool => $a->done < $this->count) === [];
    }

    /** @return int boards finished, summed over the assemblies */
    public function doneBoards(): int
    {
        return array_sum(array_map(static fn (Assembly $a): int => $a->done, $this->assemblies));
    }

    public function isClosed(): bool
    {
        return in_array($this->step, ['order_done', 'order_cancelled'], true);
    }
}
