import { useState } from "react";
import { Boxes, LoaderCircle, X } from "lucide-react";
import { BlockingDialog } from "../../components/ui/BlockingDialog.jsx";

export function MachineComponentDialog({
  machine,
  components,
  onClose,
  onSave,
}) {
  const [value, setValue] = useState({
    componentId: "",
    slotCode: "",
    trackingMethod: "counter_based",
    baselineExpectedClicks: "",
    notes: "",
    clientRequestId: crypto.randomUUID(),
  });
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const change = (field, next) =>
    setValue((current) => ({ ...current, [field]: next }));
  async function submit(event) {
    event.preventDefault();
    if (
      !value.componentId ||
      !value.slotCode.trim() ||
      (value.baselineExpectedClicks !== "" &&
        Number(value.baselineExpectedClicks) <= 0)
    )
      return setError(
        "Choose a component and enter a unique slot code with a valid expected-click value.",
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
            <h2 id="machine-component-dialog-title">Add Machine Component</h2>
            <p>
              {machine.machine_code} · This does not change its Model Profile.
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
            <label className="form-field">
              <span>Component *</span>
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
                {components
                  .filter((item) => item.is_active)
                  .map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name}
                    </option>
                  ))}
              </select>
            </label>
            <label className="form-field">
              <span>Slot code *</span>
              <input
                value={value.slotCode}
                onChange={(event) => change("slotCode", event.target.value)}
                placeholder="CLEANING_ROLLER"
              />
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
                <option value="consumption_based">Consumption based</option>
                <option value="inspection_based">Inspection based</option>
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
            {busy && <LoaderCircle className="spin" size={16} />}Add to Machine
          </button>
        </footer>
      </form>
    </BlockingDialog>
  );
}
