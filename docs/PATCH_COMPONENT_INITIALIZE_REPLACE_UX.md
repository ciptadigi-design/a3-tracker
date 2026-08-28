# Component Initialize vs Replace UX Patch

## Operator semantics

**Initialize** starts lifecycle tracking for a component that is already physically installed. It creates the lifecycle baseline only. It does not install a new component, issue Inventory, create a replacement event, or create FIFO consumption cost.

**Replace** records physically removing the active component and installing another component. Every active lifecycle exposes Replace regardless of Healthy, Watch, Overdue, or remaining percentage. Health remains advisory and does not authorize or block operational replacement.

## Replacement sources

Inventory is the default when a replacement draft has no explicit valid source. A persisted External / Untracked choice is preserved. Inventory selection continues to show only items explicitly linked by `component_id`, physical location availability, quantity, and after-stock preview. Both the UI and the database reject insufficient stock.

External / Untracked remains an exception path. It requires a reason, creates no Inventory movement, and warns that acquisition cost may remain unknown.

## Existing workflow and safety

The patch reuses the existing BlockingDialog, lifecycle-scoped drafts, replacement RPC, atomic lifecycle transition, Inventory Issue, FIFO allocation, history, permissions, and cost evidence. Initialization and replacement retain separate draft keys. No database migration or Machine Cost formula change is included.

## Manual acceptance

- UNKNOWN: Initialize is visible, Replace is absent, explanatory copy is clear, and no Inventory controls appear.
- ACTIVE Healthy, Watch, and Overdue: Replace is visible in detailed and compact cards.
- Inventory replacement: linked item, location, availability, quantity, after-stock, counter, PIC, reason, condition, and notes remain available. Do not submit against hosted DEV without an operational reason.
- External / Untracked: warning is visible, Inventory controls are hidden, and reason is required.
- Draft: switch route/tab and confirm the lifecycle-scoped replacement draft returns unchanged.
- Check compact cards and dialogs at 1440, 1366, 1024, 820, 430, and 390 pixels in light and dark themes.
