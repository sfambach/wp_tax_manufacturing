<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * A revision's status, derived from its build orders when it is read — never kept (D-1463).
 *
 * ```mermaid
 * flowchart LR
 *   R["Revision"] --> Q{"erster Auftrag, nicht abgebrochen?"}
 *   Q -->|keiner| N["Neu"]
 *   Q -->|läuft| S["sein Status"]
 *   Q -->|Fertig| B["Bestätigt"]
 *   Q -->|Fehlgeschlagen| X["Nicht bestätigt"]
 * ```
 *
 * «Erster» is the one created first, which is the lowest id; only the first counts, however many follow (his words 18.11.
 * 20:35). The step of an assembly is its order's, unless it is marked «Fehlgeschlagen» (D-1464).
 *
 * @see docs/NewConcept/90-decision-log.md D-1463
 */
final class RevisionStatus
{
    public const NEW       = 'new';
    public const CONFIRMED = 'confirmed';
    public const REJECTED  = 'rejected';

    /**
     * @param list<BuildOrder> $orders with their steps as derived (D-1433)
     * @return string {@see NEW}, {@see CONFIRMED}, {@see REJECTED} or the step of the first order, e.g. «order_building»
     */
    public static function of(int $revision, array $orders): string
    {
        return self::ofEach([$revision], $orders)[$revision];
    }

    /**
     * @param list<int>        $revisions
     * @param list<BuildOrder> $orders
     * @return array<int, string> revision → status
     */
    public static function ofEach(array $revisions, array $orders): array
    {
        usort($orders, static fn (BuildOrder $a, BuildOrder $b): int => $a->id <=> $b->id);
        $out = array_fill_keys($revisions, self::NEW);
        $set = [];

        foreach ($orders as $order) {
            if ($order->step === 'order_cancelled') {
                continue;
            }

            foreach ($order->assemblies as $assembly) {
                if (! array_key_exists($assembly->revision, $out) || isset($set[$assembly->revision])) {
                    continue;
                }

                $set[$assembly->revision] = true;
                $out[$assembly->revision] = match (true) {
                    $assembly->failed || $order->step === 'order_failed' => self::REJECTED,
                    $order->step === 'order_done'                        => self::CONFIRMED,
                    default                                              => $order->step,
                };
            }
        }

        return $out;
    }
}
