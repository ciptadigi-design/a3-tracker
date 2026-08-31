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
  const [catalogOpen, setCatalogOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const visibleComponents = components
    .filter((item) => item.is_active)
    .filter((item) => `${item.name} ${item.code}`.toLowerCase().includes(search.trim().toLowerCase()));
  const standardSlot = profiles.find((profile) => profile.is_active && profile.machine_model_id === machine.machine_model_id && profile.slot_code?.trim().toUpperCase() === value.slotCode.trim().toUpperCase());
  const normalizedSlot = value.slotCode.trim().toUpperCase();
  const selectedComponent = components.find((item) => item.id === value.componentId);
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
  function selectCatalogComponent(component) {
    setValue((current) => ({ ...current, componentId: component.id, slotCode: current.slotCode || component.code || "", trackingMethod: component.default_tracking_method || current.trackingMethod }));
    setSearch("");
    setCatalogOpen(false);
    setActiveIndex(0);
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
            <label className="form-field form-field-wide catalog-combobox-field">
              <span id="machine-component-catalog-label">Component Catalog *</span>
              <div className="catalog-combobox">
                <input
                  data-dialog-initial-focus
                  role="combobox"
                  aria-label="Component Catalog"
                  aria-labelledby="machine-component-catalog-label"
                  aria-expanded={catalogOpen}
                  aria-controls="machine-component-catalog-options"
                  aria-autocomplete="list"
                  aria-activedescendant={catalogOpen && visibleComponents[activeIndex] ? `catalog-option-${visibleComponents[activeIndex].id}` : undefined}
                  value={catalogOpen ? search : selectedComponent ? `${selectedComponent.name} · ${selectedComponent.code}` : search}
                  placeholder="Search or choose component…"
                  onFocus={() => setCatalogOpen(true)}
                  onChange={(event) => { setSearch(event.target.value); setCatalogOpen(true); setActiveIndex(0); if (selectedComponent) setValue((current) => ({ ...current, componentId: "" })); }}
                  onKeyDown={(event) => {
                    if (event.key === "ArrowDown") { event.preventDefault(); setCatalogOpen(true); setActiveIndex((current) => Math.min(current + 1, Math.max(visibleComponents.length - 1, 0))); }
                    else if (event.key === "ArrowUp") { event.preventDefault(); setActiveIndex((current) => Math.max(current - 1, 0)); }
                    else if (event.key === "Enter" && catalogOpen && visibleComponents[activeIndex]) { event.preventDefault(); selectCatalogComponent(visibleComponents[activeIndex]); }
                    else if (event.key === "Escape") { setCatalogOpen(false); }
                  }}
                />
                {catalogOpen && <div id="machine-component-catalog-options" className="catalog-combobox-options" role="listbox">
                  {visibleComponents.map((item, index) => <button id={`catalog-option-${item.id}`} className={index === activeIndex ? "catalog-combobox-option active" : "catalog-combobox-option"} role="option" aria-selected={item.id === value.componentId} type="button" key={item.id} onMouseDown={(event) => event.preventDefault()} onClick={() => selectCatalogComponent(item)}><strong>{item.name}</strong><code>{item.code}</code></button>)}
                  {!visibleComponents.length && <div className="catalog-combobox-empty" role="status"><span>No matching component found.</span><button type="button" className="secondary-button catalog-create-inline" onMouseDown={(event) => event.preventDefault()} onClick={() => onCreateNewComponent?.(value)} disabled={busy}>+ Create New Component</button></div>}
                </div>}
              </div>
              {!selectedComponent && !catalogOpen && <small className="field-hint">Select an existing Component Catalog item. Machine-specific components apply only to this physical machine.</small>}
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
