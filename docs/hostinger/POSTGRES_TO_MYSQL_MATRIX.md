# PostgreSQL → MySQL compatibility matrix

| PostgreSQL feature | Classification | Target rule |
|---|---|---|
| UUID / pgcrypto | ADAPT | Preserve stable IDs; use CHAR(36) for migration/phpMyAdmin simplicity; Laravel UUID generation. |
| jsonb, arrays | ADAPT / MOVE | MySQL JSON for metadata; normalize fields used in filters; role arrays become policy sets. |
| enum types | ADAPT | Prefer constrained VARCHAR + CHECK for MariaDB portability. |
| partial/expression indexes | REDESIGN | Generated discriminator columns or normalized flags plus composite indexes. |
| CHECK / generated columns | DIRECT/ADAPT | Require MySQL 8.0.16+; test MariaDB enforcement and syntax. |
| triggers/views | ADAPT | Keep immutable-history guards where valuable; business orchestration moves to services; views for read models. |
| CTE/window functions | DIRECT | Require MySQL 8+; retain query semantics. |
| lateral joins | REDESIGN | Derived tables/correlated subqueries or Laravel query objects. |
| `FOR UPDATE` | DIRECT | InnoDB transactions, deterministic lock order. |
| advisory locks | MOVE | Replace purchase-number advisory lock with unique/idempotency row or MySQL named lock only after testing. |
| SECURITY DEFINER/RLS | MOVE | Sanctum middleware, account/branch resolvers, policies, scoped repositories and FKs. |
| timestamptz / `AT TIME ZONE` | ADAPT | UTC `DATETIME(6)` plus explicit IANA timezone; Carbon computes local inclusive periods. |
| `date_trunc`, intervals | ADAPT | Carbon and MySQL date functions; test DST boundaries. |
| `ON CONFLICT`, `RETURNING` | ADAPT | Unique keys + `ON DUPLICATE KEY UPDATE`; select by UUID after writes. |

Minimum target is MySQL 8.0.34+ with InnoDB (or MariaDB 10.6+ only after a full compatibility suite). MyISAM is a blocker. Money remains DECIMAL, quantities retain exact numeric scale, and all critical invariants remain PK/FK/UNIQUE/NOT NULL/CHECK/index backed.
