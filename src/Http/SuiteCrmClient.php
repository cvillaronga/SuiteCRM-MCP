<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Http;

/**
 * Hardened HTTP client for the SuiteCRM v8 REST API.
 *
 * The original implementation called `curl_setopt_array` without setting
 * `CURLOPT_SSL_VERIFYPEER`/`CURLOPT_SSL_VERIFYHOST` or pinning a minimum
 * TLS version, which left it vulnerable to silent downgrade and to MITM
 * if the runtime defaults were ever relaxed.
 *
 * This class:
 *  - forces certificate validation and full hostname verification;
 *  - pins minimum TLS to v1.2 (1.3 is preferred if compiled in);
 *  - enforces total timeout and connect timeout;
 *  - rejects responses larger than a configured cap to defeat
 *    response-bomb attacks targeting downstream LLM contexts;
 *  - exposes a single `request()` method so the rest of the codebase
 *    cannot bypass these settings;
 *  - never logs request bodies (they may contain credentials).
 *
 * Threat model:
 *  - SuiteCRM endpoint may be compromised. The client treats every
 *    response body as untrusted: callers must validate before forwarding
 *    it back to the MCP client (see {@see OutputFilter}).
 */
final class SuiteCrmClient
{
    private string $baseUrl;
    private int $timeout;
    private int $maxResponseBytes;

    public function __construct(string $baseUrl, int $timeoutSeconds = 15, int $maxResponseBytes = 4_194_304)
    {
        $this->baseUrl          = rtrim($baseUrl, '/');
        $this->timeout          = max(1, $timeoutSeconds);
        $this->maxResponseBytes = max(8 * 1024, $maxResponseBytes);
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $body
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    public function request(string $method, string $path, array $headers = [], ?array $body = null): array
    {
        $url     = $this->buildUrl($path);
        $bodyStr = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($body !== null && $bodyStr === false) {
            throw new HttpException('Failed to encode request body');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new HttpException('Failed to initialise curl handle');
        }

        $responseHeaders = [];
        $responseBytes   = 0;
        $bodyBuffer      = '';

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $this->mergeHeaders($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),

            // TLS hardening (NSA spec 9.3, advisor call-out).
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,

            // Header capture for forensic correlation.
            CURLOPT_HEADERFUNCTION => function ($ch, $headerLine) use (&$responseHeaders) {
                if (strpos($headerLine, ':') !== false) {
                    [$k, $v] = explode(':', $headerLine, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($headerLine);
            },
            // Body capture with a hard size cap. Returning 0 from a write
            // callback aborts the transfer (per curl docs); we surface that
            // as a typed exception below.
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$responseBytes, &$bodyBuffer) {
                $responseBytes += strlen($chunk);
                if ($responseBytes > $this->maxResponseBytes) {
                    return 0;
                }
                $bodyBuffer .= $chunk;
                return strlen($chunk);
            },
        ];

        if ($bodyStr !== null) {
            $options[CURLOPT_POSTFIELDS] = $bodyStr;
        }

        curl_setopt_array($ch, $options);

        $rc       = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($rc === false && $errno !== 0) {
            if ($responseBytes > $this->maxResponseBytes) {
                throw new HttpException("SuiteCRM response exceeded $this->maxResponseBytes bytes; aborted.");
            }
            throw new HttpException(sprintf('curl error %d: %s', $errno, $error));
        }

        return [
            'status'  => $httpCode,
            'body'    => $bodyBuffer,
            'headers' => $responseHeaders,
        ];
    }

    private function buildUrl(string $path): string
    {
        if (strpos($path, '/') !== 0) {
            $path = '/' . $path;
        }
        return $this->baseUrl . $path;
    }

    /**
     * @param array<string,string> $extra
     * @return array<int,string>
     */
    private function mergeHeaders(array $extra): array
    {
        $defaults = [
            'Accept'       => 'application/vnd.api+json',
            'Content-Type' => 'application/vnd.api+json',
            'User-Agent'   => 'SuiteCRM-MCP/2.0 (+hardened)',
        ];
        $headers = array_merge($defaults, $extra);
        $out     = [];
        foreach ($headers as $k => $v) {
            $out[] = $k . ': ' . $v;
        }
        return $out;
    }
}
