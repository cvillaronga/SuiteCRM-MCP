<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tools;

use SuiteCRM\MCP\Http\HttpException;
use SuiteCRM\MCP\Validation\ValidationException;

abstract class AbstractTool
{
    abstract public function name(): string;
    abstract public function description(): string;

    /**
     * @param array<string,mixed> $arguments Already validated by SchemaValidator and ParameterSanitizer.
     * @return mixed                          Tool result (will go through OutputFilter before transit).
     */
    abstract public function execute(array $arguments, ToolContext $ctx);

    protected function authHeaders(ToolContext $ctx): array
    {
        return ['Authorization' => 'Bearer ' . $ctx->auth->token()];
    }

    /**
     * @return array<string,mixed>
     */
    protected function decodeJson(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new HttpException('SuiteCRM response was not a JSON object');
        }
        return $decoded;
    }

    /**
     * Map common HTTP status codes from SuiteCRM into typed errors that
     * the server can surface to the MCP client without leaking response
     * bodies (which may contain sensitive data).
     *
     * @throws HttpException|ValidationException|\SuiteCRM\MCP\Auth\AccessDeniedException
     */
    protected function assertOk(int $status, string $tool): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }
        if ($status === 401) {
            throw new HttpException("SuiteCRM rejected the bearer token for tool '$tool'");
        }
        if ($status === 403) {
            // SuiteCRM-side ACL has the final word; we surface that as denial.
            throw new \SuiteCRM\MCP\Auth\AccessDeniedException("SuiteCRM denied tool '$tool' (HTTP 403)");
        }
        if ($status === 404) {
            throw new HttpException("SuiteCRM resource not found for tool '$tool'");
        }
        if ($status === 422 || $status === 400) {
            throw new ValidationException("SuiteCRM rejected payload for tool '$tool' (HTTP $status)");
        }
        throw new HttpException("SuiteCRM request failed for tool '$tool' (HTTP $status)");
    }
}
