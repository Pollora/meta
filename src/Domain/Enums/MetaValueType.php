<?php

declare(strict_types=1);

namespace Pollora\Meta\Domain\Enums;

/**
 * The kinds of values a typed meta can hold, derived from the property type.
 */
enum MetaValueType
{
    case String;
    case Integer;
    case Number;
    case Boolean;
    case DateTime;
    case Enum;

    /** A PHP array of typed items: one row per item, or one serialized array */
    case ArrayOf;

    /** A class with public typed properties, stored as an array */
    case DataObject;
}
