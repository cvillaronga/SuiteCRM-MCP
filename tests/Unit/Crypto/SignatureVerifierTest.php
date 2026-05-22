<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tests\Unit\Crypto;

use PHPUnit\Framework\TestCase;
use SuiteCRM\MCP\Audit\AuditLogger;
use SuiteCRM\MCP\Crypto\SignatureVerifier;
use SuiteCRM\MCP\Replay\NonceStore;

final class SignatureVerifierTest extends TestCase
{
    public function testValidSignaturePasses(): void
    {
        $verifier = new SignatureVerifier('shared-secret-shared-secret-32b', $this->audit());
        $envelope = $this->signedEnvelope('tools/list', null, 'shared-secret-shared-secret-32b');
        $this->assertTrue($verifier->verify($envelope, 300));
    }

    public function testMutatedBodyRejected(): void
    {
        $verifier = new SignatureVerifier('shared-secret-shared-secret-32b', $this->audit());
        $envelope = $this->signedEnvelope('tools/list', null, 'shared-secret-shared-secret-32b');
        $envelope['method'] = 'tools/call';
        $this->assertFalse($verifier->verify($envelope, 300));
    }

    public function testExpiredTimestampRejected(): void
    {
        $verifier = new SignatureVerifier('shared-secret-shared-secret-32b', $this->audit());
        $envelope = $this->signedEnvelope('tools/list', null, 'shared-secret-shared-secret-32b', time() - 10000);
        $this->assertFalse($verifier->verify($envelope, 300));
    }

    public function testNonceReplayDetected(): void
    {
        $store = new NonceStore($this->audit());
        $this->assertTrue($store->consume('a-nonce-1234', time(), 300, 'corr'));
        $this->assertFalse($store->consume('a-nonce-1234', time(), 300, 'corr'));
    }

    public function testWeakNonceRejected(): void
    {
        $store = new NonceStore($this->audit());
        $this->assertFalse($store->consume('short', time(), 300, 'corr'));
    }

    /**
     * @return array<string,mixed>
     */
    private function signedEnvelope(string $method, $id, string $secret, ?int $ts = null): array
    {
        $envelope = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => []];
        $ts       = $ts ?? time();
        $nonce    = bin2hex(random_bytes(8));
        $body     = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $material = $ts . '.' . $nonce . '.' . $method . '.' . $body;
        $envelope['mcp_sig'] = [
            'alg'   => 'HMAC-SHA256',
            'ts'    => $ts,
            'nonce' => $nonce,
            'sig'   => hash_hmac('sha256', $material, $secret),
        ];
        return $envelope;
    }

    private function audit(): AuditLogger
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mcp_audit_');
        return new AuditLogger($tmp);
    }
}
