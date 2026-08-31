# M2.11G.1.3 Machine-Specific Catalog Selector UX

The Add Machine-Specific Component dialog now uses one accessible searchable Component Catalog combobox. Search text filters active Catalog definitions by case-insensitive name or code; results present both values and support mouse/touch and Arrow Up/Down, Enter, and Escape keyboard interaction. There is no separate Search field plus Choose dropdown, and typed text alone can never create an assignment.

When no result matches, the selector shows **No matching component found** and an explicit **Create New Component** action. That action reuses the canonical Catalog creation dialog. The selected machine and assignment draft (slot code, tracking method, expected clicks, and notes) are retained; after creation the new Catalog row is selected automatically. Cancelling creation returns to the assignment flow without changing the draft.

Catalog membership does not imply Model Profile membership. Selecting a Catalog item creates neither a Model Profile nor a Profile Slot and does not trigger Sync. Standard components continue to use Catalog → Assign to Model / Profile Slot → Sync; Machine-specific remains the exception-only physical-machine path.

M2.11G.1.2 server-authoritative guardrails remain unchanged: active standard slots, duplicate physical slots, exclusions, archived or cross-account Catalog entries, and invalid configuration are rejected. Counter based remains the only supported new tracking method; Consumption based and Inspection based remain visible but disabled as Coming soon. Detailed Machine Component action-row styling and lifecycle capability logic are unchanged.
