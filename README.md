# SuiteCRM MCP Integration Plugin (Design Document)

## 1. Summary

This document outlines the design for a plugin that integrates SuiteCRM with Large Language Models (LLMs) using the Multimodal Context Protocol (MCP). The plugin enables AI-powered functionality by allowing LLMs to access and process contextual information from SuiteCRM in a standardized format, including Accounts, Contacts, Cases, and other beans through an agnostic approach to data modeling.

The MCP standardizes how application data is presented to LLMs, similar to how USB-C provides a standardized way to connect devices. This plugin allows SuiteCRM data to be structured according to the MCP specification, making it compatible with any MCP-supporting LLM.

## 2. Design Objectives

### 2.1 Primary Objectives

- Create a plugin that transforms SuiteCRM data into MCP-compliant context bundles
- Develop an agnostic bean model to handle any SuiteCRM module type
- Support core CRM entities (Accounts, Contacts, Cases) with specialized handling
- Establish secure, efficient communication between SuiteCRM and LLM providers
- Respect SuiteCRM's security model and data access controls
- Optimize context bundles for token efficiency and semantic clarity

### 2.2 Success Criteria

- The plugin can serialize any SuiteCRM bean into an MCP-compliant context bundle
- Context bundles provide sufficient information for an LLM to answer questions about the data
- The system handles relationships between entities appropriately
- Data transmission is secure and respects user permissions
- The integration is performant, with minimal impact on SuiteCRM operations
- The implementation is maintainable and extensible

## 3. System Architecture

### 3.1 High-Level Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│                        SuiteCRM                             │
│                                                             │
│  ┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐ │
│  │ Accounts  │  │ Contacts  │  │   Cases   │  │Other Beans│ │
│  └───────────┘  └───────────┘  └───────────┘  └───────────┘ │
│         │             │              │              │       │
└─────────┼─────────────┼──────────────┼──────────────┼───────┘
          │             │              │              │
          ▼             ▼              ▼              ▼
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│                    MCP Integration Plugin                   │
│                                                             │
│  ┌───────────────────────┐       ┌───────────────────────┐  │
│  │                       │       │                       │  │
│  │  Context Serializer   │◄─────►│  Bean Type Manager    │  │
│  │                       │       │                       │  │
│  └───────────────────────┘       └───────────────────────┘  │
│                                                             │
│  ┌───────────────────────┐       ┌───────────────────────┐  │
│  │                       │       │                       │  │
│  │ Relationship Manager  │◄─────►│  Security Manager     │  │
│  │                       │       │                       │  │
│  └───────────────────────┘       └───────────────────────┘  │
│                                                             │
│  ┌───────────────────────┐       ┌───────────────────────┐  │
│  │                       │       │                       │  │
│  │    MCP Formatter      │◄─────►│  Context Optimizer    │  │
│  │                       │       │                       │  │
│  └───────────────────────┘       └───────────────────────┘  │
│                                                             │
│  ┌───────────────────────┐       ┌───────────────────────┐  │
│  │                       │       │                       │  │
│  │       API Hub         │◄─────►│   Admin Interface     │  │
│  │                       │       │                       │  │
│  └───────────────────────┘       └───────────────────────┘  │
│                │                                            │
└────────────────┼────────────────────────────────────────────┘
                 │
                 ▼
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│                     LLM Providers                           │
│                                                             │
│  ┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐ │
│  │  OpenAI   │  │ Anthropic │  │  Mistral  │  │   Others  │ │
│  └───────────┘  └───────────┘  └───────────┘  └───────────┘ │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 3.2 Component Description

#### 3.2.1 Context Serializer
Transforms SuiteCRM beans and their data into MCP-compatible context bundles.

#### 3.2.2 Bean Type Manager
Handles the different types of beans in SuiteCRM, with specialized processors for common entities (Accounts, Contacts, Cases) and a generic processor for other bean types.

#### 3.2.3 Relationship Manager
Manages the relationships between different beans, ensuring that related entities are properly included in context bundles.

#### 3.2.4 Security Manager
Enforces SuiteCRM's security model, ensuring that only authorized data is included in context bundles.

