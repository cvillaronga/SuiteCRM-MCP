<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Output;

use SuiteCRM\MCP\Audit\AuditLogger;

/**
 * Outbound payload filter (NSA specs 4.1, 4.2, 4.3, 4.4).
 *
 * Every value flowing from SuiteCRM (or any tool) back to the MCP client
 * — and by extension to a downstream LLM — is fed through this filter.
 *
 * Stages:
 *  1. DLP: structural redaction of high-confidence sensitive patterns.
 *  2. Prompt-injection scan: heuristic detection of obvious attempts to
 *     override or pivot the consuming LLM.
 *  3. Truncation: enforce a hard size cap. Truncation is announced in
 *     the returned payload metadata so downstream callers can detect
 *     it instead of silently consuming partial JSON.
 *
 * Action policy:
 *  - DLP hits: value is rewritten; the output is allowed to continue.
 *    Audit event `output.redacted` records the rule name.
 *  - Injection hits: the offending string is wrapped in a sentinel
 *    (`[mcp:annotated-suspicious]`) and an audit event
 *    `output.injection_detected` is emitted. We do not silently drop
 *    the value — that would help an attacker fingerprint the filter —
 *    but we mark it clearly for the LLM consumer's own safety
 *    middleware to act on. `output.dropped` is emitted only if the
 *    final serialised size exceeds the cap and we truncate.
 */
final class OutputFilter
{
    private PromptInjectionScanner $scanner;
    private ContentPolicy $policy;
    private AuditLogger $audit;

    public function __construct(PromptInjectionScanner $scanner, ContentPolicy $policy, AuditLogger $audit)
    {
        $this->scanner = $scanner;
        $this->policy  = $policy;
        $this->audit   = $audit;
    }

    /**
     * Apply the pipeline to an arbitrary payload.
     *
     * @param mixed $payload
     * @return array{result:mixed, truncated:bool, dlp_hits:array<int,string>, injection_hits:array<int,string>}
     */
    public function filter($payload, string $correlationId, string $tool): array
    {
        $dlpHits       = [];
        $injectionHits = [];

        $walked   = $this->walk($payload, $dlpHits, $injectionHits);
        $encoded  = json_encode($walked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $size     = $encoded === false ? 0 : strlen($encoded);
        $truncated = false;

        if ($encoded !== false && $size > $this->policy->maxBytes()) {
            $truncated = true;
            $walked    = [
                '__truncated' => true,
                'preview'     => substr($encoded, 0, $this->policy->maxBytes() - 256) . '…',
                'original_bytes' => $size,
            ];
            $this->audit->event('output.dropped', [
                'tool'           => $tool,
                'correlation_id' => $correlationId,
                'reason'         => 'oversize',
                'original_bytes' => $size,
                'max_bytes'      => $this->policy->maxBytes(),
            ]);
        }

        if ($dlpHits !== []) {
            $this->audit->event('output.redacted', [
                'tool'           => $tool,
                'correlation_id' => $correlationId,
                'rules'          => array_values(array_unique($dlpHits)),
            ]);
        }
        if ($injectionHits !== []) {
            $this->audit->event('output.injection_detected', [
                'tool'           => $tool,
                'correlation_id' => $correlationId,
                'rules'          => array_values(array_unique($injectionHits)),
            ]);
        }

        return [
            'result'         => $walked,
            'truncated'      => $truncated,
            'dlp_hits'       => array_values(array_unique($dlpHits)),
            'injection_hits' => array_values(array_unique($injectionHits)),
        ];
    }

    /**
     * @param mixed              $value
     * @param array<int,string>  $dlpHits
     * @param array<int,string>  $injectionHits
     * @return mixed
     */
    private function walk($value, array &$dlpHits, array &$injectionHits)
    {
        if (is_string($value)) {
            [$cleaned, $hits] = $this->policy->applyDlp($value);
            foreach ($hits as $h) {
                $dlpHits[] = $h;
            }
            $scan = $this->scanner->scan($cleaned);
            if ($scan !== []) {
                foreach ($scan as $hit) {
                    $injectionHits[] = $hit['name'];
                }
                return '[mcp:annotated-suspicious] ' . $cleaned;
            }
            return $cleaned;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->walk($v, $dlpHits, $injectionHits);
            }
            return $out;
        }
        return $value;
    }
}
