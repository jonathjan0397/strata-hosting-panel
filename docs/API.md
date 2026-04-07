# Strata Hosting Panel API

The Strata Hosting Panel API is intended for billing and provisioning integrations. It uses Laravel Sanctum personal access tokens created from **Admin -> API Tokens**.

Base path:

```text
/api/v1
```

Authentication header:

```text
Authorization: Bearer <token>
Accept: application/json
```

## Token Abilities

Admin-created API tokens currently include:

```text
accounts:create
accounts:suspend
accounts:unsuspend
accounts:terminate
accounts:usage
catalog:read
```

## Accounts

### List Accounts

```http
GET /api/v1/accounts
```

Ability: `accounts:usage`

Query parameters:

| Name | Type | Notes |
|---|---|---|
| `search` | string | Optional username or user email search. |
| `status` | string | Optional `active` or `suspended`. |
| `per_page` | integer | Optional, 1-100. Defaults to 25. |

Returns a paginated `data` array plus `meta` pagination fields.

### Show Account

```http
GET /api/v1/accounts/{id}
```

Ability: `accounts:usage`

Returns account identity, user, node, package, limits, usage, and timestamps.

### Create Account

```http
POST /api/v1/accounts
```

Ability: `accounts:create`

Request body:

```json
{
  "name": "Jane Smith",
  "email": "jane@example.com",
  "username": "janesmith",
  "password": "optional-strong-password",
  "node_id": 1,
  "hosting_package_id": 2,
  "php_version": "8.4",
  "disk_limit_mb": 10240,
  "bandwidth_limit_mb": 102400,
  "max_domains": 10,
  "max_email_accounts": 25,
  "max_databases": 10
}
```

If `hosting_package_id` is supplied, package defaults are applied over direct limit fields.

### Suspend Account

```http
POST /api/v1/accounts/{id}/suspend
```

Ability: `accounts:suspend`

### Unsuspend Account

```http
POST /api/v1/accounts/{id}/unsuspend
```

Ability: `accounts:unsuspend`

### Terminate Account

```http
DELETE /api/v1/accounts/{id}
```

Ability: `accounts:terminate`

The panel keeps account state if remote server cleanup fails.

### Account Usage

```http
GET /api/v1/accounts/{id}/usage
```

Ability: `accounts:usage`

Returns disk, bandwidth, domain, mailbox, and database usage/limits.

## Catalog

### Packages

```http
GET /api/v1/packages
```

Ability: `catalog:read`

Returns active hosting packages with limits and attached feature list details.

### Feature Lists

```http
GET /api/v1/feature-lists
```

Ability: `catalog:read`

Returns feature-list records plus the feature catalog map.

## Error Handling

Errors use JSON where possible:

```json
{
  "error": "Server cleanup failed, account was kept in the panel: ..."
}
```

Validation errors use Laravel's standard `422` validation response shape.
