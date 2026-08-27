# M2.3D Adaptive Component Intelligence

## Purpose and terminology

M2.3D is advisory intelligence. It never autonomously changes configuration.

- **Baseline expected life** is the human-managed effective workspace/model-profile value.
- **Observed expected life** is the median of usable completed lifecycle outcomes.
- **Recommended expected life** is an advisory, guardrailed value requiring review.
- **Effective expected life** remains the immutable `expected_at_install` snapshot on an installed lifecycle. A baseline adoption affects only future lifecycles.

For consumption-based components the same quantities are described as expected yield, observed yield, and actual yield.

## Grouping and sample eligibility

V1 groups evidence by account, machine model, and normalized logical profile slot. It never combines accounts, models, or different slots. Each diagnostic retains its physical machine so later versions can analyze machine-specific deviation without creating an override today.

The source is immutable M2.3C replacement history. A sample enters statistical analysis only when `include_in_adaptive_learning = true`. Active, unknown, incomplete, legacy, explicitly excluded, and `TEST_COMPONENT` lifecycles do not enter the calculation. Excluded and statistical-outlier samples remain visible as historical facts.

## Robust V1 statistics

`component_adaptive_intelligence` calculates total, eligible, usable, and outlier counts; mean; median; minimum; maximum; sample standard deviation; quartiles; interquartile range; and coefficient of variation (sample standard deviation divided by mean). The median is the observed expected-life estimator because operational replacement datasets are initially small and may contain abnormal early failures. Mean remains visible for diagnosis but is not the recommendation estimator.

Outlier detection is conservative:

- fewer than 4 eligible samples: no statistical exclusion;
- 4 or more with a positive IQR: values below `Q1 - 1.5 × IQR` or above `Q3 + 1.5 × IQR` are potential outliers;
- a zero IQR produces no outlier exclusion.

Diagnostics disclose the rule and reason. Rows are never deleted or modified.

## Deterministic confidence formula

Algorithm version is `v1`. Scores have no randomness or external AI dependency.

Quantity score by usable sample count is: 0→0, 1→10, 2→25, 3→45, 4→55, 5→65, 6→72, 7→78, 8→84, 9→87, 10→90, 11→92, 12→94, 13→96, 14→98, and 15+→100.

Consistency score uses coefficient of variation: fewer than 2 samples→0; ≤5%→100; ≤10%→90; ≤15%→80; ≤25%→65; ≤40%→45; >40%→20.

Quality score is `usable samples / total completed samples × 100`, so ineligible and outlier outcomes reduce evidence quality without disappearing.

The raw score is:

`45% quantity + 40% consistency + 15% quality`

Small-sample maturity caps are then applied: 0→0, 1→24, 2→44, 3–4→64, 5–7→79, 8–14→89, 15+→100.

Labels are: 0/no usable data `No Data`; 1–24 `Very Low`; 25–44 `Low`; 45–64 `Developing`; 65–79 `Medium`; 80–89 `High`; and 90–100 `Mature`. Confidence measures reliability and evidence quality, not agreement with the human baseline.

## Recommendation rules

The machine-readable states are `no_data`, `insufficient_data`, `keep_baseline`, `review_increase`, `review_decrease`, `high_variability`, and `adaptive_disabled`.

- 0 usable samples: no data, no score presentation, and no recommendation.
- 1–2 usable samples: observation only; at least 3 are required for a formal recommendation.
- coefficient of variation above 40%: high variability, with no actionable recommendation.
- within the inclusive ±10% dead band: keep baseline.
- beyond +10%: review increase; beyond −10%: review decrease.

An actionable suggestion is clamped to a maximum one-step change of ±25% from the current baseline. The full observed median remains visible, and the UI explains when the guardrail applies. Profiles with `adaptive_enabled = false` may display their evidence but produce no actionable recommendation and cannot be adopted.

## Human adoption and audit

Only an active owner or admin may call `adopt_component_intelligence_recommendation`. Technician and operator access is read-only. Adoption uses existing workspace override semantics: a shared platform profile is never changed; adoption creates a workspace override when necessary. The existing baseline field remains the single source of truth.

The RPC is idempotent by account and `client_request_id`. It locks the account adoption scope and re-reads the intelligence row. It rejects a stale dialog if the baseline, eligible-sample fingerprint, or algorithm version changed. It also rechecks adaptive status and recommendation actionability.

`component_profile_baseline_revisions` records both manual workspace baseline changes and adopted recommendations. Adaptive revisions preserve the algorithm version, fingerprint, evidence counts, confidence, observed estimate, recommendation, and a JSON intelligence snapshot. Normal clients can read permitted account history but cannot insert, update, or delete it directly.

## Lifecycle immutability

Adoption never updates historical or currently active lifecycle snapshots. The current component continues using the expectation captured when installed. On the next genuine replacement, the M2.3C transaction resolves the effective workspace profile and the new lifecycle snapshots the newly approved baseline. The previous lifecycle and replacement event retain their original profile and expectation facts.

## UI and zero-sample behavior

Model Profiles Detailed mode exposes confidence/sample context and opens a responsive shared `BlockingDialog`. Compact mode retains only a small `Adaptive · n` indicator. The dialog shows baseline, observed median, difference, counts, range, variability, recommendation, deterministic explanation, and recent immutable samples without raw IDs.

With zero real samples it deliberately displays `No Data`, the current baseline, and an explanation that learning starts after eligible real replacements. It does not render zero as a bad confidence score, fabricate an observed value, permit adoption, divide by zero, or create demonstration data.

## Future evolution

Later algorithm versions may add stronger robust estimators, causal reason/condition analysis, machine-deviation detection, and confidence calibration. They must receive a new algorithm version and retain old adoption snapshots. M2.3D does not implement automatic adoption, machine-specific overrides, predictive maintenance, inventory, purchasing, pricing, notifications, ML, or LLM intelligence.
