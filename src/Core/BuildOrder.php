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
        /** the order's note; «Fehlgeschlagen» adds its finding here (D-1464) */
        public readonly string $note = '',
        /** D-1475: minutes of the stopwatch's finished stretches */
        public readonly float $worked = 0.0,
        /** D-1475: «Y-m-d H:i:s» UTC since when the stopwatch runs; null when it stands */
        public readonly ?string $runningSince = null,
    ) {
    }

    /**
     * Stefan 21.11. 10:34: the order as it would be with these plans — the assemblies of a plan it keeps, an empty one with a
     * negative id for a plan it gains. A preview, never stored.
     *
     * @param list<int> $revisions
     */
    public function withRevisions(array $revisions): self
    {
        $kept = [];
        $new  = 0;

        foreach (array_values(array_unique(array_filter($revisions, static fn (int $r): bool => $r > 0))) as $revision) {
            $own    = array_values(array_filter($this->assemblies, static fn (Assembly $a): bool => $a->revision === $revision));
            $kept[] = $own[0] ?? new Assembly(--$new, $revision, [], []);
        }

        return new self($this->id, $this->label, $this->project, $this->count, $this->step, $this->box, $this->started, $this->finished, $kept,
            $this->shortageReason, $this->due, $this->kit, $this->note, $this->worked, $this->runningSince);
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

    public function isClosed(): bool
    {
        return in_array($this->step, ['order_done', 'order_failed', 'order_cancelled'], true);
    }
}
