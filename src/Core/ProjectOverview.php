<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * Fertigung › «Projekte» (Stefan 26.11. «A»): one row per project, every project, with its boxes and the revisions of its plans.
 *
 * ```mermaid
 * flowchart LR
 *   P["Projekte"] --> R["eine Zeile je Projekt"]
 *   G["Projektgebinde"] -->|Projekt| R
 *   V["Revisionen der Baupläne"] -->|Projekt| R
 * ```
 *
 * A project without a box still gets its row; its boxes are then empty and the page shows «—».
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/fertigung/konzept.md
 */
final class ProjectOverview
{
    /**
     * @param array<int, string> $projects project → label, in the order shown
     * @param array<int, array{project: int, label: string, pieces: ?float}> $boxes place → box
     * @return list<array{project: int, label: string, boxes: array<int, string>, pieces: ?float}> pieces is null when no box tells its contents
     */
    public static function rows(array $projects, array $boxes): array
    {
        $byProject = [];

        foreach ($boxes as $place => $box) {
            $byProject[$box['project']][$place] = $box;
        }

        $rows = [];

        foreach ($projects as $project => $label) {
            $own    = $byProject[$project] ?? [];
            $known  = array_filter(array_column($own, 'pieces'), static fn (?float $n): bool => $n !== null);
            $rows[] = [
                'project' => $project,
                'label'   => $label,
                'boxes'   => array_map(static fn (array $box): string => $box['label'], $own),
                'pieces'  => $known === [] ? null : (float) array_sum($known),
            ];
        }

        return $rows;
    }

    /**
     * The box «+ Auftrag» fills in: only when the project has exactly one, else the order is created without one.
     *
     * @param array{boxes: array<int, string>} $row one of {@see rows()}
     */
    public static function onlyBox(array $row): int
    {
        return count($row['boxes']) === 1 ? (int) array_key_first($row['boxes']) : 0;
    }
}
