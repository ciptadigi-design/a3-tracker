# Frontend Workflow UI State

## Responsibility

Workflow UI state is separate from both server state and form drafts:

- Supabase remains authoritative and may refetch normally.
- Draft state stores editable, unsubmitted values.
- Workflow UI state records which transient workflow is active, such as an open modal or selected operating context.

An active workflow can therefore remount after navigation or refresh, then let its form restore the matching draft. A refetch does not close the workflow or replace draft values.

## Storage and keys

`src/features/uiState` provides a versioned `sessionStorage` layer and `usePersistentUIState`. Keys follow:

```text
a3tracker:ui-state:<userId>:<accountId>:<branchId|global>:<feature>:<entityId|active>
```

Use branch-scoped records for the actual workflow. A small account-scoped pointer is acceptable when the application must restore the originating branch before reopening that workflow. Values must contain navigation/UI context only, never credentials, server history, or form fields.

Writes are immediate because open, close, and selection events are infrequent and must survive an immediate unmount. Explicit close/cancel and successful submission clear the applicable workflow state. Logout removes only the authenticated user's A3 Tracker UI-state prefix.

## Machine workflow

The machine workflow stores either `create` or `edit` plus the edited machine ID. Add reopens on `/machines`. Edit uses the existing `/machines/:id` detail route; returning to the Machines page resumes that route and opens the shared Edit dialog. The dialog's responsive portal, focus, and scroll-lock behavior remain unchanged.

## URL decision

V1 keeps modal state in `sessionStorage` rather than query parameters. This matches operator-session semantics, avoids making private unfinished workflows bookmarkable/shareable, and requires no router rewrite. URL state would be preferable if deep-linking, cross-tab sharing, browser-history entries for modal open/close, or server-rendered routing becomes a product requirement.

## Future adoption

Future Human Error, Machine Fault, Purchase, Component Replacement, and Maintenance workflows should:

1. Create a fully scoped UI-state key.
2. Store only the active workflow identifier/entity context.
3. Keep editable values in `usePersistentDraft`.
4. Restore UI state first, then let the mounted form restore its draft.
5. Clear UI state on explicit close and successful submission.
6. Verify navigation, refresh, context isolation, logout, and responsive reopening.
