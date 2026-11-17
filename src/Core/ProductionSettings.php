<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * The settings of «Produktion» (D-1305): how many days a build for a customer may take, per sales channel; the hourly wage and
 * the set-up time per order of the price (D-1425).
 *
 * @see /mnt/project-files/taxmod-fertigung/spezifikation-fertigungsprozess.md §8b
 */
final class ProductionSettings
{
    /** His word 2026-11-11: «in der Regel sind das zwei Tage Fertigung für Kunden» — eBay 2 days until he sets otherwise. */
    public const DEFAULT_DEADLINES = ['eBay' => 2];
    /** His words 17.11. 21:41/21:43: «sagen wir hier mal 15€ soll anpassbar sein», «bereitstellung halbe stunde pro produktion». */
    public const DEFAULT_WAGE  = 15.0;
    public const DEFAULT_SETUP = 0.5;

    /**
     * @param array<string, int> $deadlines channel → days
     * @param int $label D-1397: the label template a produced board is printed on when neither material nor group name one; 0 none
     * @param float $wage EUR per hour
     * @param float $setupHours hours per order
     */
    private function __construct(
        public readonly array $deadlines,
        public readonly int $label = 0,
        public readonly float $wage = self::DEFAULT_WAGE,
        public readonly float $setupHours = self::DEFAULT_SETUP,
    ) {
    }

    /** @param array<mixed> $stored the option as saved; empty gives the defaults */
    public static function fromArray(array $stored): self
    {
        return new self(
            isset($stored['deadlines']) && is_array($stored['deadlines']) ? self::clean($stored['deadlines']) : self::DEFAULT_DEADLINES,
            is_numeric($stored['label'] ?? null) ? max(0, (int) $stored['label']) : 0,
            is_numeric($stored['wage'] ?? null) && (float) $stored['wage'] >= 0 ? (float) $stored['wage'] : self::DEFAULT_WAGE,
            is_numeric($stored['setup'] ?? null) && (float) $stored['setup'] >= 0 ? (float) $stored['setup'] : self::DEFAULT_SETUP,
        );
    }

    /**
     * From the rows of the form: a channel left empty drops its row, days must be a whole number from 0.
     *
     * @param list<string> $channels
     * @param list<string> $days
     */
    public static function fromRows(array $channels, array $days, int $label = 0, string $wage = '', string $setup = ''): self
    {
        $out = [];

        foreach ($channels as $i => $channel) {
            $channel = trim($channel);
            $value   = trim((string) ($days[$i] ?? ''));

            if ($channel === '') {
                continue;
            }

            if (preg_match('/^\d+$/', $value) !== 1) {
                throw ManufacturingError::deadlineNotWhole($channel, $value);
            }

            $out[$channel] = (int) $value;
        }

        return new self($out, max(0, $label), self::amount('wage', $wage, self::DEFAULT_WAGE), self::amount('setup', $setup, self::DEFAULT_SETUP));
    }

    /** @return array{deadlines: array<string, int>, label: int, wage: float, setup: float} */
    public function toArray(): array
    {
        return ['deadlines' => $this->deadlines, 'label' => $this->label, 'wage' => $this->wage, 'setup' => $this->setupHours];
    }

    /** A decimal with comma or point, from 0; empty keeps the default. */
    private static function amount(string $name, string $value, float $default): float
    {
        $value = str_replace(',', '.', trim($value));

        if ($value === '') {
            return $default;
        }

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            throw ManufacturingError::notAnAmount($name, $value);
        }

        return (float) $value;
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, int>
     */
    private static function clean(array $raw): array
    {
        $out = [];

        foreach ($raw as $channel => $days) {
            if (is_string($channel) && trim($channel) !== '' && is_numeric($days) && (int) $days >= 0) {
                $out[trim($channel)] = (int) $days;
            }
        }

        return $out;
    }
}
