# Architecture — ResourceRequest Hub

## Data model

- `users`: credential hashes, name, role, active flag.
- `project_spaces`: project ownership and archival; one owner may have many projects.
- `resource_pools`: named physical/logical resource groups, type, capacity and availability flag.
- `resource_requests`: allocated quantity, pool, project, desired dates, priority and workflow state.
- `request_audit`: permanent trace of each workflow transition and responsible actor.
- `request_comments`: private discussion associated with an authorized request.
- `login_throttles`: rolling failed-login counters.

The SQL schema includes foreign keys, quantity constraints and indexes on high-use filters. **Do not edit the SQL tables directly to approve or release requests**; use the service methods that enforce workflow and capacity rules.

## Application layers

- `public/index.php` handles routing, server-rendered HTML and input/output orchestration.
- `app/Auth.php` authenticates users with password hashes and regenerates session IDs.
- `app/Validation.php` validates request fields independently of HTML constraints.
- `app/RequestService.php` performs scoped queries and transactional workflow actions.
- `app/ResourceService.php` computes allocation availability and restricts resource changes to administrators.
- `app/ProjectService.php` checks project ownership and archiving.
- `app/InsightService.php` groups records for dashboard analytics.

## Allocation safety

Transactions for approve/release lock the affected request record and its **resource pool** using MySQL locking reads. Every operation on a pool must use the same lock order so simultaneous approvals cannot reserve the same remaining units twice. Availability is derived from `SUM(quantity) WHERE status='approved'`. `released` requests do not occupy capacity.

InnoDB's transaction isolation and locking behavior matter for correctness. The integration tests check sequential over-allocation and release; a separate concurrent load test would be required to validate production-scale contention.

## API

Authenticated `GET /api/requests`, `GET /api/requests/{id}`, `GET /api/resources` and `GET /api/insights`. They use PHP session authentication and return JSON. The API is read-only; it is not a public or OAuth-authenticated API.