#### 3.2.5 MCP Formatter
Ensures that context bundles comply with the MCP specification, providing proper structure and metadata.

#### 3.2.6 Context Optimizer
Optimizes context bundles for token efficiency, removing redundant information and prioritizing important data.

#### 3.2.7 API Hub
Manages communication with LLM providers, handling authentication, request formatting, and response processing.

#### 3.2.8 Admin Interface
Provides configuration options for the plugin, including LLM provider settings, security policies, and context bundle optimization parameters.

## 4. Data Flow

### 4.1 Basic Request Flow

```
┌────────────┐     ┌────────────┐     ┌────────────┐     ┌────────────┐
│            │     │            │     │            │     │            │
│  SuiteCRM  │     │  Context   │     │    MCP     │     │    LLM     │
│   User     │────►│ Generation │────►│ Formatting │────►│  Provider  │
│ Interface  │     │   Process  │     │   Process  │     │    API     │
│            │     │            │     │            │     │            │
└────────────┘     └────────────┘     └────────────┘     └────────────┘
       ▲                                                       │
       │                                                       │
       │                                                       ▼
┌────────────┐     ┌────────────┐     ┌────────────┐     ┌────────────┐
│            │     │            │     │            │     │            │
│  Response  │     │  Response  │     │  Response  │     │    LLM     │
│ Processing │◄────│ Integration│◄────│ Formatting │◄────│  Response  │
│            │     │            │     │            │     │            │
└────────────┘     └────────────┘     └────────────┘     └────────────┘
```

### 4.2 Detailed Context Generation Flow

```
┌────────────┐     ┌────────────┐     ┌────────────┐
│            │     │            │     │            │
│  Bean      │     │ Permission │     │   Bean     │
│ Retrieval  │────►│  Check     │────►│  Analysis  │
│            │     │            │     │            │
└────────────┘     └────────────┘     └────────────┘
                                             │
                                             ▼
┌────────────┐     ┌────────────┐     ┌────────────┐
│            │     │            │     │            │
│ Related    │     │ Primary    │     │  Field     │
│ Entity     │◄────│ Entity     │◄────│ Extraction │
│ Discovery  │     │ Processing │     │            │
└────────────┘     └────────────┘     └────────────┘
      │
      ▼
┌────────────┐     ┌────────────┐     ┌────────────┐
│            │     │            │     │            │
│ Related    │     │ Activity   │     │ Bundle     │
│ Entity     │────►│ History    │────►│ Assembly   │
│ Processing │     │ Collection │     │            │
└────────────┘     └────────────┘     └────────────┘
```

## 5. MCP Context Bundle Structure

### 5.1 Generic MCP Bundle Structure

```json
{
  "metadata": {
    "protocol_version": "mcp-v1",
    "bundle_type": "crm_entity",
    "source": "suitecrm",
    "timestamp": "2025-04-12T15:30:22Z",
    "source_user": {
      "id": "user-123",
      "name": "Alice Smith",
      "role": "Sales Manager"
    },
    "request_id": "mcp-req-789456"
  },
  "primary_content": {
    "type": "entity_type",
    "id": "entity-id-123",
    "name": "Entity Name",
    "attributes": {},
    "created_at": "2025-01-15T10:22:33Z",
    "updated_at": "2025-04-10T09:15:42Z"
  },
  "related_content": {
    "entity_type_1": [],
    "entity_type_2": []
  },
  "action_history": [],
  "context": {
    "query": "What is the current status of this account?",
    "conversation_history": []
  }
}
```

### 5.2 Account Context Bundle Example

