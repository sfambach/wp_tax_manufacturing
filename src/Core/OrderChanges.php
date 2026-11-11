<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * The change lists for `taxmod/write-data` that keep build orders: new order, an assembly's step and missing positions,
 * closing, cancelling and taking an order up again. Every step can be taken back by the next one (project rule: each
 * user step undoable) — nothing here removes a record.
 *
 * ```mermaid
 * flowchart LR
 *   P["Projekt"] -->|each board: newest revision| N["new order + one assembly per board"]
 *   N -->|first position fitted| B["order «in Bau», Begonnen am"]
 *   B -->|every board of every assembly «fertig»| D["order may close: «fertig», Fertig am"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/NewConcept/90-decision-log.md D-1142
 */
final class OrderChanges
{
    public function __construct(private readonly Structure $s)
    {
    }

    /**
     * The revision built for each board of a project: its newest (largest id), as the project-box check reads it (D-1139, INFERRED).
     *
     * @return list<int>
     */
    public function revisionsOf(int $project, Table $projects, Table $boards): array
    {
        return array_values($this->newestRevisions($project, $projects, $boards));
    }

    /** @return array<int, int> board id => its newest revision, for the boards that have one */
    public function newestRevisions(int $project, Table $projects, Table $boards): array
    {
        $s   = $this->s;
        $out = [];

        foreach ($projects->all($project, $s->field('project_boards')) as $board) {
            $ids = array_map('intval', $boards->all((int) $board, $s->field('board_revisions')));

            if ($ids !== []) {
                $out[(int) $board] = max($ids);
            }
        }

        return $out;
    }

    /**
     * @param list<int> $revisions from {@see revisionsOf()}
     * @return list<array<string, mixed>>
     */
    public function create(int $project, array $revisions, string $count, ?int $box, string $title): array
    {
        $s = $this->s;

        if ($revisions === []) {
            throw ManufacturingError::projectWithoutBoards($project);
        }

        if (preg_match('/^\d+$/', trim($count)) !== 1 || (int) $count < 1) {
            throw ManufacturingError::countNotPositive($count);
        }

        $parts = [];

        foreach ($revisions as $i => $revision) {
            $parts['b' . ($i + 1)] = [
                (string) $s->field('assembly_revision') => (string) $revision,
                (string) $s->field('assembly_status')   => (string) $s->value('build_open'),
            ];
        }

        $values = [
            (string) $s->field('label')            => $title,
            (string) $s->field('order_project')    => (string) $project,
            (string) $s->field('order_count')      => (string) (int) $count,
            (string) $s->field('order_status')     => (string) $s->value('order_planned'),
            (string) $s->field('order_assemblies') => $parts,
        ];

        if ($box !== null && $box > 0 && $s->hasField('order_box')) {
            $values[(string) $s->field('order_box')] = (string) $box;
        }

        return [
            ['op' => 'create_record', 'ref' => 'auftrag', 'node' => $s->node('order')],
            ['op' => 'set_values', 'record' => '@auftrag', 'values' => $values],
        ];
    }

    /**
     * An assembly's fitted counts, options left out, tested and finished boards and note; a planned order goes «in Bau»,
     * with today as its start, once anything is fitted (D-1146). A count is a part of the assembly: an existing one is
     * rewritten in place, a new one added — none is removed, a count taken back is written as the lower number.
     *
     * @param array<int, int> $fitted    position → boards
     * @param list<int>       $unused
     * @param list<int>       $positions every position of the revision
     * @param list<int>       $options   the positions that may be left out
     * @return list<array<string, mixed>>
     */
    public function updateAssembly(BuildOrder $order, int $assembly, array $fitted, array $unused, array $positions, array $options, ?int $tested, ?int $done, ?string $note, string $today): array
    {
        $s    = $this->s;
        $part = $order->assembly($assembly) ?? throw ManufacturingError::assemblyNotOfOrder($assembly, $order->id);

        foreach ([...array_values($fitted), $tested ?? 0, $done ?? 0] as $boards) {
            if ($boards < 0 || $boards > $order->count) {
                throw ManufacturingError::boardsOutOfRange($boards, $order->count);
            }
        }

        $unused = array_values(array_unique(array_map('intval', $unused)));

        foreach ([...$unused, ...array_keys($fitted)] as $position) {
            if (! in_array($position, in_array($position, $unused, true) ? $options : $positions, true)) {
                throw ManufacturingError::positionNotOnRevision($position, $part->revision);
            }
        }

        $changes = [];
        $added   = [];

        foreach ($fitted as $position => $boards) {
            $existing = $part->fitted[$position] ?? null;

            if ($existing !== null) {
                if ($existing['boards'] !== $boards) {
                    $changes[] = ['op' => 'set_values', 'record' => (string) $existing['part'], 'values' => [(string) $s->field('fitting_boards') => (string) $boards]];
                }
            } elseif ($boards > 0) {
                $added['f' . $position] = [(string) $s->field('fitting_position') => (string) $position, (string) $s->field('fitting_boards') => (string) $boards];
            }
        }

        $values = [(string) $s->field('assembly_unused') => ['values' => array_map('strval', $unused)]];

        if ($added !== []) {
            // A non-numeric key adds a new part (taxmod/apply, set_values); the parts already there stay.
            $values[(string) $s->field('assembly_fitted')] = $added;
        }

        foreach (['assembly_tested' => $tested, 'assembly_done' => $done, 'assembly_note' => $note] as $key => $value) {
            if ($value !== null && $s->hasField($key)) {
                $values[(string) $s->field($key)] = is_string($value) ? trim($value) : (string) $value;
            }
        }

        array_unshift($changes, ['op' => 'set_values', 'record' => (string) $assembly, 'values' => $values]);

        if ($order->step === 'order_planned' && array_filter($fitted) !== []) {
            $changes[] = ['op' => 'set_values', 'record' => (string) $order->id, 'values' => $this->stepValues('order_building', $order->started === null ? $today : null, null)];
        }

        return $changes;
    }

    /**
     * The box the order's parts lie in (D-1030 project box); 0 takes it away.
     *
     * @return list<array<string, mixed>>
     */
    public function setBox(BuildOrder $order, int $box): array
    {
        return [['op' => 'set_values', 'record' => (string) $order->id, 'values' => [(string) $this->s->field('order_box') => $box > 0 ? (string) $box : '']]];
    }

    /**
     * Closing, cancelling or taking up again. «fertig» needs every assembly done; taking up goes back to «in Bau», or to
     * «geplant» while no assembly has left «offen».
     *
     * @param 'order_done'|'order_cancelled'|'reopen' $to
     * @return list<array<string, mixed>>
     */
    public function setStep(BuildOrder $order, string $to, string $today): array
    {
        if ($to === 'order_done') {
            if (! $order->isFinished()) {
                throw ManufacturingError::assembliesNotDone($order->id);
            }

            return [['op' => 'set_values', 'record' => (string) $order->id, 'values' => $this->stepValues('order_done', null, $today)]];
        }

        if ($to === 'order_cancelled') {
            return [['op' => 'set_values', 'record' => (string) $order->id, 'values' => $this->stepValues('order_cancelled', null, null)]];
        }

        if ($to === 'reopen') {
            $started = array_filter($order->assemblies, static fn (Assembly $a): bool => array_filter(array_column($a->fitted, 'boards')) !== []) !== [];

            return [['op' => 'set_values', 'record' => (string) $order->id, 'values' => $this->stepValues($started ? 'order_building' : 'order_planned', null, '')]];
        }

        throw ManufacturingError::unknownStatus($to);
    }

    /**
     * @param string|null $finished '' clears it
     * @return array<string, string>
     */
    private function stepValues(string $step, ?string $started, ?string $finished): array
    {
        $s      = $this->s;
        $values = [(string) $s->field('order_status') => (string) $s->value($step)];

        if ($started !== null && $s->hasField('order_started')) {
            $values[(string) $s->field('order_started')] = $started;
        }

        if ($finished !== null && $s->hasField('order_finished')) {
            $values[(string) $s->field('order_finished')] = $finished;
        }

        return $values;
    }
}
