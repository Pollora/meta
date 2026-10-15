<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Services;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Exceptions\InvalidMetaValueException;
use Pollora\Meta\Domain\Models\MetaDefinition;
use ReflectionClass;
use Stringable;
use Throwable;

/**
 * Converts meta values between their PHP type and what WordPress stores.
 *
 * Reading is tolerant (a boolean accepts `1`, `true`, `yes`, `on`…), writing is
 * strict (the PHP value must match the property type). Dates are stored in
 * ISO 8601, in UTC. A scalar is stored as a string; an array as a list of
 * strings (one row each) or a serialized array of JSON values; a data object
 * as a serialized array, never as a PHP object.
 */
final class MetaValueCaster
{
    private const string DATE_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * The PHP value of a stored meta. An absent or empty value gives the default.
     *
     * @throws InvalidMetaValueException When the stored value cannot be read as the declared type
     */
    public function toPhp(MetaDefinition $definition, mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return $this->absentValue($definition);
        }

        return $this->read($definition, $raw) ?? throw InvalidMetaValueException::forRead($definition, $raw);
    }

    /**
     * What an absent meta reads as: the property default, or for a data object
     * without one, an instance with its own defaults.
     */
    public function absentValue(MetaDefinition $definition): mixed
    {
        if ($definition->valueType === MetaValueType::DataObject && $definition->default === null && ! $definition->nullable) {
            return $this->readObject($definition, []);
        }

        return $definition->default;
    }

    /**
     * The stored form of a value written from PHP. Null means the meta is deleted.
     *
     * A scalar gives a string; an array a list of strings (one row each) when the
     * meta is not single, otherwise a list of JSON values; a data object an array
     * of JSON values by property key.
     *
     * @return string|array<array-key, mixed>|null
     *
     * @throws InvalidMetaValueException When the value does not match the property type
     */
    public function toStorage(MetaDefinition $definition, mixed $value): string|array|null
    {
        if ($value === null) {
            return $definition->nullable ? null : throw InvalidMetaValueException::forWrite($definition, $value);
        }

        if ($definition->valueType === MetaValueType::ArrayOf && ! $definition->single) {
            return is_array($value)
                ? array_values(array_map(fn (mixed $item): string => (string) $this->toStorage($definition->item(), $item), $value))
                : throw InvalidMetaValueException::forWrite($definition, $value);
        }

        if ($definition->isStructured()) {
            return $this->toJson($definition, $value);
        }

        $stored = match ($definition->valueType) {
            MetaValueType::String => is_string($value) || $value instanceof Stringable ? (string) $value : null,
            MetaValueType::Integer => is_int($value) ? (string) $value : null,
            MetaValueType::Number => is_int($value) || is_float($value) ? (string) $value : null,
            MetaValueType::Boolean => is_bool($value) ? ($value ? '1' : '0') : null,
            MetaValueType::DateTime => $value instanceof DateTimeInterface ? $this->formatDate($value) : null,
            MetaValueType::Enum => $value instanceof BackedEnum && $value::class === $definition->valueClass ? (string) $value->value : null,
            MetaValueType::ArrayOf, MetaValueType::DataObject => null,
        };

        return $stored ?? throw InvalidMetaValueException::forWrite($definition, $value);
    }

    /**
     * A value as JSON can carry it, inside a serialized array and in REST: numbers
     * and booleans keep their type, dates and enums become strings or integers.
     *
     * @throws InvalidMetaValueException When the value does not match the declared type
     */
    public function toJson(MetaDefinition $definition, mixed $value): mixed
    {
        if ($value === null) {
            return $definition->nullable ? null : throw InvalidMetaValueException::forWrite($definition, $value);
        }

        $json = match ($definition->valueType) {
            MetaValueType::String => is_string($value) || $value instanceof Stringable ? (string) $value : null,
            MetaValueType::Integer => is_int($value) ? $value : null,
            MetaValueType::Number => is_int($value) || is_float($value) ? $value : null,
            MetaValueType::Boolean => is_bool($value) ? $value : null,
            MetaValueType::DateTime => $value instanceof DateTimeInterface ? $this->formatDate($value) : null,
            MetaValueType::Enum => $value instanceof BackedEnum && $value::class === $definition->valueClass ? $value->value : null,
            MetaValueType::ArrayOf => is_array($value) ? array_values(array_map(fn (mixed $item): mixed => $this->toJson($definition->item(), $item), $value)) : null,
            MetaValueType::DataObject => $this->objectToJson($definition, $value),
        };

        return $json ?? throw InvalidMetaValueException::forWrite($definition, $value);
    }

    /**
     * Normalizes any value written through WordPress (REST, editor, `update_post_meta()`)
     * to the stored form. Used as `sanitize_callback`: an unreadable value becomes
     * an empty string, which reads back as the default.
     */
    public function sanitize(MetaDefinition $definition, mixed $value): mixed
    {
        // Empty stays empty (an absent value), so sanitizing twice changes nothing.
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $phpValue = $value instanceof DateTimeInterface || $value instanceof BackedEnum || ($definition->valueType === MetaValueType::DataObject && is_object($value))
                ? $value
                : $this->toPhp($definition, $value);

            return $this->toStorage($definition, $phpValue) ?? '';
        } catch (InvalidMetaValueException) {
            return '';
        }
    }

    /**
     * The default as the REST API publishes it, or null when there is none.
     */
    public function toRestDefault(MetaDefinition $definition): mixed
    {
        $default = $this->absentValue($definition);

        return match (true) {
            $default === null => null,
            // A non-single meta has a default per row, which a list cannot give.
            $definition->valueType === MetaValueType::ArrayOf && ! $definition->single => null,
            $definition->isStructured() => $this->toJson($definition, $default),
            $default instanceof BackedEnum => $default->value,
            default => $default,
        };
    }

    /**
     * The value of a raw stored or JSON value, or null when it cannot be read.
     */
    private function read(MetaDefinition $definition, mixed $raw): mixed
    {
        return match ($definition->valueType) {
            MetaValueType::String => is_scalar($raw) ? (string) $raw : null,
            MetaValueType::Integer => $this->readInteger($raw),
            MetaValueType::Number => is_numeric($raw) ? (float) $raw : null,
            MetaValueType::Boolean => is_scalar($raw) ? filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
            MetaValueType::DateTime => $this->readDate($definition, $raw),
            MetaValueType::Enum => $this->readEnum($definition, $raw),
            MetaValueType::ArrayOf => $this->readArray($definition, $raw),
            MetaValueType::DataObject => is_array($raw) ? $this->readObject($definition, $raw) : null,
        };
    }

    /**
     * @return list<mixed>|null
     */
    private function readArray(MetaDefinition $definition, mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $items = [];

        foreach ($raw as $item) {
            $value = $this->read($definition->item(), $item);

            if ($value === null) {
                return null;
            }

            $items[] = $value;
        }

        return $items;
    }

    /**
     * An instance of the data object, its properties read from the array; a
     * missing property keeps its default.
     *
     * @param  array<array-key, mixed>  $raw
     */
    private function readObject(MetaDefinition $definition, array $raw): ?object
    {
        /** @var class-string $class */
        $class = $definition->valueClass;
        $object = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        foreach ($definition->properties as $property) {
            if (! array_key_exists($property->key, $raw)) {
                $object->{$property->property} = $property->default;

                continue;
            }

            $item = $raw[$property->key];

            if ($item === null) {
                if (! $property->nullable) {
                    return null;
                }

                $object->{$property->property} = null;

                continue;
            }

            $value = $this->read($property, $item);

            if ($value === null) {
                return null;
            }

            $object->{$property->property} = $value;
        }

        return $object;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function objectToJson(MetaDefinition $definition, mixed $value): ?array
    {
        if (! is_object($value) || ! is_a($value, (string) $definition->valueClass)) {
            return null;
        }

        $json = [];

        foreach ($definition->properties as $property) {
            $json[$property->key] = $this->toJson($property, $value->{$property->property} ?? null);
        }

        return $json;
    }

    private function readInteger(mixed $raw): ?int
    {
        $value = is_scalar($raw) ? filter_var($raw, FILTER_VALIDATE_INT) : false;

        return $value === false ? null : $value;
    }

    private function readDate(MetaDefinition $definition, mixed $raw): ?DateTimeInterface
    {
        if (! is_string($raw)) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($raw, 'UTC');
        } catch (Throwable) {
            return null;
        }

        $class = (string) $definition->valueClass;

        // A property typed with an interface (DateTimeInterface, CarbonInterface) gets a CarbonImmutable.
        return match (true) {
            is_a($class, DateTimeImmutable::class, true), is_a($class, DateTime::class, true) => $class::createFromInterface($date),
            default => $date,
        };
    }

    private function readEnum(MetaDefinition $definition, mixed $raw): ?BackedEnum
    {
        if (! is_scalar($raw)) {
            return null;
        }

        /** @var class-string<BackedEnum> $enum */
        $enum = $definition->valueClass;

        if ($definition->wordPressType() === 'integer') {
            $raw = $this->readInteger($raw);

            if ($raw === null) {
                return null;
            }
        }

        return $enum::tryFrom($raw);
    }

    private function formatDate(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }
}
