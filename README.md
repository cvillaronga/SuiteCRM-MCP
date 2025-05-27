# SuiteCRM MCP Server

A Model Context Protocol (MCP) server implementation for SuiteCRM that enables AI assistants to interact with SuiteCRM data.

## Features

- **List Records**: Retrieve records from any SuiteCRM module
- **Get Record**: Fetch a specific record by ID
- **Create Record**: Create new records in any module
- **Update Record**: Modify existing records
- **Delete Record**: Remove records from the system
- **Search Records**: Search across multiple modules
- **Relate Records**: Create relationships between records

## Prerequisites

- PHP 7.4 or higher
- SuiteCRM instance with API v8 enabled
- Composer for dependency management
- SuiteCRM OAuth2 credentials

## Installation

1. Clone this repository:
```bash
git clone https://github.com/cvillaronga/suitecrm-mcp-server.git
cd suitecrm-mcp-server
```

2. Install dependencies:
```bash
composer install
```

3. Configure your SuiteCRM credentials:
```bash
cp .env.example .env
# Edit .env with your SuiteCRM details
```

## Configuration

Create a `.env` file with the following variables:

```env
SUITECRM_URL=https://your-suitecrm-instance.com
SUITECRM_CLIENT_ID=your-client-id
SUITECRM_CLIENT_SECRET=your-client-secret
SUITECRM_USERNAME=your-username
SUITECRM_PASSWORD=your-password
```

### Obtaining OAuth2 Credentials

1. Log in to your SuiteCRM instance as an administrator
2. Navigate to Admin → OAuth2 Clients and Tokens
3. Create a new client with:
   - Client Type: User Credentials
   - Is Confidential: Yes
4. Copy the generated Client ID and Client Secret

## Usage

### With Claude Desktop

Add to your Claude Desktop configuration (`~/Library/Application Support/Claude/claude_desktop_config.json` on macOS):

```json
{
  "mcpServers": {
    "suitecrm": {
      "command": "php",
      "args": ["/path/to/suitecrm-mcp-server/suitecrm-mcp-server.php"],
      "env": {
        "SUITECRM_URL": "https://your-suitecrm-instance.com",
        "SUITECRM_CLIENT_ID": "your-client-id",
        "SUITECRM_CLIENT_SECRET": "your-client-secret",
        "SUITECRM_USERNAME": "your-username",
        "SUITECRM_PASSWORD": "your-password"
      }
    }
  }
}
```

### With Other MCP Clients

The server communicates via stdio and follows the MCP protocol specification.

## Available Tools

### list_records
List records from a module with optional filtering:
```json
{
  "module": "Accounts",
  "limit": 20,
  "offset": 0,
  "filter": {
    "name": "Acme Corp"
  }
}
```

### get_record
Retrieve a specific record:
```json
{
  "module": "Contacts",
  "id": "12345-678-90"
}
```

### create_record
Create a new record:
```json
{
  "module": "Leads",
  "data": {
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@example.com"
  }
}
```

### update_record
Update an existing record:
```json
{
  "module": "Accounts",
  "id": "12345-678-90",
  "data": {
    "name": "Updated Account Name",
    "phone": "+1234567890"
  }
}
```

### delete_record
Delete a record:
```json
{
  "module": "Leads",
  "id": "12345-678-90"
}
```

### search_records
Search across multiple modules:
```json
{
  "query": "John",
  "modules": ["Accounts", "Contacts", "Leads"]
}
```

### relate_records
Create a relationship between records:
```json
{
  "module": "Accounts",
  "id": "account-id",
  "link_field": "contacts",
  "related_id": "contact-id"
}
```

## Supported Modules

The server supports all standard SuiteCRM modules including:
- Accounts
- Contacts
- Leads
- Opportunities
- Cases
- Calls
- Meetings
- Tasks
- Notes
- Emails
- Campaigns
- And all custom modules

## Error Handling

The server implements proper error handling for:
- Authentication failures
- Invalid module names
- Missing required fields
- API communication errors
- Invalid record IDs

## Development

### Running Tests

```bash
composer test
```

### Code Style

This project follows PSR-12 coding standards.

## Security Considerations

- Store credentials securely using environment variables
- Use HTTPS for SuiteCRM connections
- Implement proper access controls in SuiteCRM
- Regularly rotate OAuth2 credentials
- Never commit credentials to version control

## Troubleshooting

### Authentication Errors
- Verify OAuth2 client is active in SuiteCRM
- Check username/password are correct
- Ensure API v8 is enabled in SuiteCRM

### Connection Issues
- Verify SuiteCRM URL is accessible
- Check firewall rules
- Ensure SuiteCRM API is enabled

### Module Errors
- Verify module name is correct (case-sensitive)
- Check user has appropriate permissions
- Ensure module is enabled in SuiteCRM

## License

see LICENSE file for details

## Contributing

1. Fork the repository
2. Create a feature branch
3. Commit your changes
4. Push to the branch
5. Create a Pull Request

## Support

For issues and feature requests, please use the GitHub issue tracker.
