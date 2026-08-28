# Post-M2.5A Acceptance Patch

## Acceptance findings

Real DEV acceptance proved the complete replacement transaction: an Inventory-backed replacement reduced stock, created the issue and FIFO evidence, transitioned the lifecycle, and appeared in Machine Cost. The acceptance transaction is retained unchanged.

The follow-up audit found three presentation and workflow issues:

- replacement was using account-member profiles for PIC instead of the Daily Counter operational-people list;
- `machine_component_health` intentionally exposes lifecycle history, but the Components projection rendered a closed row as an UNKNOWN card beside its active successor;
- Machine Cost represented incomplete counter evidence and unknown acquisition cost defensively, but its labels could be read as zero cost.

## Replacement modal

The existing BlockingDialog, lifecycle draft, replacement RPC, Inventory validation, and FIFO transaction remain in place. The modal now groups current state, replacement information, Inventory, learning, and notes. Large physical counters are formatted for reading while the draft and RPC payload remain canonical numbers.

PIC follows the Daily Counter model: active operational people are primary and Manual PIC is an explicit fallback. Manual name is shown only for the fallback. A saved inactive or missing person remains visible as a stale selection and must be reselected. A forward migration extends the existing RPC parameter compatibly so an unlinked operational person can be referenced directly while preserving the immutable name snapshot; legacy member-user calls continue to work.

Inventory remains the default unless a valid lifecycle-scoped draft says otherwise. Only explicitly component-linked items are eligible. Location stock, quantity, and after-stock are previews; database validation remains authoritative. External / Untracked remains an exception, requires a reason, creates no stock movement, and may leave acquisition cost unknown.

## Logical component card identity

A current card is identified by machine plus normalized logical slot code. The page now requests and projects only open lifecycle states (`unknown` or `active`), groups defensively by that identity, and gives ACTIVE precedence over UNKNOWN. Closed lifecycles remain in Replacement/Lifecycle History and analytical evidence but never produce standalone current cards.

The database already enforces one open lifecycle per machine and normalized slot. No card-integrity migration or historical cleanup was required. Replacing a component continues to close one lifecycle and open its successor in the same slot.

## Machine Cost explanation model

The backend period engine and formulas are unchanged. Presentation is derived from its existing counter and cost statuses:

- missing start, end, or both boundaries is explained explicitly; no historical clicks are invented;
- zero clicks is distinct from missing evidence;
- unknown-only consumption displays an unknown cost basis, never a zero-cost implication;
- mixed totals and composition state the known monetary amount and unknown event count;
- Purchase Cost is labeled account context and explicitly excluded from machine cost/click until consumption;
- Ending Inventory Cost Basis is qualified as known when unknown-cost quantity exists, with known and unknown quantities shown separately.

## Manual acceptance

1. Open Replace / Refill for an active component. Confirm section hierarchy, formatted counter, operational PIC selector, Manual PIC fallback, Inventory default, linked-item filtering, stock preview, and concise External warning. Cancel without submitting.
2. Return to the machine with the existing Toner Cyan acceptance replacement. Confirm exactly one current Toner Cyan card and confirm the closed lifecycle remains in history.
3. Confirm a genuinely uninitialized slot shows UNKNOWN/Initialize and an active slot shows Replace.
4. Open Machine Cost for the current month. Confirm missing boundary evidence explains the unavailable click total, unknown-only toner consumption does not look like zero cost, Purchase Cost is contextual, and known/unknown ending stock quantities are distinct.
5. Check the modal and Machine Cost at 1440, 1366, 1024, 820, 430, and 390 widths in light and dark themes. Confirm keyboard focus, Escape, focus restore, disabled/busy states, and no overflow.

Do not submit another replacement or invent counters merely for acceptance.
