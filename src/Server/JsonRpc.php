<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Server;

/**
 * Tiny JSON-RPC 2.0 helpers. Centralising the wire format here keeps the
 * transport and orchestrator decoupled.
 */
final class JsonRpc
{
    public const PARSE_ERROR     = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND= -32601;
    public const INVALID_PARAMS  = -32602;
    public const INTERNAL_ERROR  = -32603;
    public const AUTH_DENIED     = -32001;
    public const RATE_LIMITED    = -32002;
    public const VALIDATION      = -32003;
    public const ZONE_DENIED     = -32004;

    /** @return array<string,mixed> */
    public static function success($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<string,mixed>|null $data
     * @return array<string,mixed>
     */
    public static function error($id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}
