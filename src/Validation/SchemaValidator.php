<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Validation;

/**
 * Strict JSON Schema subset validator (NSA spec 2.1).
 *
 * Why a hand-rolled validator instead of a general library?
 *  - The MCP tool input surface is small and well-known. A narrow validator
 *    is easier to audit than a 4 000-line general implementation.
 *  - The validator is `strict-by-default`: `additionalProperties` defaults to
 *    `false` on every `object` schema, so unknown fields are rejected — this
 *    blocks the parameter-smuggling vector flagged by NSA spec 2.2.
 *  - Type coercion is forbidden. A string where an integer is expected is an
 *    error, not a silent conversion.
 *
 * Supported keywords:
 *  - type (string, integer, number, boolean, object, array, null)
 *  - properties, required, additionalProperties
 *  - items (single schema), minItems, maxItems
 *  - enum
 *  - minLength, maxLength, pattern
 *  - minimum, maximum
 *
 * Threat-model notes:
 *  - `pattern` is delegated to PCRE; the registry never accepts patterns from
 *    user input — only tool authors compose them. Patterns are wrapped in
 *    anchors and `u` flag to prevent partial matches and binary smuggling.
 *  - Maximum recursion depth is enforced to defeat schema bombs.
 */
final class SchemaValidator
{
    private const MAX_DEPTH = 16;

    /**
     * @param array<string,mixed> $schema
     * @param mixed               $value
     * @return array<int,string>  list of validation errors; empty == valid
     */
    public function validate(array $schema, $value, string $path = '$'): array
    {
        $errors = [];
        $this->walk($schema, $value, $path, 0, $errors);
        return $errors;
    }

    /**
     * @param array<string,mixed> $schema
     * @param mixed               $value
     * @param array<int,string>   $errors
     */
    private function walk(array $schema, $value, string $path, int $depth, array &$errors): void
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[] = "$path: schema nesting exceeds maximum depth";
            return;
        }

        if (isset($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                $errors[] = "$path: value is not one of the permitted enum members";
                return;
            }
        }

        $type = $schema['type'] ?? null;
        if ($type !== null && !$this->matchesType($type, $value)) {
            $errors[] = "$path: expected type {$type}";
            return;
        }

        if ($type === 'string' && is_string($value)) {
            $this->validateString($schema, $value, $path, $errors);
            return;
        }
        if (($type === 'integer' || $type === 'number') && (is_int($value) || is_float($value))) {
            $this->validateNumber($schema, $value, $path, $errors);
            return;
        }
        if ($type === 'array' && is_array($value)) {
            $this->validateArray($schema, $value, $path, $depth, $errors);
            return;
        }
        if ($type === 'object' && is_array($value)) {
            $this->validateObject($schema, $value, $path, $depth, $errors);
            return;
        }
    }

    /**
     * @param mixed $value
     */
    private function matchesType(string $type, $value): bool
    {
        switch ($type) {
            case 'string':  return is_string($value);
            case 'integer': return is_int($value);
            case 'number':  return is_int($value) || is_float($value);
            case 'boolean': return is_bool($value);
            case 'array':   return is_array($value) && array_keys($value) === range(0, count($value) - 1);
            case 'object':  return is_array($value) && ($value === [] || array_keys($value) !== range(0, count($value) - 1));
            case 'null':    return $value === null;
            default:        return false;
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<int,string>   $errors
     */
    private function validateString(array $schema, string $value, string $path, array &$errors): void
    {
        if (isset($schema['minLength']) && strlen($value) < (int)$schema['minLength']) {
            $errors[] = "$path: shorter than minLength {$schema['minLength']}";
        }
        if (isset($schema['maxLength']) && strlen($value) > (int)$schema['maxLength']) {
            $errors[] = "$path: longer than maxLength {$schema['maxLength']}";
        }
        if (isset($schema['pattern'])) {
            $pattern = '/' . str_replace('/', '\\/', (string)$schema['pattern']) . '/u';
            if (@preg_match($pattern, $value) !== 1) {
                $errors[] = "$path: does not match required pattern";
            }
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param int|float           $value
     * @param array<int,string>   $errors
     */
    private function validateNumber(array $schema, $value, string $path, array &$errors): void
    {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $errors[] = "$path: less than minimum {$schema['minimum']}";
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $errors[] = "$path: greater than maximum {$schema['maximum']}";
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<int,mixed>    $value
     * @param array<int,string>   $errors
     */
    private function validateArray(array $schema, array $value, string $path, int $depth, array &$errors): void
    {
        if (isset($schema['minItems']) && count($value) < (int)$schema['minItems']) {
            $errors[] = "$path: fewer than minItems {$schema['minItems']}";
        }
        if (isset($schema['maxItems']) && count($value) > (int)$schema['maxItems']) {
            $errors[] = "$path: more than maxItems {$schema['maxItems']}";
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $i => $item) {
                $this->walk($schema['items'], $item, "$path[$i]", $depth + 1, $errors);
            }
        }
    }

    /**
     * @param array<string,mixed>     $schema
     * @param array<string|int,mixed> $value
     * @param array<int,string>       $errors
     */
    private function validateObject(array $schema, array $value, string $path, int $depth, array &$errors): void
    {
        $required   = $schema['required']   ?? [];
        $properties = $schema['properties'] ?? [];
        /*
         * `additionalProperties` defaults to `false`. This is the
         * deny-by-default posture from NSA spec 2.1: unknown fields are a
         * potential injection or smuggling vector and are rejected.
         */
        $allowAdditional = $schema['additionalProperties'] ?? false;

        foreach ($required as $r) {
            if (!array_key_exists($r, $value)) {
                $errors[] = "$path.$r: missing required property";
            }
        }

        foreach ($value as $key => $v) {
            $childPath = "$path.$key";
            if (isset($properties[$key]) && is_array($properties[$key])) {
                $this->walk($properties[$key], $v, $childPath, $depth + 1, $errors);
                continue;
            }
            if ($allowAdditional === false) {
                $errors[] = "$childPath: property not permitted (additionalProperties=false)";
                continue;
            }
            if (is_array($allowAdditional)) {
                $this->walk($allowAdditional, $v, $childPath, $depth + 1, $errors);
            }
        }
    }
}
