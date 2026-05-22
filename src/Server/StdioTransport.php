<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Server;

use React\EventLoop\Loop;
use React\Stream\ReadableResourceStream;
use React\Stream\WritableResourceStream;

/**
 * Line-delimited JSON-RPC transport over stdio.
 *
 * Sole responsibility: read newline-terminated JSON objects from stdin,
 * hand them to the orchestrator, and write the orchestrator's responses
 * to stdout. Knows nothing about MCP semantics.
 *
 * Enforces the configured maximum line length to defeat
 * memory-exhaustion attacks where a hostile client sends an unbounded
 * payload without a newline.
 */
final class StdioTransport
{
    private int $maxLineBytes;
    /** @var callable */
    private $handler;

    public function __construct(int $maxLineBytes, callable $handler)
    {
        $this->maxLineBytes = $maxLineBytes;
        $this->handler      = $handler;
    }

    public function run(): void
    {
        $loop   = Loop::get();
        $stdin  = new ReadableResourceStream(STDIN, $loop);
        $stdout = new WritableResourceStream(STDOUT, $loop);

        $buffer = '';
        $stdin->on('data', function (string $chunk) use (&$buffer, $stdout) {
            $buffer .= $chunk;
            if (strlen($buffer) > $this->maxLineBytes * 8) {
                // Hard cap on the accumulator so a malicious client cannot
                // grow it without bound by withholding a newline.
                $buffer = '';
                $stdout->write(json_encode([
                    'jsonrpc' => '2.0',
                    'id'      => null,
                    'error'   => ['code' => JsonRpc::PARSE_ERROR, 'message' => 'Payload too large'],
                ]) . "\n");
                return;
            }

            while (($nlPos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $nlPos);
                $buffer = substr($buffer, $nlPos + 1);
                $line   = trim($line);
                if ($line === '') {
                    continue;
                }
                if (strlen($line) > $this->maxLineBytes) {
                    $stdout->write(json_encode([
                        'jsonrpc' => '2.0',
                        'id'      => null,
                        'error'   => ['code' => JsonRpc::PARSE_ERROR, 'message' => 'Line exceeds maximum payload size'],
                    ]) . "\n");
                    continue;
                }
                $response = ($this->handler)($line);
                if ($response !== null) {
                    $stdout->write((string)$response . "\n");
                }
            }
        });

        $stdin->on('close', static function () use ($loop) {
            $loop->stop();
        });

        $loop->run();
    }
}