```json
{
  "metadata": {
    "protocol_version": "mcp-v1",
    "bundle_type": "organization",
    "source": "suitecrm",
    "timestamp": "2025-04-12T15:30:22Z",
    "source_user": {
      "id": "1",
      "name": "Alice Smith",
      "role": "Sales Manager"
    },
    "request_id": "mcp-req-789456"
  },
  "primary_content": {
    "type": "organization",
    "id": "a123456",
    "name": "Acme Corporation",
    "attributes": {
      "industry": "Manufacturing",
      "annual_revenue": {
        "value": 5200000,
        "formatted": "$5,200,000"
      },
      "employees": "250",
      "account_type": "Customer",
      "rating": "A",
      "website": "https://www.acmecorp.example"
    },
    "addresses": {
      "billing": {
        "street": "123 Business Ave",
        "city": "Metropolis",
        "state": "NY",
        "postal_code": "10001",
        "country": "USA",
        "type": "billing"
      },
      "shipping": {
        "street": "456 Logistics Blvd",
        "city": "Metropolis",
        "state": "NY",
        "postal_code": "10002",
        "country": "USA",
        "type": "shipping"
      }
    },
    "phone_numbers": [
      {
        "number": "+1 (555) 123-4567",
        "type": "office"
      },
      {
        "number": "+1 (555) 987-6543",
        "type": "fax"
      }
    ],
    "description": "Leading manufacturer of industrial equipment and supplies",
    "created_at": "2023-06-15T09:30:00Z",
    "updated_at": "2025-03-28T14:45:22Z"
  },
  "related_content": {
    "contacts": [
      {
        "type": "person",
        "id": "c123456",
        "name": "John Smith",
        "attributes": {
          "title": "Chief Executive Officer",
          "department": "Executive",
          "email": "jsmith@acmecorp.example",
          "phone": "+1 (555) 123-7890",
          "is_primary": true
        }
      },
      {
        "type": "person",
        "id": "c234567",
        "name": "Sarah Jones",
        "attributes": {
          "title": "Procurement Manager",
          "department": "Operations",
          "email": "sjones@acmecorp.example",
          "phone": "+1 (555) 123-8901",
          "is_primary": false
        }
      }
    ],
    "cases": [
      {
        "type": "support_case",
        "id": "s123456",
        "name": "API Integration Issue",
        "attributes": {
          "status": "Open",
          "priority": "High",
          "created_at": "2025-04-01T10:15:30Z",
          "updated_at": "2025-04-10T09:30:45Z"
        },
        "description": "Customer is experiencing timeout errors when using our API for batch processing."
      },
      {
        "type": "support_case",
        "id": "s234567",
        "name": "Billing Discrepancy",
        "attributes": {
          "status": "Open",
          "priority": "Medium",
          "created_at": "2025-04-05T14:22:10Z",
          "updated_at": "2025-04-10T11:05:33Z"
        },
        "description": "Invoice #INV-20250405 shows incorrect quantity for order #ORD-98765."
      }
    ],
    "opportunities": [
      {
        "type": "sales_opportunity",
        "id": "o123456",
        "name": "Enterprise Solution Upgrade",
        "attributes": {
          "amount": {
            "value": 250000,
            "formatted": "$250,000"
          },
          "stage": "Proposal",
          "probability": 60,
          "close_date": "2025-06-30"
        },
        "description": "Upgrade of existing deployment to Enterprise tier with additional modules"
      }
    ]
  },
  "action_history": [
    {
      "type": "call",
      "id": "call123",
      "name": "Q3 Production Schedule Discussion",
      "timestamp": "2025-04-08T10:30:00Z",
      "attributes": {
        "direction": "Outbound",
        "duration": {
          "hours": 0,
          "minutes": 25
        },
        "status": "Held"
      },
      "participants": [
        {
          "type": "person",
          "id": "c234567",
          "name": "Sarah Jones",
          "role": "Procurement Manager"
        },
        {
          "type": "user",
          "id": "1",
          "name": "Alice Smith",
          "role": "Sales Manager"
        }
      ],
      "description": "Discussed upcoming needs for Q3 production schedule. Sarah indicated interest in the new XJ-5000 model and requested a demo next week."
    },
    {
      "type": "meeting",
      "id": "meet456",
      "name": "Quarterly Business Review",
      "timestamp": "2025-03-25T14:00:00Z",
      "attributes": {
        "duration": {
          "hours": 1,
          "minutes": 0
        },
        "location": "Virtual Meeting",
        "status": "Held"
      },
      "participants": [
        {
          "type": "person",
          "id": "c123456",
          "name": "John Smith",
          "role": "Chief Executive Officer"
        },
        {
          "type": "person",
          "id": "c234567",
          "name": "Sarah Jones",
          "role": "Procurement Manager"
        },
        {
          "type": "user",
          "id": "1",
          "name": "Alice Smith",
          "role": "Sales Manager"
        }
      ],
      "description": "Reviewed performance metrics, discussed expansion to East Asia market in Q4."
    },
    {
      "type": "email",
      "id": "email789",
      "name": "Re: Executive Presentation",
      "timestamp": "2025-04-10T11:45:00Z",
      "attributes": {
        "direction": "Inbound",
        "status": "Read"
      },
      "participants": [
        {
          "type": "person",
          "id": "c123456",
          "name": "John Smith",
          "role": "Chief Executive Officer"
        },
        {
          "type": "user",
          "id": "1",
          "name": "Alice Smith",
          "role": "Sales Manager"
        }
      ],
      "description": "Confirmed availability for April 20 presentation, requested additional information on ROI projections."
    }
  ],
  "context": {
    "query": "What are the key issues and opportunities with Acme Corp right now?",
    "conversation_history": [
      {
        "role": "user",
        "content": "Give me a summary of Acme Corp"
      },
      {
        "role": "assistant",
        "content": "Acme Corporation is an active customer in the Manufacturing sector with 250 employees and $5.2M annual revenue. They currently have 2 open support cases and 1 active opportunity worth $250,000 in the proposal stage."
      }
    ]
  }
}
```

