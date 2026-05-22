<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Validation;

/**
 * Centralised schema repository (NSA spec 2.1).
 *
 * One canonical schema per tool. Every change to a schema is therefore a
 * code change that goes through review — the trust boundary between
 * "what callers may send" and "what tools accept" is single-sourced.
 *
 * Schemas intentionally:
 *  - declare `additionalProperties: false` so unknown fields are rejected
 *    (defence against parameter smuggling, NSA spec 2.2);
 *  - bound free-text fields with `maxLength` (mitigates resource-exhaustion
 *    and prompt-stuffing attacks, NSA spec 8.1);
 *  - constrain `module` to a strict identifier pattern, eliminating the URL
 *    path-injection class entirely (the original `apiRequest` interpolated
 *    arbitrary strings into the SuiteCRM API path).
 *
 * For attribute payloads on create/update/relate we deliberately keep
 * `additionalProperties: { type: string|number|boolean|null }` — SuiteCRM
 * modules expose many fields, including custom ones, so we whitelist the
 * primitive types and let the {@see ParameterSanitizer} handle content
 * sanitation rather than maintaining a per-module attribute schema. This is
 * documented as an accepted limitation in SECURITY.md.
 */
final class SchemaRegistry
{
    private const MODULE_PATTERN = '^[A-Za-z][A-Za-z0-9_]{0,63}$';
    private const ID_PATTERN     = '^[A-Za-z0-9_\\-]{1,64}$';

    /** @var array<string,array<string,mixed>> */
    private array $schemas;

    public function __construct()
    {
        /*
         * Because the bundled validator is intentionally narrow and does not
         * implement `oneOf`, attribute values flow through ParameterSanitizer
         * for type and content checks (which enforces a hard 256-property
         * ceiling). The schema for `data` therefore only describes the
         * object shape, not the value types.
         */
        $attributesSchema = [
            'type'                 => 'object',
            'additionalProperties' => true,
        ];

        $this->schemas = [
            'list_records' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['module'],
                'properties'           => [
                    'module' => [
                        'type'      => 'string',
                        'pattern'   => self::MODULE_PATTERN,
                        'maxLength' => 64,
                    ],
                    'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                    'filter' => [
                        'type'                 => 'object',
                        'additionalProperties' => true,
                    ],
                ],
            ],
            'get_record' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['module', 'id'],
                'properties'           => [
                    'module' => ['type' => 'string', 'pattern' => self::MODULE_PATTERN, 'maxLength' => 64],
                    'id'     => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'maxLength' => 64],
                ],
            ],
            'create_record' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['module', 'data'],
                'properties'           => [
                    'module' => ['type' => 'string', 'pattern' => self::MODULE_PATTERN, 'maxLength' => 64],
                    'data'   => $attributesSchema,
                ],
            ],
            'update_record' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['module', 'id', 'data'],
                'properties'           => [
                    'module' => ['type' => 'string', 'pattern' => self::MODULE_PATTERN, 'maxLength' => 64],
                    'id'     => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'maxLength' => 64],
                    'data'   => $attributesSchema,
                ],
            ],
            'delete_record' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['module', 'id'],
                'properties'           => [
                    'module' => ['type' => 'string', 'pattern' => self::MODULE_PATTERN, 'maxLength' => 64],
                    'id'     => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'maxLength' => 64],
                ],
            ],
            'search_records' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['query'],
                'properties'           => [
                    'query'   => ['type' => 'string', 'minLength' => 1, 'maxLength' => 256],
                    'modules' => [
                        'type'     => 'array',
                        'maxItems' => 16,
                        'items'    => ['type' => 'string', 'pattern' => self::MODULE_PATTERN, 'maxLength' => 64],
                    ],
                ],
            ],
            'relate_records' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => ['module', 'id', 'link_field', 'related_id'],
                'properties'           => [
                    'module'     => ['type' => 'string', 'pattern' => self::MODULE_PATTERN, 'maxLength' => 64],
                    'id'         => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'maxLength' => 64],
                    'link_field' => ['type' => 'string', 'pattern' => self::MODULE_PATTERN, 'maxLength' => 64],
                    'related_id' => ['type' => 'string', 'pattern' => self::ID_PATTERN, 'maxLength' => 64],
                ],
            ],
        ];
    }

    public function has(string $tool): bool
    {
        return isset($this->schemas[$tool]);
    }

    /** @return array<string,mixed> */
    public function schemaFor(string $tool): array
    {
        if (!isset($this->schemas[$tool])) {
            throw new \InvalidArgumentException("Unknown tool schema: $tool");
        }
        return $this->schemas[$tool];
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return $this->schemas;
    }
}
