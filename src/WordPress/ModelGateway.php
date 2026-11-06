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
    private readonly ModelDoor $door;

    public function __construct()
    {
        $this->door = new ModelDoor();
    }

    public function table(int $node): Table
    {
        return new Table($this->ask(fn (): array => $this->door->records($node)));
    }

    /** @param list<int> $records */
    public function records(array $records): Table
    {
        return new Table($this->door->valuesOf($records));
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed> the modeller's answer, with the new ids under `refs`
     */
    public function write(array $changes): array
    {
        return $this->ask(fn (): array => $this->door->write($changes));
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
