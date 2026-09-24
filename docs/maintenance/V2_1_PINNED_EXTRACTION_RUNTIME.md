# Maintenance V2.1 Pinned Extraction Runtime

The reviewed full-manual extractor uses only the configured absolute
`MAINTENANCE_OFFICIAL_PYTHON_EXECUTABLE`. It does not search `PATH`, fall back
to `python3`, install dependencies, or alter the source PDF. The runtime
contract is Python 3.11.x with exactly `pypdf==6.14.2`. Because the verified
source PDF uses AES encryption, the private runtime also pins the minimal
cryptography provider closure (`cryptography==50.0.1`, `cffi==2.1.1`, and
`pycparser==3.0`); page scope 1412–1670,
`PdfReader`, `extract_text(extraction_mode="layout")`, normalization, identity,
and dataset digest semantics remain unchanged.

Production uses the private non-web runtime identity
`<app-root>/runtimes/official-knowledge/python311-pypdf-6.14.2-crypto-50.0.1-v1`. The base
interpreter `/opt/alt/python311/bin/python3` is used only to create the private
virtual environment. The hash-pinned input is
`backend/runtime/official-knowledge/requirements.txt`. Provisioning and
verification are defined by
`scripts/deployment/provision-official-python-runtime.sh` and
`scripts/deployment/verify-official-python-runtime.sh`; the canonical order is
documented in `docs/production/RELEASE_PROCEDURE.md`.

Application preflight rejects absent, relative, non-regular, or non-executable
paths before starting a process. It then runs the configured interpreter in
isolated mode and requires Python 3.11.x, pypdf 6.14.2, and cryptography 50.0.1
exactly. Extraction
uses the same isolated executable and retains the reviewed 120-second timeout.
Failures report sanitized executable identity, exit code/text, stdout/stderr
byte counts, signal, timeout, and elapsed time without extracted manual text,
environment contents, or source paths.

Production apply remains separately authorization-gated. Provisioning this
runtime, running its preflight, or performing a read-only preview never
authorizes official knowledge writes.
