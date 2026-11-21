<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * The order's stopwatch (D-1475): started and stopped on the order's page, the time summed in minutes at the order, and
 * handed to the completion, which divides it by the boards when it was the time of the whole completion.
 *
 * ```mermaid
 * flowchart LR
 *   S["Start: läuft seit = jetzt"] --> P["Stopp: Summe += jetzt − läuft seit"]
 *   P --> S
 *   P --> F["Abschluss: Summe → Produktionszeit, Summe = 0"]
 * ```
 *
 * The time is counted here, on the server, never in the browser — the page only shows it running.
 *
 * @see docs/NewConcept/90-decision-log.md D-1475
 */
final class Stopwatch
{
    /** Only while the parts are staged and the boards are built — not in «Test» (architecture 19.11.). */
    public const STEPS = ['order_staging', 'order_building'];

    /** @param bool $asDuration Feldkatalog 21.11.: «Erfasste Zeit» is a duration (a unit value), no longer a plain number of minutes */
    public function __construct(private readonly Structure $s, private readonly bool $asDuration = false)
    {
    }

    /** Units the structure knows → minutes per unit (D-1425: the stock stores times in seconds). */
    private const MINUTES_PER = ['unit_second' => 1 / 60, 'unit_minute' => 1.0, 'unit_hour' => 60.0];

    /**
     * Feldkatalog 21.11.: the minutes a stored duration stands for. A missing or foreign unit is an error, never read as
     * minutes (PR-4) — the number alone does not say what it counts.
     */
    public static function durationMinutes(?string $amount, ?string $unit, Structure $s): float
    {
        foreach (self::MINUTES_PER as $key => $factor) {
            if ($unit !== null && $s->hasValue($key) && (string) $s->value($key) === $unit) {
                return (float) str_replace(',', '.', (string) $amount) * $factor;
            }
        }

        throw ManufacturingError::unknownTimeUnit((string) $unit);
    }

    public function exists(): bool
    {
        return $this->s->hasField('order_worked') && $this->s->hasField('order_running');
    }

    public static function runs(BuildOrder $order): bool
    {
        return $order->runningSince !== null;
    }

    /** @return float minutes recorded so far, the running stretch included */
    public static function minutes(BuildOrder $order, string $now): float
    {
        return $order->worked + ($order->runningSince === null ? 0.0 : self::between($order->runningSince, $now));
    }

    /** @return string hours and minutes, «1:05» */
    public static function clock(float $minutes): string
    {
        $whole = (int) floor(max(0.0, $minutes));

        return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }

    /**
     * The production time per board: the time as given, divided by the boards when it was the time of all of them.
     */
    public static function perBoard(float $minutes, bool $forAll, int $boards): float
    {
        return $forAll && $boards > 1 ? $minutes / $boards : $minutes;
    }

    /** @return list<array<string, mixed>> */
    public function start(BuildOrder $order, string $now): array
    {
        if (! $this->exists() || ! in_array($order->step, self::STEPS, true) || self::runs($order)) {
            throw ManufacturingError::unknownStatus('timer_start');
        }

        return [['op' => 'set_values', 'record' => (string) $order->id, 'values' => [(string) $this->s->field('order_running') => $now]]];
    }

    /** @return list<array<string, mixed>> */
    public function stop(BuildOrder $order, string $now): array
    {
        if (! $this->exists() || ! self::runs($order)) {
            throw ManufacturingError::unknownStatus('timer_stop');
        }

        return [['op' => 'set_values', 'record' => (string) $order->id, 'values' => [
            (string) $this->s->field('order_worked')  => $this->worked(self::minutes($order, $now)),
            (string) $this->s->field('order_running') => '',
        ]]];
    }

    /** @return string|array<string, array<string, string>> the plain minutes, or a new duration part in seconds */
    private function worked(float $minutes): string|array
    {
        if (! $this->asDuration) {
            return self::number($minutes);
        }

        return ['neu' => [
            (string) $this->s->field('unit_amount') => self::number($minutes * 60),
            (string) $this->s->field('unit_unit')   => (string) $this->s->value('unit_second'),
        ]];
    }

    /**
     * The completion booked the time onto its boards: back to nothing, a running stopwatch stopped — in the completion's act,
     * so one undo takes both back.
     *
     * @return list<array<string, mixed>>
     */
    public function reset(BuildOrder $order): array
    {
        if (! $this->exists() || ($order->worked <= 0.0 && ! self::runs($order))) {
            return [];
        }

        return [['op' => 'set_values', 'record' => (string) $order->id, 'values' => [
            (string) $this->s->field('order_worked')  => '',
            (string) $this->s->field('order_running') => '',
        ]]];
    }

    /** Minutes between two «Y-m-d H:i:s» in UTC; a clock set back counts nothing. */
    private static function between(string $since, string $now): float
    {
        $from = strtotime($since . ' UTC');
        $to   = strtotime($now . ' UTC');

        return $from === false || $to === false ? 0.0 : max(0, $to - $from) / 60;
    }

    private static function number(float $minutes): string
    {
        $text = number_format($minutes, 2, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') ?: '0' : $text;
    }
}
