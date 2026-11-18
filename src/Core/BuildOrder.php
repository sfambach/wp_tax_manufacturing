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
        /** D-1301: why it went into building with parts missing; '' when it did not */
        public readonly string $shortageReason = '',
        /** D-1300: the wished date, Y-m-d; null when none is set */
        public readonly ?string $due = null,
        /** D-1428: a kit — board and parts packed for sale, no fitting and no «Test» */
        public readonly bool $kit = false,
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

    /**
     * D-1419: every board is completed and each of them has passed its test or is scrapped — «Fertiggestellt» may follow.
     *
     * @param list<string> $states the state of each completed board as the stock names it: untested|passed|failed|scrapped
     */
    public function testsSettled(array $states): bool
    {
        return $this->isFinished() && $states !== [] && array_diff($states, ['passed', 'scrapped']) === [];
    }

    /** D-1464: the assemblies marked «Fehlgeschlagen». @return list<Assembly> */
    public function failedAssemblies(): array
    {
        return array_values(array_filter($this->assemblies, static fn (Assembly $a): bool => $a->failed));
    }

    /**
     * D-1464, the one place of the mixed case (INFERRED, open with him): the order failed when every assembly of it failed;
     * one failed among several leaves it «Fertig».
     */
    public function hasFailed(): bool
    {
        return $this->assemblies !== [] && count($this->failedAssemblies()) === count($this->assemblies);
    }

    /** D-1463 with the same rule as {@see hasFailed()}: this revision failed in this order when every assembly of it did. */
    public function failedFor(int $revision): bool
    {
        $own = array_filter($this->assemblies, static fn (Assembly $a): bool => $a->revision === $revision);

        return $own !== [] && array_filter($own, static fn (Assembly $a): bool => ! $a->failed) === [];
    }

    public function isClosed(): bool
    {
        return in_array($this->step, ['order_done', 'order_failed', 'order_cancelled'], true);
    }
}
