# Pre-M2.8 Machine Master Governance

Manufacturer and Machine Model keep the established safe scope: an account may own canonical masters that are reused by every Branch in that account, while platform-shared seed masters remain readable and immutable from the account UI. Neither master is Branch-owned. This avoids a destructive scope rewrite while supporting consistent multi-Branch machine onboarding.

Only an explicit active Platform Superuser may create, edit, archive, or restore these masters. Owner, Admin, Technician, and Operator membership does not confer this authority. Physical Machine creation retains its existing Owner/Admin and authorized-Branch contract.

Manufacturer names are unique after trimming and case normalization within their scope. Model names are unique after the same normalization within one Manufacturer. A Manufacturer cannot be archived while it has active Models. An archived Model remains resolvable by historical Machines and Model Profiles but cannot be selected for a new Machine.

Add Machine selects active Manufacturer and Model records; it never creates free-text masters. Model Profile and Machine Component resolution remains keyed by the exact Machine Model UUID. A new model with no configured profiles therefore starts at zero profiles and creates no component, lifecycle, inventory, cost, or other operational evidence.

The migration and automated fixtures do not add Xerox or Versant data to hosted DEV. Those records are created only when a Platform Superuser deliberately enters real master data through Settings → Machine Models.
