<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * The findings offered when a board goes to «Nacharbeit»: the latest ones written, each once, newest first (Stefan 20.11.
 * 19:02 «die letzten 3 oder so»; Prüftor: the last five). Typing stays free — a one-off text drops out after five newer.
 *
 * @see docs/NewConcept/90-decision-log.md D-1433
 */
final class Findings
{
    /**
     * @param list<array{note: string, tested_on: string}> $rows the boards' notes with their test date
     * @return list<string>
     */
    public static function recent(array $rows, int $count = 5): array
    {
        // Newest first; a board without a date counts as oldest. The sort keeps the order of equal dates.
        usort($rows, static fn (array $a, array $b): int => strcmp($b['tested_on'], $a['tested_on']));
        $out = [];

        foreach ($rows as $row) {
            // A note may hold several lines (finding, then what was reworked); the last is the latest.
            $lines = array_values(array_filter(array_map('trim', explode("\n", $row['note'])), static fn (string $line): bool => $line !== ''));
            $text  = $lines === [] ? '' : $lines[count($lines) - 1];

            if ($text !== '' && ! in_array($text, $out, true)) {
                $out[] = $text;
            }

            if (count($out) >= $count) {
                break;
            }
        }

        return $out;
    }
}
