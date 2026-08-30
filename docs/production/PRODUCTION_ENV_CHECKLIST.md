# Production Environment Checklist

Never copy DEV values blindly and never commit secret values.

| Variable/configuration | Class | Required action |
|---|---|---|
| `VITE_SUPABASE_URL` | PUBLIC_BROWSER / BUILD_TIME | Dedicated Production project URL in separate Vercel project |
| `VITE_SUPABASE_PUBLISHABLE_KEY` | PUBLIC_BROWSER / BUILD_TIME | Production publishable/anon key; not service role |
| `SUPABASE_URL` | EDGE_SERVER_SECRET / RUNTIME | Production Edge Function environment |
| `SUPABASE_ANON_KEY` | EDGE_SERVER_SECRET / RUNTIME | Production Edge Function environment |
| `SUPABASE_SERVICE_ROLE_KEY` | EDGE_SERVER_SECRET / RUNTIME | Supabase-managed/secret only; never browser/build/log |
| `PLATFORM_BOOTSTRAP_USER_ID` | EDGE_SERVER_SECRET / RUNTIME | Exact pre-created Auth UUID approved for one-time bootstrap |
| `PLATFORM_BOOTSTRAP_TOKEN` | EDGE_SERVER_SECRET / RUNTIME | One-time strong secret; rotate/remove after verified bootstrap |
| Database password/private JWT signing material | SECRET | Operator/provider vault only; never Vercel browser vars |

Create intended real Auth users directly; do not migrate historical Operational People or copy DEV-only test accounts. Record username, email, initial-password delivery/rotation, account role, Branch assignment, and separate operational PIC eligibility. Superuser privilege is exact UUID-bound: pre-create the approved user, set the two bootstrap secrets, invoke `bootstrap-platform-superuser` once, verify `platform_superusers` for that UUID, then rotate/remove the token. The function is idempotent at the database boundary and must not reveal whether other identities exist.

Configure Supabase Auth Site URL to the approved Production Vercel URL first. Add only exact required redirect URLs; add the future custom domain after approval. Edge Function CORS must allow the exact deployed Production and later approved custom origin, not a wildcard. Verify password policy, email flow/provider, JWT expiry, and redirect/logout behavior in the Production audit.

No dedicated Production environment currently exists. The present `a3-tracker-dev` Vercel project has only DEV browser variables and must not be promoted. Future DNS steps: add approved Vercel domain, verify ownership, add the requested DNS record, wait for SSL issuance, update Auth redirects and Edge CORS, validate redirects/cookies/login, and rerun smoke. Do not execute these steps in M2.11.
