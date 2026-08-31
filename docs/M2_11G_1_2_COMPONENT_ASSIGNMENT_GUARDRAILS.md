# M2.11G.1.2 Component Assignment Guardrails

Component Catalog is the canonical reusable master definition. Every new machine-specific assignment must reference an existing active Catalog row; the machine flow never creates an implicit definition. The dialog searches Catalog by name/code and offers **Create New Component**, which reuses the canonical Catalog dialog and returns to the assignment with the new row selected while retaining the draft.

The standard path remains **Catalog → Assign to Model → Model Profile Slot → Sync Model Profile**. Machine-specific assignment is an exception path only. Eligibility is slot-based: account, active machine/model, normalized durable `slot_code`, Catalog visibility, active Model Profile slots, exclusions, and existing active machine assignments are considered. A Catalog component being present alone is never treated as standard, so the same logical component may occupy multiple distinct valid slots.

Manual creation is rejected with actionable conflict reasons for `COMPONENT_NOT_FOUND`, `STANDARD_PROFILE_SLOT`, `PROFILE_SLOT_EXCLUDED`, and `EXISTING_MACHINE_ASSIGNMENT`. Inherited and manual rows therefore cannot be shadowed or duplicated, and an excluded standard slot cannot be bypassed. Archived Catalog rows are not assignable. Shared/account-scoped visibility and authorization remain enforced in both Laravel and the Supabase reference RPC.

Ordinary Sync remains non-reconciling. The explicit **Reconcile with Model Profile** operation is unchanged and preserves assignment UUIDs and lifecycle/replacement evidence. No existing rows are migrated, deleted, reconciled, or otherwise changed; Graha hosted data is untouched.

The Detailed Machine Components cards keep Initialize Lifecycle and Remove from Machine in a shared, bottom-aligned horizontal action row at normal widths, with consistent button typography/dimensions. Narrow mobile layouts may wrap them responsively; lifecycle and reconciliation capability rules are unchanged.

Validation performed for this patch: focused component UX tests, production build, full Laravel suite, chronologically-sequenced reconciliation fixtures, selected Supabase pgTAP/reference tests, PHP formatting, and `git diff --check`. No hosted database was mutated locally.