## 6. Technical Implementation

### 6.1 Bean Type Mapping System

The plugin will use a configuration-based mapping system to translate SuiteCRM bean types to MCP entity types:

| SuiteCRM Module | MCP Entity Type      | Context Sub-type |
|-----------------|----------------------|------------------|
| Accounts        | organization         | business         |
| Contacts        | person               | contact          |
| Leads           | person               | lead             |
| Cases           | support_case         | issue            |
| Opportunities   | sales_opportunity    | deal             |
| Meetings        | calendar_event       | meeting          |
| Calls           | communication_event  | call             |
| Tasks           | task                 | action_item      |
| Notes           | note                 | annotation       |
| Emails          | communication_event  | email            |
| Documents       | document             | file             |
| *Other beans*   | generic_entity       | *module_name*    |

This mapping system will be extensible via the Admin Interface, allowing administrators to customize mappings for custom modules.

### 6.2 Field Mapping Framework

A flexible field mapping framework will determine how SuiteCRM bean fields are transformed into MCP attributes:

```php
$field_mappings = [
    'Accounts' => [
        'name' => ['target' => 'name', 'transform' => null],
        'industry' => ['target' => 'attributes.industry', 'transform' => null],
        'annual_revenue' => [
            'target' => 'attributes.annual_revenue', 
            'transform' => 'formatCurrency'
        ],
        'billing_address_street' => [
            'target' => 'addresses.billing.street', 
            'transform' => null
        ],
        // Additional mappings...
    ],
    'Contacts' => [
        'first_name' => ['target' => 'name_components.first', 'transform' => null],
        'last_name' => ['target' => 'name_components.last', 'transform' => null],
        // Combined transformation for name
        '__name' => [
            'source' => ['first_name', 'last_name'],
            'target' => 'name',
            'transform' => 'combineNames'
        ],
        // Additional mappings...
    ],
    // Mappings for other bean types...
];
```

The mapping system will support:
- Direct mappings (bean field → MCP attribute)
- Transformed mappings (with value processing)
- Composite mappings (multiple bean fields → single MCP attribute)
- Nested attribute paths (for complex MCP structures)

### 6.3 Relationship Handling

The plugin will use a relationship configuration system to determine which related entities to include in context bundles:

```php
$relationship_config = [
    'Accounts' => [
        'contacts' => [
            'relation' => 'accounts_contacts',
            'bean_type' => 'Contacts',
            'foreign_key' => 'account_id',
            'max_items' => 5,
            'sort_by' => 'date_modified',
            'sort_direction' => 'DESC'
        ],
        'cases' => [
            'relation' => 'accounts_cases',
            'bean_type' => 'Cases',
            'foreign_key' => 'account_id',
            'max_items' => 5,
            'sort_by' => 'date_modified',
            'sort_direction' => 'DESC'
        ],
        // Additional relationships...
    ],
    // Configurations for other bean types...
];
```

