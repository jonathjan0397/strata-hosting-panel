# WHM-cPanel Parity Backlog

Branch: `main`

## Goal

Bring Strata Hosting Panel to competitive feature parity with WHM/cPanel in the areas that matter operationally:

- admin server management
- account packaging and lifecycle
- reseller controls
- end-user hosting workflows
- diagnostics, migrations, backups, and safety

This is not a goal to clone every commercial integration in cPanel. The target is practical parity for real hosting operations.

## Current Position

Strata already has a usable hosting-panel core:

- nodes and services
- account, domain, DNS, email, database, FTP, file manager, backups
- reseller layer
- admin API tokens
- shell, firewall, fail2ban, spam, updates

The main parity gap is breadth and workflow maturity.

## Phase 1: WHM Core

### 1. Packages and Feature Lists
Status: `in progress`

- Add hosting packages with reusable limits and defaults.
- Add feature lists as named capability bundles.
- Attach packages to accounts.
- Allow reseller-safe packages and package assignment.
- Replace ad hoc account-limit entry with package-first flows.

Deliverables:

- `feature_lists` schema
- `hosting_packages` schema
- admin CRUD for feature lists
- admin CRUD for hosting packages
- account creation support for package assignment
- reseller/API package assignment support

Implemented on `main`:

- `FeatureList` and `HostingPackage` models
- package and feature-list migrations
- admin CRUD controllers and Inertia pages
- package-aware admin account creation
- package-aware reseller account creation with reseller-safe filtering
- package-aware API account creation
- admin navigation entries for packages and feature lists
- package-aware user feature enforcement in routing and navigation
- reseller-visible package catalog and package reassignment on client edits
- reseller default package preference for new client account forms
- admin account bulk package reassignment

### 2. Reseller Package Management
Status: `in progress`

- Reseller-visible package catalog
- package availability controls
- reseller default package selection
- reseller package-scoped quota enforcement

Implemented on `main`:

- reseller-visible package catalog filtered to active reseller-safe packages
- reseller client package reassignment with quota enforcement
- reseller default package selection from the reseller settings screen

### 3. Account Lifecycle and Migration
Status: `in progress`

- import from backup archive
- node-to-node migration workflow
- transfer status UI
- rollback-safe migration operations

Implemented on `main`:

- admin import workflow that scans an account node for existing backup archives and registers missing completed backup jobs
- admin migration queue with source/target node tracking
- migration-prep workflow that creates a full source-node backup and marks the migration `backup_ready`
- migration audit event for prepared backups
- authenticated target-node backup upload endpoint and transfer action to move prepared archives to `transfer_ready`
- target-node restore action that provisions the target account, restores the transferred archive, and retains the source for validation/cutover
- conservative cutover action for static/domain-only accounts with target-node panel ownership update and vhost reprovision rollback
- explicit post-cutover source-node cleanup action with failure isolation from the completed cutover state
- migration queue cutover-blocker visibility for accounts that need manual service re-provisioning
- API migration workflow endpoints for list, detail, prepare, transfer, restore, cutover, and source cleanup
- queued migration execution for prepare, transfer, restore, cutover, and source cleanup with migration-row progress tracking
- competitor backup import queue for cPanel/CWP `.tar.gz` and `.tgz` archives, converting supported website files and SQL dumps into normal Strata full-backup jobs
- competitor backup import metadata preview for detected domains, DNS zone files, mailbox names, and forwarders
- queued manual backup creation, full restore, and path restore with restore status/error tracking

### 4. Metrics and Logs
Status: `in progress`

- domain/account error logs
- access log viewing/downloading
- bandwidth and request summaries
- account resource dashboards

Implemented on `main`:

- package-gated user metrics route
- user metrics dashboard with resource summaries
- bounded domain access/error log tailing
- bounded PHP error log tailing
- bounded recent log downloads from the Metrics workspace
- recent access-log traffic summaries with request, bandwidth, status-code, method, and top-path breakdowns
- scheduled daily traffic aggregation with 30-day stored request/bandwidth history

## Phase 2: cPanel Daily-Use Parity

### 5. Email Maturity
Status: `in progress`

- email filters
- spam controls per domain/account
- delivery tracking
- bulk address/forwarder import
- mail archive controls

Implemented on `main`:

- mailbox-level email filters
- shared Sieve compilation for filters plus autoresponders
- automatic DKIM/SPF/DMARC record provisioning and deliverability checks
- Domain Key Manager for viewing, copying, regenerating, and publishing DKIM/domain-key records
- SPF Manager for editing, validating, copying, and restoring recommended domain SPF records
- user filter management UI inside the existing email workflow
- user spam activity dashboard backed by Rspamd node stats
- mailbox-level spam policy controls backed by Sieve rules
- domain-level default spam policy with optional apply-to-existing mailbox sync
- user delivery tracking against bounded postfix and dovecot log views
- bulk mailbox and forwarder CSV import from the per-domain email workspace
- mailbox-level archive controls that copy incoming mail to an Archive folder through Sieve

