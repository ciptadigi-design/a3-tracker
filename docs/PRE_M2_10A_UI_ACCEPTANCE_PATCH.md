# Pre-M2.10A UI Acceptance Patch

## Persistent Branch preference

The active Branch preference uses a durable browser cache keyed by authenticated `user UUID + Account UUID`. It is intentionally separate from session-scoped drafts/UI state, so sign-out clears credentials and sensitive runtime state without erasing this harmless preference.

Restoration occurs only after `loadTenantContext` returns the active, authorized Branch list. Resolution keeps the current valid Branch first, then accepts the stored Branch only when it remains in that list, then uses the first Branch in the existing stable authorized ordering. The accepted fallback is persisted. A created/reordered Branch cannot steal a still-valid selection, an archived/inaccessible/cross-Account value is replaced safely, and the preference never acts as authorization.

This patch does not add a database table or migration. Server-backed cross-device synchronization can be considered later if the product needs it; local persistence is sufficient for the requested browser lifecycle and avoids disproportionate preference-schema/RLS work here.

## Machine Models

Manufacturers and Models now share one compact grid grammar: icon, identity, contextual fact, status, and a fixed action area. Shared masters reserve the action area without exposing controls. Models show their Manufacturer directly. At mobile widths the grid becomes a stacked list item without horizontal scrolling.

The header action follows the selected tab (`Manufacturer` or `Model`), segmented tabs are compact and connected to the dataset, and empty states are explicit. Component Model Profiles is a shorter secondary card with a compact `Manage Profiles` action and an active-slot/across-models metric derived from `machine_model_id`.

Add/Edit dialogs retain `BlockingDialog`, existing spacing/footer conventions, and persistent drafts. No Manufacturer, Machine Model, Model Profile, component, provisioning, inventory, cost, report, role, or migration semantics changed.
