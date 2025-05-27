<?php
// suitecrm-mcp-server.php

require_once __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Stream\ReadableResourceStream;
use React\Stream\WritableResourceStream;

/**
 * Class SuiteCRMMCPServer
 */
class SuiteCRMMCPServer {
    private $suiteCrmUrl;
    private $clientId;
    private $clientSecret;
    private $username;
    private $password;
    private $accessToken;
    private $tokenExpiry;
    private $loop;
    private $stdin;
    private $stdout;

    public function __construct() {
        // Load configuration from environment variables
        $this->suiteCrmUrl = $_ENV['SUITECRM_URL'] ?? 'http://localhost/suitecrm';
        $this->clientId = $_ENV['SUITECRM_CLIENT_ID'] ?? '';
        $this->clientSecret = $_ENV['SUITECRM_CLIENT_SECRET'] ?? '';
        $this->username = $_ENV['SUITECRM_USERNAME'] ?? '';
        $this->password = $_ENV['SUITECRM_PASSWORD'] ?? '';

        $this->loop = Loop::get();
        $this->stdin = new ReadableResourceStream(STDIN, $this->loop);
        $this->stdout = new WritableResourceStream(STDOUT, $this->loop);
    }

    public function run() {
        $buffer = '';

        $this->stdin->on('data', function ($data) use (&$buffer) {
            $buffer .= $data;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                if (trim($line) === '') continue;

                $request = json_decode($line, true);
                if ($request) {
                    $this->handleRequest($request);
                }
            }
        });

        // Send initial server info
        $this->sendServerInfo();