### 6. Files and Developer Tooling
Status: `in progress`

- Git repository management
- directory privacy
- disk-usage explorer
- partial/path restore from backups
- Web Disk or equivalent

Implemented on `main`:

- package-gated user Git Version Control page
- jailed repository inspection for account web roots
- jailed repository init, HTTPS clone, and fast-forward pull operations
- domain-aware path suggestions for hosted document roots
- domain-level directory privacy with hashed credentials and reprovision-backed auth rules
- package-gated disk-usage explorer with bounded account-jail scanning
- file/directory path restore from completed files/full backups
- Web Disk-style FTPS connection guide backed by jailed FTP accounts

### 7. Database Breadth
Status: `in progress`

- PostgreSQL support
- remote database access management
- phpMyAdmin/phpPgAdmin integration

Implemented on `main`:

- host-scoped MariaDB grants for localhost or remote hosts
- user-facing remote database access management in the Databases workspace
- PostgreSQL database/user lifecycle support in the existing Databases workspace
- PostgreSQL service/log visibility in the admin node tools
- phpMyAdmin/phpPgAdmin launch and connection guide with manual database credential login

## Phase 3: Advanced Platform Parity

### 8. Security Depth
Status: `in progress`

- ModSecurity controls
- IP blocker
- hotlink protection
- leech protection
- malware scan integration

Implemented on `main`:

- package-gated domain hotlink protection with nginx/apache vhost rules
- domain-level Force HTTPS redirects for SSL-enabled sites
- admin IP blocker backed by UFW deny-from rules
- dedicated Fail2Ban administration page with service start/stop/restart, manual bans, and per-jail unbans
- package-gated ClamAV malware scanner for jailed account paths with optional quarantine
- queued ClamAV scan execution with persisted scan history and polling status
- package-gated per-domain ModSecurity directive controls with enforce/detection-only modes
- package-gated per-domain leech protection with Nginx request limiting and Apache block/redirect directives

### 9. UX and Navigation
Status: `in progress`

- section-level dashboards
- bulk actions
- search and discoverability improvements
- package-centric onboarding flows

Implemented on `main`:

- modern glassmorphism theme foundation with persisted user preference
- four selectable role-wide palettes: Smoky Gray, Aurora Teal, Ember Gold, and Violet Bloom
- workspace-aware top bar for admin, reseller, and hosting contexts
- quick-jump navigation search for common role-specific destinations
- shared page header, action card, and empty-state components
- task-oriented user hosting dashboard with common workflow shortcuts
- task-oriented admin dashboard with WHM-style operational shortcuts
- task-oriented reseller dashboard with package and client shortcuts
- website/domain index and create screens converted to shared page structure
- per-domain email workspace converted to shared page structure with diagnostics shortcuts
- backup, database, and FTP workspaces converted to shared page structure with usage summaries and empty states
- autoresponder, spam overview, DNS zone list, and SSH key pages converted to shared page structure
- metrics, disk usage, and Git tooling pages converted to shared page structure
- admin account, domain, and node indexes converted to shared page structure with operational summaries
- admin account bulk suspend and unsuspend actions
- admin account bulk package reassignment action
- admin backup bulk delete action with remote-cleanup failure preservation
- admin domain bulk delete action with server-cleanup failure preservation
- admin per-account database bulk delete action with remote-cleanup failure preservation
- remaining user-facing app installer, file manager, domain detail, DNS detail, deliverability, delivery tracking, filters, PHP settings, and no-account pages converted to shared page structure
- reseller branding and package catalog pages converted to shared page structure

### 10. Platform and API Expansion
Status: `in progress`

- richer provisioning API
- event/webhook model
- migration/import API
- package/feature-list API

Implemented on `main`:

- API package and feature-list catalog endpoints for provisioning integrations
- API account list and detail endpoints for provisioning inventory integrations
- repo-local API reference for billing and provisioning integrations
- admin-managed webhook endpoints with HMAC-signed audit-backed lifecycle event delivery
- API migration workflow endpoints with `migrations:read` and `migrations:write` token abilities

## Implementation Order

1. Package and feature-list foundation
2. Package-aware account creation and reseller flows
3. Metrics/log visibility
4. Migration/import tooling
5. Email product depth
6. Files/developer workflows
7. Database expansion
8. Security/product completeness

## Notes

- Feature lists are being added as a data/control primitive first.
- Initial Phase 1 package work should not break existing account creation without packages.
- Existing direct-limit fields remain valid during the transition period.
