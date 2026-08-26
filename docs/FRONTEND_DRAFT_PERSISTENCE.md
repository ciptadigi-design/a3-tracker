# Frontend Draft Persistence Standard

## Purpose

Supabase data and unsaved form input have different lifecycles. Server queries may refetch whenever freshness requires it; a refetch must never reinitialize an active operator draft. Forms hydrate once from a scoped browser draft when one exists and otherwise use current server data or defaults.

Drafts are frontend-only. Restoring or updating a draft must not write to Supabase.

## Storage and keys

Drafts use `sessionStorage`. This survives SPA navigation, component remounts, and refreshes in the current tab, while allowing the browser session to discard stale data on shared devices.

Use `createDraftKey` from `src/features/drafts/draftKeys.js`:

```text
a3tracker:draft:<userId>:<accountId>:<branchId|global>:<feature>:<entityId|new>
```

Every applicable user, account, branch, feature, and entity must be represented. Never reuse a key across tenants or entities. Stored records use a versioned envelope containing `value`, optional `metadata`, and `savedAt`. Do not store derived values, server history, credentials, or data that can be refetched.

Current examples:

```text
a3tracker:draft:<user>:<account>:<branch>:daily-counter:<machineId>
a3tracker:draft:<user>:<account>:<branch>:machine:new
a3tracker:draft:<user>:<account>:<branch>:machine:<machineId>
```

## Form lifecycle

Use `usePersistentDraft` from `src/features/drafts/usePersistentDraft.js` with a stable `draftKey` for the mounted form:

```jsx
const draft = usePersistentDraft({
  draftKey,
  initialValue: valuesFromServerOrDefaults,
  metadata: { baseUpdatedAt: entity.updated_at },
  validate: isValidDraftShape,
})
```

The hook returns:

- `value` and `updateDraft` for controlled fields.
- `hasDraft` and `wasRestored` for reset/status UI.
- `clearDraft` after a successful submission.
- `resetDraft` for an explicit operator reset.
- `pendingDraft`, `restorePendingDraft`, and `discardPendingDraft` for conflicts.

Updates are debounced during normal typing and flushed when the component unmounts. Once hydrated, server rerenders and refetches do not replace hook state. Successful submission must call `clearDraft`; validation errors and failed submissions must retain the draft. Closing a form or navigating does not clear it.

## Edit conflicts

Edit forms should save the server entity's `updated_at` as `metadata.baseUpdatedAt`. On a later mount, compare it with the current server value in `shouldRestore`. If they differ, defer restoration and require an explicit choice between latest server data and the complete saved draft. Do not silently merge fields.

If an entity has no reliable revision or `updated_at` field, document that limitation in the feature and restore the complete draft without claiming conflict detection. Do not add a migration solely for frontend draft persistence.

## Logout

`clearDraftsForUser(userId)` removes only standardized `a3tracker:draft:<userId>:` records (plus supported legacy Daily keys during migration). Logout invokes this after Supabase sign-out succeeds. It must not clear unrelated `sessionStorage` data or another user's drafts.

## Adopting the standard

For each future form:

1. Define a feature name and entity identifier (`new` for create forms).
2. Build a user/account/branch-scoped key with `createDraftKey`.
3. Define an initial value containing editable fields only and validate the stored shape.
4. Bind controlled fields to `value` and `updateDraft`.
5. Show subtle restored and reset affordances.
6. Clear only after the server mutation succeeds.
7. Add `baseUpdatedAt` conflict handling for edit forms when available.
8. Test navigation, remount, refresh, entity isolation, reset, successful submission, and logout.
