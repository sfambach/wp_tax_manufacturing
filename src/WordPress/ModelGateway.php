<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress;

use Taxmod\Core\Exception\ModelDoorRefused;
use Taxmod\WordPress\ModelDoor;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\Table;

/**
 * The module's side of the modeller's one door into the model (`Taxmod\WordPress\ModelDoor`, D-1186): it only wraps the
 * answers in the core's {@see Table} and words a refusal as {@see ManufacturingError}.
 */
final class ModelGateway
{
    private ModelDoor $door;

    /** @var array<int, Table> Review F2: node → its table, read once until the next write here or in the stock */
    private array $tables = [];
    private int $tablesAt = -1;

    public function __construct()
    {
        $this->door = new ModelDoor();
    }

    public function table(int $node): Table
    {
        $stamp = self::stockWrites();

        if ($stamp !== $this->tablesAt) {
            // The door remembers what it read; after a booking in the stock a fresh one reads again.
            $this->door     = $this->tablesAt === -1 ? $this->door : new ModelDoor();
            $this->tables   = [];
            $this->tablesAt = $stamp;
        }

        return $this->tables[$node] ??= new Table($this->ask(fn (): array => $this->door->records($node)));
    }

    /** @return array<int, string> child node id → name */
    public function children(int $node): array
    {
        return $this->ask(fn (): array => $this->door->children($node));
    }

    /** @param list<int> $records */
    public function records(array $records): Table
    {
        return new Table($records === [] ? [] : $this->ask(fn (): array => $this->door->valuesOf($records)));
    }

    /**
     * The records whose field `$relation` points at one of these records (D-1195).
     *
     * @param list<int> $records
     * @return list<int>
     */
    public function referrers(array $records, int $relation): array
    {
        return $records === [] ? [] : $this->ask(fn (): array => $this->door->referrers($records, $relation));
    }

    /**
     * @param list<int> $records
     * @return array<int, int> record → node
     */
    public function nodesOf(array $records): array
    {
        return $records === [] ? [] : $this->ask(fn (): array => $this->door->nodesOf($records));
    }

    /**
     * «Review nötig» on records made with only what could be derived (D-1255); a modeller before 0.2.44 has no such mark.
     *
     * @param list<int> $records
     */
    public function markForReview(array $records): void
    {
        if (method_exists($this->door, 'markForReview')) {
            $this->ask(function () use ($records): array {
                $this->door->markForReview($records);

                return [];
            });
        }
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed> the modeller's answer, with the new ids under `refs`
     */
    public function write(array $changes): array
    {
        $this->tables = [];

        return $this->ask(fn (): array => $this->door->write($changes));
    }

    /** The stock's write count: a booking there may change records this module reads (Review F1/F2). */
    private static function stockWrites(): int
    {
        $gateway = \TaxmodStock\WordPress\ModelGateway::class;

        return class_exists($gateway) && property_exists($gateway, 'writes') ? (int) $gateway::$writes : 0;
    }

    /**
     * @param callable(): array<mixed> $read
     * @return array<mixed>
     */
    private function ask(callable $read): array
    {
        try {
            return $read();
        } catch (ModelDoorRefused $refused) {
            throw ManufacturingError::fromModel($refused->getMessage());
        }
    }
}
