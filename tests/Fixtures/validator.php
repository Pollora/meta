<?php

declare(strict_types=1);

// A rule checker for typed meta, without Laravel: the few rules the tests use, with Laravel's messages.

use Pollora\Meta\Domain\Contracts\MetaValidatorInterface;
use Pollora\Meta\Domain\Exceptions\MetaValidationException;
use Pollora\Meta\Domain\Models\MetaDefinition;

function metaValidator(): MetaValidatorInterface
{
    return new class implements MetaValidatorInterface
    {
        public function validate(MetaDefinition $definition, mixed $value): void
        {
            $name = $definition->label ?? str_replace('_', ' ', $definition->key);
            $value = $value instanceof BackedEnum ? $value->value : $value;
            $errors = [];

            foreach ($definition->rules as $rule) {
                if ($value === null) {
                    continue;
                }

                [$name2, $argument] = array_pad(explode(':', (string) $rule, 2), 2, null);

                $size = is_array($value) ? count($value) : (is_numeric($value) ? (float) $value : null);

                $error = match ($name2) {
                    'max' => $size !== null && $size > (float) $argument ? sprintf(is_array($value) ? 'The %s field must not have more than %s items.' : 'The %s field must not be greater than %s.', $name, $argument) : null,
                    'min' => $size !== null && $size < (float) $argument ? sprintf(is_array($value) ? 'The %s field must have at least %s items.' : 'The %s field must be at least %s.', $name, $argument) : null,
                    'in' => ! in_array((string) $value, explode(',', (string) $argument), true) ? sprintf('The selected %s is invalid.', $name) : null,
                    'email' => ! filter_var($value, FILTER_VALIDATE_EMAIL) ? sprintf('The %s field must be a valid email address.', $name) : null,
                    default => null,
                };

                if ($error !== null) {
                    $errors[] = $error;
                }
            }

            if ($errors !== []) {
                throw new MetaValidationException($definition, $errors);
            }
        }
    };
}
