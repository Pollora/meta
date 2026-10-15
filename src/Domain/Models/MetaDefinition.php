<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Models;

use BackedEnum;
use LogicException;
use Pollora\Meta\Domain\Enums\Control;
use Pollora\Meta\Domain\Enums\MetaValueType;
use ReflectionEnum;

/**
 * One typed meta, as declared by a `#[Meta]` property.
 *
 * Immutable: built once by the schema builder from the property and its
 * attribute, then read by the WordPress registry and by `Meta::of()`.
 */
final readonly class MetaDefinition
{
    /**
     * @param  string  $property  Name of the declaring property
     * @param  string  $key  Key stored in the database
     * @param  MetaValueType  $valueType  Kind of value, derived from the property type
     * @param  class-string|null  $valueClass  Enum or date class, for those value types
     * @param  bool  $nullable  Whether the property accepts null, which deletes the meta
     * @param  mixed  $default  Value returned when the meta is absent
     * @param  bool  $showInRest  Exposes the meta in the REST API
     * @param  string|null  $label  Label shown by the editor
     * @param  string|null  $description  Description passed to `register_meta()`
     * @param  string|array{0: class-string|object, 1: string}|null  $sanitize  Callable replacing the derived sanitization
     * @param  string|null  $capability  Capability required to write through REST and the editor
     * @param  bool  $revisions  Versions the meta with post revisions
     * @param  array<int, mixed>  $rules  Laravel validation rules
     * @param  bool  $single  False for an array stored one row per item
     * @param  MetaDefinition|null  $items  The item of an array
     * @param  array<string, MetaDefinition>  $properties  The properties of a data object, by property name
     * @param  Control|null  $control  The input a UI driver should build; null when no neutral control fits (arrays, objects)
     * @param  string|null  $group  The group of fields a UI driver puts the meta in
     * @param  array<string, mixed>  $hints  Options for UI drivers, by driver
     * @param  bool  $media  The value is an attachment ID
     * @param  bool  $public  The value may be shown to anyone
     */
    public function __construct(
        public string $property,
        public string $key,
        public MetaValueType $valueType,
        public ?string $valueClass,
        public bool $nullable,
        public mixed $default,
        public bool $showInRest = false,
        public ?string $label = null,
        public ?string $description = null,
        public string|array|null $sanitize = null,
        public ?string $capability = null,
        public bool $revisions = false,
        public array $rules = [],
        public bool $single = true,
        public ?MetaDefinition $items = null,
        public array $properties = [],
        public ?Control $control = null,
        public ?string $group = null,
        public array $hints = [],
        public bool $media = false,
        public bool $public = false,
    ) {}

    /**
     * Whether WordPress treats the key as protected (hidden from custom fields and REST by default).
     */
    public function isProtected(): bool
    {
        return str_starts_with($this->key, '_');
    }

    /**
     * The `type` passed to `register_meta()`.
     */
    public function wordPressType(): string
    {
        return match ($this->valueType) {
            MetaValueType::String, MetaValueType::DateTime => 'string',
            MetaValueType::Integer => 'integer',
            MetaValueType::Number => 'number',
            MetaValueType::Boolean => 'boolean',
            MetaValueType::Enum => $this->isIntegerBackedEnum() ? 'integer' : 'string',
            // A non-single meta registers the type of one row: WordPress wraps it in an array.
            MetaValueType::ArrayOf => $this->single ? 'array' : $this->item()->wordPressType(),
            MetaValueType::DataObject => 'object',
        };
    }

    /**
     * The JSON schema published with the meta in the REST API.
     *
     * @return array<string, mixed>
     */
    public function restSchema(): array
    {
        if ($this->valueType === MetaValueType::ArrayOf) {
            return $this->single ? ['type' => 'array', 'items' => $this->item()->restSchema()] : $this->item()->restSchema();
        }

        if ($this->valueType === MetaValueType::DataObject) {
            $properties = [];

            foreach ($this->properties as $property) {
                $properties[$property->key] = $property->nullableRestSchema();
            }

            return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        }

        $schema = ['type' => $this->wordPressType()];

        if ($this->valueType === MetaValueType::DateTime) {
            $schema['format'] = 'date-time';
        }

        if ($this->valueType === MetaValueType::Enum) {
            /** @var class-string<BackedEnum> $enum */
            $enum = $this->valueClass;
            $schema['enum'] = array_map(static fn (BackedEnum $case): int|string => $case->value, $enum::cases());
        }

        return $schema;
    }

    /**
     * The item of an array.
     */
    public function item(): MetaDefinition
    {
        return $this->items ?? throw new LogicException(sprintf('The meta "%s" is not an array.', $this->key));
    }

    /**
     * Whether the value is an array or an object, stored serialized or in several rows.
     */
    public function isStructured(): bool
    {
        return $this->valueType === MetaValueType::ArrayOf || $this->valueType === MetaValueType::DataObject;
    }

    /**
     * The REST schema of a property inside an object, accepting null when it does.
     *
     * @return array<string, mixed>
     */
    private function nullableRestSchema(): array
    {
        $schema = $this->restSchema();

        if ($this->nullable && is_string($schema['type'])) {
            $schema['type'] = [$schema['type'], 'null'];
        }

        return $schema;
    }

    private function isIntegerBackedEnum(): bool
    {
        /** @var class-string<BackedEnum> $enum */
        $enum = $this->valueClass;

        return (new ReflectionEnum($enum))->getBackingType()?->getName() === 'int';
    }
}
