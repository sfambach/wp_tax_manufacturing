<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * D-1460: the «Systeme» a first completion writes into the new hardware record — what he confirmed, out of what the model offers
 * anywhere below «Computer-Systeme» (XT and AT lie under PC). Nothing is assumed: an empty plan proposes nothing.
 *
 * @see docs/NewConcept/90-decision-log.md D-1460
 */
final class Systems
{
    /**
     * @param list<int>          $values  the plan's values (proposal) or what was ticked (to write)
     * @param array<int, string> $offered every value below the choice node, at any depth
     * @return list<int> the values that are offered, each once, in the given order
     */
    public static function kept(array $values, array $offered): array
    {
        return array_values(array_unique(array_filter($values, static fn (int $id): bool => isset($offered[$id]))));
    }
}
