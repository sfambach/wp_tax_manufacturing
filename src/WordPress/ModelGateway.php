<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress;

use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\Table;

/**
 * The module's only door into the model: the modeller's abilities `taxmod/read-node` (one call per node, CD-7) and
 * `taxmod/write-data` (D-936: records only, one change number, all or nothing).
 *
 * ⚠️ One exception, as in the stock module: {@see records()} reads a few records of a node too large to read whole (the
 * parts of a parts list), through the modeller's record repository.
 */
final class ModelGateway
{
    /** @var array<int, Table> node id → its records, read once per request */
    private array $read = [];

    public function table(int $node): Table
    {
        if (isset($this->read[$node])) {
            return $this->read[$node];
        }

        $records = [];

        foreach ($this->execute('taxmod/read-node', ['node' => $node])['records'] ?? [] as $record) {
            $values = [];

            foreach ($record['values'] ?? [] as $value) {
                $values[(int) $value['relation_id']][] = (string) $value['value'];
            }

            $records[(int) $record['id']] = $values;
        }

        return $this->read[$node] = new Table($records);
    }

    /**
     * Only these records with their values, in one query.
     *
     * @param list<int> $records
     */
    public function records(array $records): Table
    {
        $repository = \Taxmod\WordPress\Persistence\WpdbRecordRepository::class;

        if ($records === [] || ! class_exists($repository) || ! method_exists($repository, 'valuesOfMany')) {
            return new Table([]);
        }

        $out = [];

        foreach ((new $repository())->valuesOfMany($records) as $id => $values) {
            $out[(int) $id] = [];

            foreach ($values as $value) {
                $out[(int) $id][(int) $value->relationId][] = (string) $value->value->rawValue();
            }
        }

        return new Table($out);
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, mixed> the modeller's answer, with the new ids under `refs`
     */
    public function write(array $changes): array
    {
        $this->read = [];

        return $this->execute('taxmod/write-data', ['changes' => $changes]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function execute(string $name, array $input): array
    {
        $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;

        if ($ability === null) {
            throw ManufacturingError::fromModel($name . ' is not available — is the Taxonomy Modeller active?');
        }

        $result = $ability->execute($input);

        if (is_wp_error($result)) {
            throw ManufacturingError::fromModel($result->get_error_message());
        }

        return is_array($result) ? $result : [];
    }
}
