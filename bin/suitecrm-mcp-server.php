#!/usr/bin/env php
<?php
/**
 * SuiteCRM MCP Server Executable
 *
 * This file serves as the entry point for running the MCP server
 * from the command line or as configured in MCP clients.
 */

// Find the autoloader
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
];

$autoloadFound = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloadFound = true;
        break;
    }
}

if (!$autoloadFound) {
    fwrite(STDERR, "Autoloader not found. Please run 'composer install'.\n");
    exit(1);
}

// Load the main server file
$serverPath = __DIR__ . '/../suitecrm-mcp-server.php';
if (!file_exists($serverPath)) {
    fwrite(STDERR, "Server file not found at: $serverPath\n");
    exit(1);
}

// Include and run the server
require_once $serverPath;