        $this->loop->run();
    }

    private function sendServerInfo() {
        $this->sendResponse([
            'jsonrpc' => '2.0',
            'method' => 'server.info',
            'params' => [
                'name' => 'suitecrm-mcp-server',
                'version' => '1.0.0',
                'capabilities' => [
                    'tools' => true,
                    'resources' => false,
                    'prompts' => false
                ]
            ]
        ]);
    }

    private function handleRequest($request) {
        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;

        try {
            switch ($method) {
                case 'initialize':
                    $this->handleInitialize($id);
                    break;

                case 'tools/list':
                    $this->handleToolsList($id);
                    break;

                case 'tools/call':
                    $this->handleToolCall($id, $request['params'] ?? []);
                    break;

                default:
                    $this->sendError($id, -32601, 'Method not found');
            }
        } catch (Exception $e) {
            $this->sendError($id, -32603, $e->getMessage());
        }
    }

    private function handleInitialize($id) {
        $this->sendResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => true,
                    'resources' => false,
                    'prompts' => false
                ],
                'serverInfo' => [
                    'name' => 'suitecrm-mcp-server',
                    'version' => '1.0.0'
                ]
            ]
        ]);
    }

    private function handleToolsList($id) {
        $tools = [
            [
                'name' => 'list_records',
                'description' => 'List records from a SuiteCRM module',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'module' => [
                            'type' => 'string',
                            'description' => 'The module name (e.g., Accounts, Contacts, Leads)'
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of records to return',
                            'default' => 20
                        ],
                        'offset' => [
                            'type' => 'integer',
                            'description' => 'Number of records to skip',
                            'default' => 0
                        ],
                        'filter' => [
                            'type' => 'object',
                            'description' => 'Filter criteria',
                            'additionalProperties' => true
                        ]
                    ],
                    'required' => ['module']
                ]
            ],
            [
                'name' => 'get_record',
                'description' => 'Get a specific record by ID',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'module' => [
                            'type' => 'string',
                            'description' => 'The module name'
                        ],
                        'id' => [
                            'type' => 'string',
                            'description' => 'The record ID'
                        ]
                    ],
                    'required' => ['module', 'id']
                ]
            ],
            [
                'name' => 'create_record',
                'description' => 'Create a new record in SuiteCRM',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'module' => [
                            'type' => 'string',
                            'description' => 'The module name'
                        ],
                        'data' => [
                            'type' => 'object',
                            'description' => 'The record data',
                            'additionalProperties' => true
                        ]
                    ],
                    'required' => ['module', 'data']
                ]
            ],
            [
                'name' => 'update_record',
                'description' => 'Update an existing record',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'module' => [
                            'type' => 'string',
                            'description' => 'The module name'
                        ],
                        'id' => [
                            'type' => 'string',
                            'description' => 'The record ID'
                        ],
                        'data' => [
                            'type' => 'object',
                            'description' => 'The updated data',
                            'additionalProperties' => true
                        ]
                    ],
                    'required' => ['module', 'id', 'data']
                ]
            ],
            [
                'name' => 'delete_record',
                'description' => 'Delete a record',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'module' => [
                            'type' => 'string',
                            'description' => 'The module name'
                        ],
                        'id' => [
                            'type' => 'string',
                            'description' => 'The record ID'
                        ]
                    ],
                    'required' => ['module', 'id']
                ]
            ],
            [
                'name' => 'search_records',
                'description' => 'Search records across modules',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query'
                        ],
                        'modules' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Modules to search in',
                            'default' => ['Accounts', 'Contacts', 'Leads']
                        ]
                    ],
                    'required' => ['query']
                ]
            ],
            [
                'name' => 'relate_records',
                'description' => 'Create a relationship between two records',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'module' => [
                            'type' => 'string',
                            'description' => 'The primary module'
                        ],
                        'id' => [
                            'type' => 'string',
                            'description' => 'The primary record ID'
                        ],
                        'link_field' => [
                            'type' => 'string',
                            'description' => 'The relationship field name'
                        ],
                        'related_id' => [
                            'type' => 'string',
                            'description' => 'The related record ID'
                        ]
                    ],
                    'required' => ['module', 'id', 'link_field', 'related_id']
                ]
            ]
        ];

        $this->sendResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => $tools
            ]
        ]);
    }

    private function handleToolCall($id, $params) {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        // Ensure we have authentication
        if (!$this->ensureAuthenticated()) {
            $this->sendError($id, -32603, 'Authentication failed');
            return;
        }

        try {
            $result = null;

            switch ($toolName) {
                case 'list_records':
                    $result = $this->listRecords($arguments);
                    break;

                case 'get_record':
                    $result = $this->getRecord($arguments);
                    break;

                case 'create_record':
                    $result = $this->createRecord($arguments);
                    break;

                case 'update_record':
                    $result = $this->updateRecord($arguments);
                    break;

                case 'delete_record':
                    $result = $this->deleteRecord($arguments);
                    break;

                case 'search_records':
                    $result = $this->searchRecords($arguments);
                    break;

                case 'relate_records':
                    $result = $this->relateRecords($arguments);
                    break;

                default:
                    $this->sendError($id, -32601, 'Unknown tool: ' . $toolName);
                    return;
            }

            $this->sendResponse([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => json_encode($result, JSON_PRETTY_PRINT)
                        ]
                    ]
                ]
            ]);
        } catch (Exception $e) {
            $this->sendError($id, -32603, 'Tool execution failed: ' . $e->getMessage());
        }
    }

    private function ensureAuthenticated() {
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return true;
        }

        return $this->authenticate();
    }

    private function authenticate() {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->suiteCrmUrl . '/Api/access_token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'grant_type' => 'password',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username' => $this->username,
                'password' => $this->password
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return false;
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['access_token'])) {
            return false;
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + $data['expires_in'];

        return true;
    }

    private function apiRequest($method, $endpoint, $data = null) {
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $this->suiteCrmUrl . '/Api/V8' . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken
            ]
        ];

        switch ($method) {
            case 'GET':
                break;

            case 'POST':
                $options[CURLOPT_POST] = true;
                if ($data) {
                    $options[CURLOPT_POSTFIELDS] = json_encode($data);
                }
                break;

            case 'PATCH':
                $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
                if ($data) {
                    $options[CURLOPT_POSTFIELDS] = json_encode($data);
                }
                break;

            case 'DELETE':
                $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new Exception("API request failed with status $httpCode: $response");
        }

        return json_decode($response, true);
    }

    private function listRecords($args) {
        $module = $args['module'] ?? '';
        $limit = $args['limit'] ?? 20;
        $offset = $args['offset'] ?? 0;
        $filter = $args['filter'] ?? [];

        $endpoint = "/module/{$module}?page[size]={$limit}&page[number]=" . (floor($offset / $limit) + 1);

        if (!empty($filter)) {
            foreach ($filter as $field => $value) {
                $endpoint .= "&filter[{$field}]={$value}";
            }
        }

        return $this->apiRequest('GET', $endpoint);
    }

    private function getRecord($args) {
        $module = $args['module'] ?? '';
        $id = $args['id'] ?? '';

        $endpoint = "/module/{$module}/{$id}";

        return $this->apiRequest('GET', $endpoint);
    }

    private function createRecord($args) {
        $module = $args['module'] ?? '';
        $data = $args['data'] ?? [];

        $endpoint = "/module/{$module}";

        $payload = [
            'data' => [
                'type' => $module,
                'attributes' => $data
            ]
        ];

        return $this->apiRequest('POST', $endpoint, $payload);
    }

    private function updateRecord($args) {
        $module = $args['module'] ?? '';
        $id = $args['id'] ?? '';
        $data = $args['data'] ?? [];

        $endpoint = "/module/{$module}/{$id}";

        $payload = [
            'data' => [
                'type' => $module,
                'id' => $id,
                'attributes' => $data
            ]
        ];

        return $this->apiRequest('PATCH', $endpoint, $payload);
    }

    private function deleteRecord($args) {
        $module = $args['module'] ?? '';
        $id = $args['id'] ?? '';

        $endpoint = "/module/{$module}/{$id}";

        return $this->apiRequest('DELETE', $endpoint);
    }

    private function searchRecords($args) {
        $query = $args['query'] ?? '';
        $modules = $args['modules'] ?? ['Accounts', 'Contacts', 'Leads'];

        $results = [];

        foreach ($modules as $module) {
            $endpoint = "/module/{$module}?filter[name][LIKE]=%{$query}%";

            try {
                $moduleResults = $this->apiRequest('GET', $endpoint);
                if (isset($moduleResults['data'])) {
                    $results[$module] = $moduleResults['data'];
                }
            } catch (Exception $e) {
                // Continue with other modules if one fails
            }
        }

        return $results;
    }

    private function relateRecords($args) {
        $module = $args['module'] ?? '';
        $id = $args['id'] ?? '';
        $linkField = $args['link_field'] ?? '';
        $relatedId = $args['related_id'] ?? '';

        $endpoint = "/module/{$module}/{$id}/relationships/{$linkField}";

        $payload = [
            'data' => [
                'type' => $linkField,
                'id' => $relatedId
            ]
        ];

        return $this->apiRequest('POST', $endpoint, $payload);
    }

    private function sendResponse($response) {
        $this->stdout->write(json_encode($response) . "\n");
    }

    private function sendError($id, $code, $message) {
        $this->sendResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message
            ]
        ]);
    }
}

// Load environment variables from .env file if it exists
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// Start the server
$server = new SuiteCRMMCPServer();
$server->run();