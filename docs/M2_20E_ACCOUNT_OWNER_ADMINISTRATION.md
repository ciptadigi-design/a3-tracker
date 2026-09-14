# M2.20E Account Owner Administration Contract

Account Owner authority is tenant governance. It applies only to the Account
represented by the active membership and never grants Platform authority over a
global user identity, another Account, Platform privilege, or a global catalog.
All backend mutations authorize both an effective capability and the persisted
Account or related resource.

Account Admin authority remains operational. Admin can open the capability-aware
Settings surface for operational people and click-target configuration, but cannot
manage the workspace profile, branches, memberships, account policy, audit history,
or global machine catalogs. Technician and Operator do not receive Settings access.

## Settings domain classification

| Domain | Classification | Effective capability |
| --- | --- | --- |
| Workspace name and default timezone | TENANT_OWNER | `account.manage` |
| Branch create, update, archive, restore | TENANT_OWNER | `branches.manage` |
| Membership provision, role/status, Branch assignments | TENANT_OWNER | `members.manage` |
| Operational people and Branch assignments | TENANT_ADMIN | `operational_people.manage` |
| Operator policy | TENANT_OWNER | `settings.policy.manage` |
| Administrative history | TENANT_OWNER | `audit.view` |
| Machine administration | OUT_OF_SCOPE_SETTINGS | `machines.manage` on Machines |
| Tenant suppliers | OUT_OF_SCOPE_SETTINGS | `suppliers.manage` on Inventory |
| Global Manufacturer and Machine Model catalog | PLATFORM_ONLY | `catalog.global.manage` |
| Platform accounts and privilege | PLATFORM_ONLY | Platform Gate |
| Global email, username, password, user status, shared identity attachment | PLATFORM_ONLY | Platform Gate and identity boundary |
| Support, recovery, infrastructure | OUT_OF_SCOPE | Platform operations |

The Settings payload is capability-shaped. It returns member identity presentation
only to `members.manage`, operational people only to
`operational_people.manage`, and global catalog configuration only to
`catalog.global.manage`. The UI independently filters every section by its effective
capability.

## Final authority matrix

| Action | Platform Superuser | Owner | Admin | Technician | Operator | Capability / authority | Scope | Audited |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| View Settings | Yes | Yes | Yes, operations section | No | No | `settings.view` | selected Account | N/A |
| Update workspace profile | Yes | Yes | No | No | No | `account.manage` | own Account | Yes |
| Create/update/archive Branch | Yes | Yes | No | No | No | `branches.manage` | own Account | Yes |
| Provision fresh member | Yes | Yes | No | No | No | `members.manage` | fresh identity, own Account | Yes |
| Update member role/status | Yes | Yes, tenant roles under M2.20A rules | No | No | No | `members.manage` | own membership | Yes |
| Assign member Branches | Yes | Yes | No | No | No | `members.manage` | own active Branches, atomic | Yes |
| Manage operational people | Yes | Yes | Yes | No | No | `operational_people.manage` | own Account and Branches | Yes |
| Manage account policy | Yes | Yes | No | No | No | `settings.policy.manage` | own Account | Yes |
| Read administrative history | Yes, Platform route | Yes, tenant route | No | No | No | `audit.view` plus route class | own Account | Read only |
| Manage machines | Yes | Yes | Yes | No | No | `machines.manage` | authorized Account/Branch | Yes |
| Manage tenant suppliers | Yes | Yes | Yes | No | No | `suppliers.manage` | global read or own tenant record | Yes |
| Grant Platform privilege | Yes | No | No | No | No | Platform Gate | global | Yes |
| Mutate global identity | Yes | No | No | No | No | Platform Gate / M2.20A | global | Yes |
| Attach an existing shared identity | Yes, explicit path | No | No | No | No | Platform Gate | explicit Account target | Yes |
| Manage global catalogs | Yes | No | No | No | No | `catalog.global.manage` plus Platform Gate | global | Yes |

The M2.20A rule remains unchanged: tenant Owners cannot promote a membership to
Owner or silently attach an existing identity. Platform performs those exceptional
identity/governance operations explicitly. Last active Owner protection remains a
transactional backend invariant.

No schema change is required. Zero-Branch Accounts remain real empty tenants:
Owners can load the tenant context and Settings, create the first Branch, and then
provision operational people and members. Overview, Machines, and Inventory render
setup-safe empty states while no Branch exists.
