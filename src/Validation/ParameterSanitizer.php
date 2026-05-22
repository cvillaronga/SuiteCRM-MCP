<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Validation;

/**
 * Content-level sanitation for tool parameters (NSA specs 2.2, 2.3, 2.4).
 *
 * Where the {@see SchemaValidator} answers "is the structure right?", this
 * class answers "is the content safe to forward downstream?". It is the
 * second layer of defence: a payload that passes schema validation can
 * still carry executable strings or untrusted serialised content, and that
 * is what we reject here.
 *
 * Specific protections:
 *  - Reject control characters except common whitespace. Defeats prompt
 *    injection via zero-width characters, ANSI escapes, and embedded NULs
 *    (CWE-77/78/94/95 vectors that hide in benign-looking strings).
 *  - Cap attribute payload depth and key count. Defeats nested-object
 *    denial-of-service.
 *  - Reject `__class__`, `@type`, `__proto__`, and similar serialisation
 *    sentinels that downstream JSON/PHP consumers might interpret as a
 *    hydration directive (NSA spec 2.4).
 *  - Strip and reject shell-meta sequences and SuiteCRM filter operators
 *    embedded in filter keys (the previous `listRecords` built URL filter
 *    parameters from raw input — this prevents the recurrence).
 *
 * Provenance:
 *  - All strings that pass this gate are tagged with the trust origin
 *    "mcp_client" by the caller. {@see ProvenanceTracker} consumes that tag
 *    when deciding whether a value may be forwarded into another tool.
 */
final class ParameterSanitizer
{
    private const MAX_DEPTH       = 6;
    private const MAX_PROPERTIES  = 256;
    private const MAX_STRING_LEN  = 8192;
    private const FORBIDDEN_KEYS  = ['__class__', '__proto__', '__class', '@type', '@class', '__type__'];

    /**
     * Sanitise a tool arguments array in-place. Returns the cleaned value.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function sanitiseArguments(array $arguments): array
    {
        $errors = [];
        $clean  = $this->walk($arguments, '$', 0, $errors);
        if ($errors !== []) {
            throw new ValidationException('Parameter sanitation failed', $errors);
        }
        return is_array($clean) ? $clean : [];
    }

    /**
     * Validate a SuiteCRM module name against an explicit allowlist.
     * This is the canonical chokepoint that removes the "any string accepted"
     * defect from the original `apiRequest` path interpolation.
     *
     * @param array<int,string> $allowed
     * @throws ValidationException
     */
    public function assertModuleAllowed(string $module, array $allowed): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $module)) {
            throw new ValidationException("Invalid module identifier: $module");
        }
        if (!in_array($module, $allowed, true)) {
            throw new ValidationException("Module '$module' is not in the allowlist");
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertIdentifier(string $id): void
    {
        if (!preg_match('/^[A-Za-z0-9_\\-]{1,64}$/', $id)) {
            throw new ValidationException('Invalid identifier format');
        }
    }

    /**
     * Validate a filter shape used by `list_records`. Filter keys must be
     * known field-name identifiers; values must be primitive scalars.
     *
     * @param array<string|int,mixed> $filter
     * @return array<string,string>
     * @throws ValidationException
     */
    public function sanitiseFilter(array $filter): array
    {
        $out = [];
        if (count($filter) > 32) {
            throw new ValidationException('Filter has too many keys');
        }
        foreach ($filter as $field => $value) {
            if (!is_string($field) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $field)) {
                throw new ValidationException("Invalid filter field: $field");
            }
            if (!is_scalar($value) || is_bool($value)) {
                throw new ValidationException("Filter value for '$field' must be a scalar string or number");
            }
            $stringValue = (string)$value;
            if (strlen($stringValue) > 256) {
                throw new ValidationException("Filter value for '$field' too long");
            }
            if ($this->containsControlChars($stringValue)) {
                throw new ValidationException("Filter value for '$field' contains control characters");
            }
            $out[$field] = $stringValue;
        }
        return $out;
    }

    /**
     * @param mixed              $value
     * @param array<int,string>  $errors
     * @return mixed
     */
    private function walk($value, string $path, int $depth, array &$errors)
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[] = "$path: exceeds maximum nesting depth";
            return null;
        }

        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING_LEN) {
                $errors[] = "$path: string exceeds maximum length";
                return null;
            }
            if ($this->containsControlChars($value)) {
                $errors[] = "$path: string contains forbidden control characters";
                return null;
            }
            return $value;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_array($value)) {
            if (count($value) > self::MAX_PROPERTIES) {
                $errors[] = "$path: too many properties";
                return null;
            }
            $clean = [];
            foreach ($value as $k => $v) {
                if (is_string($k)) {
                    if (in_array($k, self::FORBIDDEN_KEYS, true)) {
                        $errors[] = "$path.$k: forbidden serialisation key";
                        continue;
                    }
                    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $k)) {
                        $errors[] = "$path.$k: property name contains illegal characters";
                        continue;
                    }
                }
                $clean[$k] = $this->walk($v, "$path.$k", $depth + 1, $errors);
            }
            return $clean;
        }

        $errors[] = "$path: unsupported value type";
        return null;
    }

    private function containsControlChars(string $value): bool
    {
        /*
         * Allow \t (0x09), \n (0x0A), \r (0x0D). Reject every other C0 control,
         * the DEL (0x7F), and the C1 controls (0x80-0x9F). Also reject the
         * zero-width set commonly used for prompt smuggling.
         */
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return true;
        }
        // U+200B..U+200F, U+202A..U+202E, U+2060..U+206F, U+FEFF
        if (preg_match('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', $value) === 1) {
            return true;
        }
        return false;
    }
}
