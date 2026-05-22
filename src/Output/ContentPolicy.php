<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Output;

/**
 * Content-length and DLP-style policy for outbound payloads (NSA spec 4.3).
 *
 * Two responsibilities:
 *  - cap the total serialised output length so a malicious SuiteCRM
 *    response cannot blow up the MCP client's context window or memory;
 *  - apply a forbidden-keyword denylist for data that obviously must
 *    not leave SuiteCRM (e.g. raw credit-card numbers, SSNs). This is
 *    a safety net — proper DLP belongs at the source — but it
 *    guarantees the MCP layer enforces a basic rule.
 *
 * The patterns intentionally err on the side of false positives. When a
 * pattern matches, the value is replaced with `[redacted:<reason>]` and
 * an audit event is recorded by the filter that owns this policy.
 */
final class ContentPolicy
{
    private int $maxBytes;
    /** @var array<int,array{name:string,pattern:string,replacement:string}> */
    private array $rules;

    public function __construct(int $maxBytes = 524_288)
    {
        $this->maxBytes = $maxBytes;
        $this->rules    = [
            [
                'name'        => 'credit_card',
                'pattern'     => '/\b(?:\d[ \-]?){13,19}\b/',
                'replacement' => '[redacted:cc]',
            ],
            [
                'name'        => 'us_ssn',
                'pattern'     => '/\b\d{3}-\d{2}-\d{4}\b/',
                'replacement' => '[redacted:ssn]',
            ],
            [
                'name'        => 'aws_access_key',
                'pattern'     => '/\bAKIA[0-9A-Z]{16}\b/',
                'replacement' => '[redacted:aws_key]',
            ],
            [
                'name'        => 'private_key_header',
                'pattern'     => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |)PRIVATE KEY-----/',
                'replacement' => '[redacted:private_key]',
            ],
        ];
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Apply DLP rules to a string. Returns the (possibly redacted) string
     * and the list of rule names that fired.
     *
     * @return array{0:string, 1:array<int,string>}
     */
    public function applyDlp(string $value): array
    {
        $hits = [];
        foreach ($this->rules as $rule) {
            $newValue = preg_replace($rule['pattern'], $rule['replacement'], $value);
            if ($newValue !== null && $newValue !== $value) {
                $hits[] = $rule['name'];
                $value  = $newValue;
            }
        }
        return [$value, $hits];
    }
}
