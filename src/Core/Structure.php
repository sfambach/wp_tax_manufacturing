<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * Where the manufacturing records live in the model: node, field and value ids, read from one option.
 *
 * The module never names a node by its label (code standard: no special-casing by name); every id it reads or writes
 * comes from here. `structure-live.json` holds the ids of fambach.net.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/fertigung/konzept.md §2
 */
final class Structure
{
    /** The model the module stands on: projects, their boards, the boards' revisions and the revisions' positions (D-1139). */
    public const BASE_NODES = ['project', 'board', 'board_revision', 'bom_position'];

    public const BASE_FIELDS = ['label', 'project_boards', 'board_revisions', 'revision_positions', 'position_part', 'position_count'];

    /** D-1142: the build order and its assemblies, one per board revision. */
    public const OWN_NODES = ['order', 'assembly', 'fitting'];

    public const OWN_FIELDS = [
        'order_project', 'order_count', 'order_status', 'order_assemblies',
        'assembly_revision', 'assembly_status', 'assembly_unused',
        // D-1146: on how many boards a position is fitted, as parts of the assembly.
        'assembly_fitted', 'fitting_position', 'fitting_boards',
    ];

    /** The steps of an order and of an assembly, in their order of the process. */
    public const ORDER_STEPS = ['order_planned', 'order_building', 'order_done', 'order_cancelled'];

    public const ASSEMBLY_STEPS = ['build_open', 'build_parts', 'build_assembled', 'build_tested', 'build_done'];

    // D-1143: the assembled board as a material of its own, one record per revision.
    private const OPTIONAL_NODES = ['location', 'assembled', 'lager'];

    private const OPTIONAL_FIELDS = ['order_box', 'order_started', 'order_finished', 'order_note', 'assembly_note', 'assembly_tested', 'assembly_done',
        // D-954: the column «Best.» of a parts list and the reference designators; D-1030: the project a box belongs to.
        'position_fitting', 'position_ref', 'location_project', 'assembled_revision',
        // D-1178: the pins a socket strip loses at each cut, on the material's stock record.
        'lager_thing', 'lager_cut_loss'];

    /** D-954: the «Best.» values that matter here — «optional» may be left out, «nein» is never needed. */
    private const OPTIONAL_VALUES = ['fitting_optional', 'fitting_no'];

    /**
     * @param array<string, int> $nodes
     * @param array<string, int> $fields
     * @param array<string, int> $values
     */
    private function __construct(private readonly array $nodes, private readonly array $fields, private readonly array $values)
    {
    }

    /** @param array<string, mixed> $raw the decoded JSON or the stored option */
    public static function fromArray(array $raw): self
    {
        return new self(
            self::ids($raw, 'nodes', [...self::BASE_NODES, ...self::OWN_NODES, ...self::OPTIONAL_NODES]),
            self::ids($raw, 'fields', [...self::BASE_FIELDS, ...self::OWN_FIELDS, ...self::OPTIONAL_FIELDS]),
            self::ids($raw, 'values', [...self::ORDER_STEPS, ...self::ASSEMBLY_STEPS, ...self::OPTIONAL_VALUES]),
        );
    }

    /**
     * The stored ids, completed by the bundled ones where both agree on every id they share — so a release that adds ids
     * needs no new paste, and a structure of another site stays as it is (same rule as the stock module).
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $bundled
     * @return array<string, mixed>
     */
    public static function completed(array $stored, array $bundled): array
    {
        foreach (['nodes', 'fields', 'values'] as $group) {
            foreach (is_array($stored[$group] ?? null) ? $stored[$group] : [] as $key => $id) {
                if (isset($bundled[$group][$key]) && (int) $bundled[$group][$key] !== (int) $id) {
                    return $stored;
                }
            }
        }

        foreach (['nodes', 'fields', 'values'] as $group) {
            $stored[$group] = (is_array($stored[$group] ?? null) ? $stored[$group] : []) + (is_array($bundled[$group] ?? null) ? $bundled[$group] : []);
        }

        return $stored;
    }

    /** @return list<string> the keys still missing before orders can be kept, e.g. «nodes.order» */
    public function missing(): array
    {
        $out = [];

        foreach ([...self::BASE_NODES, ...self::OWN_NODES] as $key) {
            isset($this->nodes[$key]) || $out[] = 'nodes.' . $key;
        }

        foreach ([...self::BASE_FIELDS, ...self::OWN_FIELDS] as $key) {
            isset($this->fields[$key]) || $out[] = 'fields.' . $key;
        }

        foreach ([...self::ORDER_STEPS, ...self::ASSEMBLY_STEPS] as $key) {
            isset($this->values[$key]) || $out[] = 'values.' . $key;
        }

        return $out;
    }

    public function node(string $key): int
    {
        return $this->nodes[$key] ?? throw ManufacturingError::notConfigured('nodes.' . $key);
    }

    public function field(string $key): int
    {
        return $this->fields[$key] ?? throw ManufacturingError::notConfigured('fields.' . $key);
    }

    public function value(string $key): int
    {
        return $this->values[$key] ?? throw ManufacturingError::notConfigured('values.' . $key);
    }

    public function hasNode(string $key): bool
    {
        return isset($this->nodes[$key]);
    }

    public function hasField(string $key): bool
    {
        return isset($this->fields[$key]);
    }

    public function hasValue(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /**
     * The step key a stored value id stands for, or null when it is none of these.
     *
     * @param list<string> $steps {@see ORDER_STEPS} or {@see ASSEMBLY_STEPS}
     */
    public function stepOf(?string $valueId, array $steps): ?string
    {
        foreach ($steps as $key) {
            if ($valueId !== null && isset($this->values[$key]) && (string) $this->values[$key] === $valueId) {
                return $key;
            }
        }

        return null;
    }

    /** @return array{nodes: array<string, int>, fields: array<string, int>, values: array<string, int>} */
    public function toArray(): array
    {
        return ['nodes' => $this->nodes, 'fields' => $this->fields, 'values' => $this->values];
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<string>         $keys
     * @return array<string, int>
     */
    private static function ids(array $raw, string $group, array $keys): array
    {
        $given = is_array($raw[$group] ?? null) ? $raw[$group] : [];
        $out   = [];

        foreach ($keys as $key) {
            $id = (int) ($given[$key] ?? 0);

            if ($id > 0) {
                $out[$key] = $id;
            }
        }

        return $out;
    }
}
