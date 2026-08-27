# A3 Tracker Blocking Modal Standard

All active V2 blocking workflows use `BlockingDialog` from `src/components/ui/BlockingDialog.jsx`. Feature dialogs keep their own visual classes and business logic; the shared shell owns interaction behavior.

## Portal and backdrop

- Every blocking dialog is portaled directly to `document.body` so application-shell stacking contexts cannot constrain its overlay.
- The fixed, viewport-level backdrop intercepts pointer input. A click on the backdrop closes the topmost dialog when the operation is not busy; it never triggers a destructive action.
- Clicks inside the dialog do not close it. Background elements never receive backdrop clicks.
- The application root and other body siblings are temporarily marked `inert`. Their previous inert state is restored when the dialog unmounts.

## Keyboard and focus

- Opening a dialog moves focus to its first enabled control, or to the dialog container when no control is available.
- Tab and Shift+Tab wrap inside the topmost dialog.
- Escape closes only the topmost dialog and only when its `busy` flag is false. Escape never submits, retires, solves, deletes, archives, corrects, or voids a record.
- Closing restores focus to the element that opened the dialog when that element still exists.
- Dialogs provide `role="dialog"` or `role="alertdialog"`, `aria-modal="true"`, and an accessible title reference. Close buttons retain explicit accessible labels.

## Scroll behavior

- Both `html` and `body` scrolling are locked while a blocking dialog is open.
- Desktop scrollbar width is compensated to avoid a horizontal layout jump.
- Original overflow and padding styles are restored on unmount, preserving the page scroll position.
- Feature-specific responsive classes continue to control desktop bounds, tablet adaptation, mobile `100dvh` layout, safe-area padding, and internal form scrolling.

## Forms, confirmations, and drafts

- Standard form dialogs and destructive confirmations may close from the backdrop, Escape, their close button, or Cancel while no submission is in progress.
- Destructive work requires its explicit action button. Backdrop and Escape always mean cancel.
- A busy dialog cannot close from Escape or the backdrop, and its visible close/cancel controls remain disabled by the feature.
- Closing a Component or Profile form clears its active workflow state but does not clear the persistent entity-scoped draft. Successful Save clears that draft; explicit Reset follows the feature's existing policy.

Future Component Lifecycle, Replacement, Inventory, Purchase, Maintenance, and Technical Fault dialogs must use this shared shell instead of implementing their own portal, focus trap, inertness, Escape listener, or scroll lock.
