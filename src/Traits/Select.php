<?php

namespace UQL\Traits;

use UQL\Exceptions\InvalidArgumentException;
use UQL\Functions;
use UQL\Helpers\ArrayHelper;
use UQL\Query\Query;

/**
 * @phpstan-type SelectedField array{originField: string, alias: bool, function: ?Functions\BaseFunction}
 * @phpstan-type SelectedFields array<string, SelectedField>
 */
trait Select
{
    /** @var SelectedFields $selectedFields */
    private array $selectedFields = [];

    public function selectAll(): Query
    {
        $this->select(Query::SELECT_ALL);
        return $this;
    }

    public function select(string $fields): Query
    {
        $fields = array_map('trim', explode(',', $fields));
        foreach ($fields as $field) {
            if ($field === Query::SELECT_ALL) {
                $this->selectedFields = [];
                continue;
            }

            if (isset($this->selectedFields[$field])) {
                throw new \InvalidArgumentException(sprintf('Field "%s" already defined', $field));
            }

            $this->addField($field);
        }

        return $this;
    }

    public function as(string $alias): Query
    {
        if ($alias === '') {
            throw new InvalidArgumentException('Alias cannot be empty');
        }

        $select = array_key_last($this->selectedFields);
        if ($select === null) {
            throw new InvalidArgumentException('Cannot use alias without a field');
        } elseif ($this->selectedFields[$select]['alias']) {
            throw new InvalidArgumentException('Cannot use alias repeatedly');
        } elseif (isset($this->selectedFields[$alias])) {
            throw new InvalidArgumentException(sprintf('Alias "%s" already defined', $alias));
        }

        $function = $this->selectedFields[$select]['function'] ?? null;
        unset($this->selectedFields[$select]);

        $this->addField($select, $alias, $function);
        return $this;
    }

    public function concat(string ...$fields): Query
    {
        return $this->addFieldFunction(new Functions\Concat(...$fields));
    }

    public function concatWithSeparator(string $separator, string ...$fields): Query
    {
        return $this->addFieldFunction(new Functions\ConcatWS($separator, ...$fields));
    }

    public function coalesce(string ...$fields): Query
    {
        return $this->addFieldFunction(new Functions\Coalesce(...$fields));
    }

    public function coalesceNotEmpty(string ...$fields): Query
    {
        return $this->addFieldFunction(new Functions\CoalesceNotEmpty(...$fields));
    }

    public function explode(string $field, string $separator = ','): Query
    {
        return $this->addFieldFunction(new Functions\Explode($field, $separator));
    }

    public function split(string $field, string $separator = ','): Query
    {
        return $this->explode($field, $separator);
    }

    public function implode(string $field, string $separator = ','): Query
    {
        return $this->addFieldFunction(new Functions\Implode($field, $separator));
    }

    public function glue(string $field, string $separator = ','): Query
    {
        return $this->implode($field, $separator);
    }

    public function sha1(string $field): Query
    {
        return $this->addFieldFunction(new Functions\Sha1($field));
    }

    public function md5(string $field): Query
    {
        return $this->addFieldFunction(new Functions\Md5($field));
    }

    public function lower(string $field): Query
    {
        return $this->addFieldFunction(new Functions\Lower($field));
    }

    public function upper(string $field): Query
    {
        return $this->addFieldFunction(new Functions\Upper($field));
    }

    public function round(string $field, int $precision = 0): Query
    {
        return $this->addFieldFunction(new Functions\Round($field, $precision));
    }

    public function length(string $field): Query
    {
        return $this->addFieldFunction(new Functions\Length($field));
    }

    public function reverse(string $field): Query
    {
        return $this->addFieldFunction(new Functions\Reverse($field));
    }

    public function ceil(string $field): Query
    {
        return $this->addFieldFunction(new Functions\Ceil($field));
    }

    public function floor(string $field): Query
    {
        return $this->addFieldFunction(new Functions\Floor($field));
    }

    public function modulo(string $field, int $divisor): Query
    {
        return $this->addFieldFunction(new Functions\Mod($field, $divisor));
    }

    private function addFieldFunction(Functions\BaseFunction $function): Query
    {
        $this->addField(
            (string) $function,
            function: $function
        );

        return $this;
    }

    private function addField(string $field, ?string $alias = null, ?Functions\BaseFunction $function = null): Query
    {
        $this->selectedFields[$alias ?? $field] = [
            'originField' => $field,
            'alias' => $alias !== null,
            'function' => $function,
        ];

        return $this;
    }

    /**
     * @param array<string|int, mixed> $item
     * @return array<string|int, mixed>
     */
    private function applySelect(array $item): array
    {
        if ($this->selectedFields === []) {
            return $item;
        }

        $result = [];
        foreach ($this->selectedFields as $finalField => $fieldData) {
            $fieldName = $finalField;
            if ($fieldData['function'] !== null) {
                $result[$fieldName] = $fieldData['function']($item, $result);
                continue;
            }

            $result[$fieldName] = ArrayHelper::getNestedValue(
                $item,
                $fieldData['alias'] ? $fieldData['originField'] : $finalField,
                false
            );
        }

        return $result;
    }

    /**
     * @return string[]
     */
    private function getAliasedFields(): array
    {
        $fields = [];
        foreach ($this->selectedFields as $finalField => $fieldData) {
            if ($fieldData['alias']) {
                $fields[] = $finalField;
            }
        }

        return $fields;
    }

    private function selectToString(): string
    {
        $return = Query::SELECT . ' ';
        if ($this->selectedFields === []) {
            return $return . Query::SELECT_ALL;
        }

        $count = count($this->selectedFields) - 1;
        $counter = 0;
        foreach ($this->selectedFields as $finalField => $fieldData) {
            $return .= "\n\t" . $fieldData['originField'];
            if ($fieldData['alias']) {
                $return .= ' ' . Query::AS . ' ' . $finalField;
            }

            if ($counter++ < $count) {
                $return .= ',';
            }
        }

        return trim($return);
    }
}
