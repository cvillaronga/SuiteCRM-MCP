<?php
declare(strict_types=1);

namespace SuiteCRM\MCP\Tools;

use SuiteCRM\MCP\Audit\CorrelationContext;
use SuiteCRM\MCP\Auth\OAuthClient;
use SuiteCRM\MCP\Http\SuiteCrmClient;
use SuiteCRM\MCP\Validation\ProvenanceTracker;

/**
 * Per-invocation bag passed to every tool. Decouples tools from the
 * top-level server object and makes the dependencies they may use
 * explicit — there is no service locator and no global state.
 */
final class ToolContext
{
    public SuiteCrmClient $http;
    public OAuthClient $auth;
    public CorrelationContext $correlation;
    public ProvenanceTracker $provenance;

    public function __construct(
        SuiteCrmClient $http,
        OAuthClient $auth,
        CorrelationContext $correlation,
        ProvenanceTracker $provenance
    ) {
        $this->http        = $http;
        $this->auth        = $auth;
        $this->correlation = $correlation;
        $this->provenance  = $provenance;
    }
}