The system will support:
- One-to-many relationships
- Many-to-many relationships
- Custom relationship logic for complex cases
- Dynamic relationship discovery based on database schema

### 6.4 Security and Permission Handling

The plugin will integrate with SuiteCRM's ACL (Access Control List) system to enforce data access permissions:

```php
/**
 * Check if the current user has access to a bean
 */
function checkBeanAccess($bean, $access_type = 'view') {
    // Get current user
    global $current_user;
    
    // Check if admin (full access)
    if ($current_user->is_admin) {
        return true;
    }
    
    // Check ACL for this bean type and access type
    return ACLController::checkAccess($bean->module_name, $access_type, $bean->isOwner($current_user->id));
}

/**
 * Filter bean fields based on field-level security
 */
function filterSecureFields($bean, $fields) {
    $filtered_fields = [];
    
    foreach ($fields as $field_name => $field_value) {
        // Check if field is viewable by current user
        if ($bean->ACLFieldAccess($field_name, 'read')) {
            $filtered_fields[$field_name] = $field_value;
        }
    }
    
    return $filtered_fields;
}
```

The security system will:
- Respect module-level access permissions
- Apply field-level security
- Filter related entities based on access rights
- Log access attempts for auditing
- Provide configurable data masking for sensitive fields

### 6.5 Context Bundle Generation Process

```php
/**
 * High-level process for generating an MCP context bundle
 */
function generateMCPContextBundle($module, $record_id, $options = []) {
    global $current_user;
    
    // 1. Retrieve the primary bean
    $bean = BeanFactory::getBean($module, $record_id);
    if (empty($bean->id)) {
        return createErrorBundle("Record not found");
    }
    
    // 2. Check access permissions
    if (!checkBeanAccess($bean)) {
        return createErrorBundle("Access denied");
    }
    
    // 3. Determine MCP entity type
    $entity_type = getMCPEntityType($module);
    
    // 4. Extract and transform primary entity fields
    $primary_content = transformPrimaryContent($bean, $entity_type);
    
    // 5. Discover and process related entities
    $related_content = processRelatedEntities($bean, $module);
    
    // 6. Collect activity history
    $action_history = collectActionHistory($bean, $module);
    
    // 7. Build metadata section
    $metadata = buildMetadataSection($bean, $entity_type, $current_user);
    
    // 8. Build context section
    $context = buildContextSection($options);
    
    // 9. Assemble the complete bundle
    $bundle = [
        'metadata' => $metadata,
        'primary_content' => $primary_content,
        'related_content' => $related_content,
        'action_history' => $action_history,
        'context' => $context
    ];
    
    // 10. Optimize the bundle if needed
    if (isset($options['max_tokens']) && $options['max_tokens'] > 0) {
        $bundle = optimizeBundle($bundle, $options['max_tokens']);
    }
    
    return $bundle;
}
```

## 7. Plugin Integration with SuiteCRM

### 7.1 Plugin File Structure

```
SuiteCRM_MCP_Plugin/
├── MCP/
│   ├── core/
│   │   ├── MCPAdapter.php
│   │   ├── BeanSerializer.php
│   │   ├── RelationshipManager.php
│   │   ├── SecurityManager.php
│   │   └── ContextOptimizer.php
│   ├── processors/
│   │   ├── AccountProcessor.php
│   │   ├── ContactProcessor.php
│   │   ├── CaseProcessor.php
│   │   └── GenericProcessor.php
│   ├── api/
│   │   ├── APIHub.php
│   │   ├── OpenAIConnector.php
│   │   ├── AnthropicConnector.php
│   │   └── MistralConnector.php
│   └── util/
│       ├── FieldTransformer.php
│       ├── TokenCounter.php
│       └── Logger.php
├── controllers/
│   ├── MCPAdminController.php
│   └── MCPActionController.php
├── views/
│   ├── admin/
│   │   ├── config.tpl
│   │   ├── mappings.tpl
│   │   └── security.tpl
│   └── actions/
│       ├── context_viewer.tpl
│       └── llm_interface.tpl
├── language/
│   └── en_us.lang.php
├── metadata/
│   ├── mcp_config.php
│   ├── bean_mappings.php
│   └── field_mappings.php
└── manifest.php
```

