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
migrations:read
migrations:write
```

## Webhooks

Admins can configure outbound webhook endpoints from **Admin -> Webhooks**. Webhooks are emitted from audit-backed lifecycle events such as account, migration, backup, package, token, and security actions.

Webhook requests are sent as `POST` with JSON bodies:

```json
{
  "id": 123,
  "event": "account.created",
  "actor_type": "admin",
  "subject_type": "App\\Models\\Account",
  "subject_id": 42,
  "payload": {},
  "created_at": "2026-04-07T00:00:00+00:00"
}
```

Headers:

```text
X-Strata-Event: account.created
X-Strata-Delivery: 123
X-Strata-Timestamp: <unix timestamp>
X-Strata-Signature: <hmac-sha256>   # only when a signing secret is configured
```

Signature input is:

```text
<timestamp>\n<body>
```

Endpoints can subscribe to all events by leaving the event list blank, or to specific audit event names such as `account.created` and `account.migration_cutover_complete`.

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

## Migrations

Migration endpoints expose the same conservative account-transfer workflow as the admin UI. Write actions enqueue queue-worker jobs and return `202 Accepted` with the current migration payload. Poll `GET /api/v1/migrations/{id}` for progress.

Automatic cutover remains limited to static/domain-only accounts. If an account has mailboxes, forwarders, FTP users, databases, database grants, or app installs, the API returns cutover blockers instead of forcing an unsafe move.

### List Migrations

```http
GET /api/v1/migrations
```

Ability: `migrations:read`

Query parameters:

| Name | Type | Notes |
|---|---|---|
| `status` | string | Optional migration status filter. |
| `account_id` | integer | Optional account filter. |
| `per_page` | integer | Optional, 1-100. Defaults to 25. |

### Show Migration

```http
GET /api/v1/migrations/{id}
```

Ability: `migrations:read`

Returns source/target nodes, source and target backups, status, error, and cutover blockers.

### Prepare Migration Backup

```http
POST /api/v1/migrations
```

Ability: `migrations:write`

Request body:

```json
{
  "account_id": 42,
  "target_node_id": 7
}
```

Queues a full source-node backup and returns the migration in `backup_running` status.

### Transfer Backup

```http
POST /api/v1/migrations/{id}/transfer
```

Ability: `migrations:write`

Queues transfer of the prepared archive to the target node and returns `transfer_running`.

### Restore Target

```http
POST /api/v1/migrations/{id}/restore
```

Ability: `migrations:write`

Queues target account provisioning and archive restore, then returns `restore_running`.

### Cut Over

```http
POST /api/v1/migrations/{id}/cutover
```

Ability: `migrations:write`

Queues panel ownership movement to the target node and vhost reprovisioning. If automatic cutover is unsafe, the response is `409` with a `blockers` array.

### Cleanup Source

```http
POST /api/v1/migrations/{id}/cleanup-source
```

Ability: `migrations:write`

Queues source-node cleanup after a completed cutover. Cleanup failures do not undo the completed target cutover.

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
