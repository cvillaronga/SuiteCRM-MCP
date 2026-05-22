<?php
declare(strict_types=1);

/**
 * Thin executable entry point.
 *
 * All wiring lives in {@see \SuiteCRM\MCP\Bootstrap}; all dispatch in
 * {@see \SuiteCRM\MCP\Server\McpServer}; transport in
 * {@see \SuiteCRM\MCP\Server\StdioTransport}.
 *
 * This file deliberately contains no business logic so a security
 * reviewer can confirm the executable surface is just composition.
 */

require_once __DIR__ . '/vendor/autoload.php';

use SuiteCRM\MCP\Bootstrap;
use SuiteCRM\MCP\Server\StdioTransport;

try {
    $server = Bootstrap::build(__DIR__);
} catch (\Throwable $e) {
    fwrite(STDERR, 'SuiteCRM-MCP failed to start: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$maxLineBytes = (int)($_ENV['MCP_MAX_PAYLOAD_BYTES'] ?? 65536);
$transport    = new StdioTransport($maxLineBytes, [$server, 'handle']);
$transport->run();
