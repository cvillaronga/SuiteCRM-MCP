<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Output;

/**
 * Heuristic detector for prompt-injection content in outbound payloads
 * (NSA spec 4.2).
 *
 * The scanner inspects strings that flow from the SuiteCRM API back to
 * the MCP client and flags content that *looks like* an attempt to take
 * over the consuming LLM's instructions. It is intentionally
 * conservative: a SuiteCRM Notes field could legitimately contain the
 * phrase "Ignore previous instructions" if a sales rep is taking notes
 * about a customer conversation. We flag matches, redact in the audit
 * log, and let the {@see OutputFilter} apply the configured action
 * (annotate vs. drop vs. raise).
 *
 * Patterns target the common indirect-prompt-injection vocabulary:
 *  - Direct override phrases ("ignore prior", "disregard above");
 *  - Tool-pivot attempts (e.g., "call tool", "invoke tool");
 *  - Encoded-payload smuggling (base64 markers, fenced "system" blocks);
 *  - Hidden Unicode (handled in ParameterSanitizer for inputs; here we
 *    cross-check outputs because SuiteCRM data may itself contain them);
 *  - URL-coded JavaScript schemes and data: URIs targeting downstream
 *    renderers.
 *
 * The patterns deliberately avoid being too cute. Regex-based detection
 * cannot stop a determined attacker; it raises the cost of casual
 * injection and forces obvious attempts to be visible in the audit log.
 * Real defence requires the LLM consumer to treat MCP outputs as
 * untrusted — which is, again, the NSA's core thesis.
 */
final class PromptInjectionScanner
{
    /** @var array<int, array{name:string,pattern:string}> */
    private array $patterns;

    public function __construct()
    {
        $this->patterns = [
            ['name' => 'override_phrase',   'pattern' => '/\b(ignore|disregard|forget)\s+(?:(?:all|the|any|previous|prior|above)\s+){1,3}(instructions?|prompts?|rules?|context|guidelines?)\b/i'],
            ['name' => 'system_role_take',  'pattern' => '/(^|\n)\s*(system|assistant|developer)\s*:\s*/i'],
            ['name' => 'tool_pivot',        'pattern' => '/\b(invoke|call|execute|run)\s+(the\s+)?tool\b/i'],
            ['name' => 'fenced_system',     'pattern' => '/```\s*(system|assistant|developer)\b/i'],
            ['name' => 'jailbreak_phrase',  'pattern' => '/\b(do\s+anything\s+now|developer\s+mode|jailbreak)\b/i'],
            ['name' => 'data_uri',          'pattern' => '/\bdata:[a-z0-9+\\-]+\/[a-z0-9+\\-.]+;base64,/i'],
            ['name' => 'js_scheme',         'pattern' => '/\bjavascript:/i'],
            ['name' => 'shell_assignment',  'pattern' => '/\$\([^\)]*\)|`[^`]+`/'],
            ['name' => 'mcp_meta',          'pattern' => '/<\s*mcp[\s\/>]/i'],
            ['name' => 'zero_width',        'pattern' => '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u'],
        ];
    }

    /**
     * @return array<int,array{name:string,offset:int}>
     */
    public function scan(string $text): array
    {
        $hits = [];
        foreach ($this->patterns as $rule) {
            if (@preg_match($rule['pattern'], $text, $m, PREG_OFFSET_CAPTURE)) {
                $hits[] = [
                    'name'   => $rule['name'],
                    'offset' => isset($m[0][1]) ? (int)$m[0][1] : 0,
                ];
            }
        }
        return $hits;
    }
}