### 7.2 Plugin Installation Process

1. Upload plugin package to SuiteCRM
2. Execute database schema updates
3. Register admin screens and actions
4. Initialize default configurations
5. Set up security roles and permissions
6. Create demo examples for common use cases

### 7.3 API Integration

The plugin will expose several API endpoints for SuiteCRM:

#### 7.3.1 MCP Bundle Generation API
```
GET /index.php?module=MCP&action=GetContextBundle&record_id={record_id}&module_name={module_name}
```

Parameters:
- `record_id`: ID of the SuiteCRM record
- `module_name`: Name of the SuiteCRM module
- `include_related`: Whether to include related entities (default: true)
- `max_tokens`: Maximum token count for optimization (optional)

Response:
```json
{
  "success": true,
  "bundle": {
    "metadata": { ... },
    "primary_content": { ... },
    "related_content": { ... },
    "action_history": { ... },
    "context": { ... }
  }
}
```

#### 7.3.2 LLM Query API
```
POST /index.php?module=MCP&action=QueryLLM
```

Request Body:
```json
{
  "record_id": "a123456",
  "module_name": "Accounts",
  "query": "What are the key issues and opportunities with Acme Corp right now?",
  "provider": "anthropic",
  "model": "claude-3-sonnet-20240229",
  "include_related": true,
  "max_tokens": 8000,
  "response_tokens": 1000
}
```

Response:
```json
{
  "success": true,
  "response": "Acme Corporation currently has two key issues and one major opportunity:\n\nIssues:\n1. A high-priority API Integration Issue (opened April 1st) where the customer is experiencing timeout errors during batch processing.\n2. A medium-priority Billing Discrepancy (opened April 5th) regarding incorrect quantities on invoice #INV-20250405.\n\nOpportunity:\nAn Enterprise Solution Upgrade worth $250,000 currently in the Proposal stage with a 60% probability of closing by June 30th.\n\nRecent activities indicate positive engagement, with a call on April 8th where Sarah Jones (Procurement Manager) expressed interest in the new XJ-5000 model and requested a demo. CEO John Smith has confirmed availability for an executive presentation on April 20th and has specifically requested ROI projections.",
  "usage": {
    "prompt_tokens": 4532,
    "completion_tokens": 145,
    "total_tokens": 4677
  }
}
```

## 8. Data Flow Example: Account Query

### 8.1 User Requests Information About an Account

1. User navigates to an Account detail view in SuiteCRM
2. User clicks "Ask AI" button and enters a question
3. SuiteCRM frontend sends query to the MCP plugin

### 8.2 Context Bundle Generation

1. Plugin identifies the account record and current user
2. Security check confirms user has access to the account
3. Account data is retrieved from database
4. Bean is mapped to MCP entity type "organization"
5. Field values are transformed according to mapping rules
6. Related entities (contacts, cases, opportunities) are identified
7. Permission-filtered related entities are retrieved and transformed
8. Recent activities (calls, meetings, emails) are collected
9. MCP bundle is assembled with all components

### 8.3 LLM API Interaction

1. Generated MCP bundle is combined with user query
2. Complete prompt is prepared for selected LLM provider
3. Request is sent to LLM API (e.g., Anthropic Claude)
4. Response is received from LLM API
5. Response metrics (tokens, timing) are logged

### 8.4 Response Handling

1. LLM response is formatted for SuiteCRM UI
2. Response is sent back to user in context of the account view
3. Interaction is logged for future reference
4. Context bundle and response are cached for similar future queries

## 9. Extension Mechanisms

### 9.1 Adding Support for Custom Modules

The plugin provides an extension mechanism for custom SuiteCRM modules:

1. Register the module in the bean mapping configuration
2. Define field mappings for the custom module
3. Configure relationship handling
4. Optionally create a specialized processor class

Example for a custom "Projects" module:

```php
// In bean_mappings.php
$bean_mappings['AOS_Projects'] = [
    'mcp_type'
