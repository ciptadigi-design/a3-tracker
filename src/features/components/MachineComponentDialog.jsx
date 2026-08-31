import { useState } from "react";
import { Boxes, LoaderCircle, X } from "lucide-react";
import { BlockingDialog } from "../../components/ui/BlockingDialog.jsx";

export function MachineComponentDialog({
  machine,
  components,
  onClose,
  onSave,
  onCreateNewComponent,
  initialValue,
  profiles = [],
  existingAssignments = [],
  exclusions = [],
}) {
  const [value, setValue] = useState({
    componentId: initialValue?.componentId ?? "",
    slotCode: "",
    trackingMethod: "counter_based",
    baselineExpectedClicks: "",
    notes: "",
    clientRequestId: crypto.randomUUID(),
    ...initialValue,
  });
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const [search, setSearch] = useState("");
  const visibleComponents = components
    .filter((item) => item.is_active)
    .filter((item) => `${item.name} ${item.code}`.toLowerCase().includes(search.trim().toLowerCase()));
  const standardSlot = profiles.find((profile) => profile.is_active && profile.machine_model_id === machine.machine_model_id && profile.slot_code?.trim().toUpperCase() === value.slotCode.trim().toUpperCase());
  const normalizedSlot = value.slotCode.trim().toUpperCase();
  const existingAssignment = existingAssignments.find((item) => item.assignment_status !== 'retired' && item.slot_code?.trim().toUpperCase() === normalizedSlot);
  const excludedSlot = exclusions.find((item) => item.slot_code?.trim().toUpperCase() === normalizedSlot);
  const change = (field, next) =>
    setValue((current) => ({ ...current, [field]: next }));
  async function submit(event) {
    event.preventDefault();
    if (
      !value.componentId ||
      !value.slotCode.trim() ||
      standardSlot || existingAssignment || excludedSlot ||
      (value.baselineExpectedClicks !== "" &&
        Number(value.baselineExpectedClicks) <= 0)
    )
      return setError(
        standardSlot ? "This slot is already standard in the active Model Profile. Use Sync Model Profile instead." : existingAssignment ? `This machine already has a component assigned to slot ${normalizedSlot}.` : excludedSlot ? "This standard slot is currently excluded from this machine. Restore the Model Profile assignment instead." : "Choose a component and enter a unique slot code with a valid expected-click value.",
      );
    setBusy(true);
    setError(null);
    try {
      await onSave(value);
      onClose();
    } catch (saveError) {
      setError(saveError.message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <BlockingDialog
      className="machine-dialog component-dialog glass-surface"
      backdropClassName="machine-dialog-backdrop"
      labelledBy="machine-component-dialog-title"
      onClose={onClose}
      busy={busy}
    >
      <header className="dialog-header">
        <div className="dialog-heading">
          <span className="dialog-icon">
            <Boxes size={22} />
          </span>
          <div>
            <span className="card-kicker">Machine-specific configuration</span>
            <h2 id="machine-component-dialog-title">Add Machine-Specific Component</h2>
            <p>
              {machine.machine_code} · Machine-specific components still use Component Catalog as the master part definition. Standard components belong in Model Profiles; use Model Profiles for components that are standard across this machine model.
            </p>
          </div>
        </div>
        <button
          className="icon-button"
          type="button"
          onClick={onClose}
          disabled={busy}
          aria-label="Close machine component form"
        >
          <X size={19} />
        </button>
      </header>
      <form className="machine-form" onSubmit={submit}>
        <div className="machine-form-body">
          <div className="form-grid">
            <label className="form-field form-field-wide">
              <span>Select a component from Component Catalog *</span>
              <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search component name or code…" aria-label="Search Component Catalog" />
              <select
                data-dialog-initial-focus
                value={value.componentId}
                onChange={(event) => {
                  const component = components.find(
                    (item) => item.id === event.target.value,
                  );
                  setValue((current) => ({
                    ...current,
                    componentId: event.target.value,
                    slotCode: current.slotCode || component?.code || "",
                    trackingMethod:
                      component?.default_tracking_method ||
                      current.trackingMethod,
                  }));
                }}
              >
                <option value="">Choose component</option>
                {visibleComponents.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name} · {item.code}
                    </option>
                ))}
              </select>
              {!visibleComponents.length && <button type="button" className="secondary-button catalog-create-inline" onClick={() => onCreateNewComponent?.(value)} disabled={busy}>+ Create New Component</button>}
              {visibleComponents.length > 0 && !value.componentId && <small className="field-hint">Search by component name or code, then choose an existing Catalog item.</small>}
            </label>
            <label className="form-field">
              <span>Slot code *</span>
              <input
                value={value.slotCode}
                onChange={(event) => change("slotCode", event.target.value)}
                placeholder="CLEANING_ROLLER"
              />
              {standardSlot && <div className="form-error" role="alert">Already standard for {machine.machine_model_name ?? machine.model?.name ?? 'this machine model'} · slot {standardSlot.slot_code}. Use Sync Model Profile instead of adding it as Machine-specific.</div>}
            </label>
            <label className="form-field">
              <span>Tracking method</span>
              <select
                value={value.trackingMethod}
                onChange={(event) =>
                  change("trackingMethod", event.target.value)
                }
              >
                <option value="counter_based">Counter based</option>
                <option value="consumption_based" disabled>Consumption based (Coming soon)</option>
                <option value="inspection_based" disabled>Inspection based (Coming soon)</option>
              </select>
            </label>
            <label className="form-field">
              <span>Expected clicks</span>
              <input
                type="number"
                min="1"
                value={value.baselineExpectedClicks}
                onChange={(event) =>
                  change("baselineExpectedClicks", event.target.value)
                }
              />
            </label>
          </div>
          <label className="form-field">
            <span>Notes</span>
            <textarea
              rows="3"
              value={value.notes}
              onChange={(event) => change("notes", event.target.value)}
            />
          </label>
          {error && <div className="form-error">{error}</div>}
        </div>
        <footer className="dialog-actions">
          <button
            className="secondary-button"
            type="button"
            onClick={onClose}
            disabled={busy}
          >
            Cancel
          </button>
          <button className="primary-button" disabled={busy}>
            {busy && <LoaderCircle className="spin" size={16} />}Add Machine-Specific Component
          </button>
        </footer>
      </form>
    </BlockingDialog>
  );
}
