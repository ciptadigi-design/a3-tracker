import { callBackend } from './dataBackend.js'
const adapters = { supabase: async () => ({ load: (args) => import('./supabase/reports.js').then((module) => module.loadOperationalReport(args)) }), laravel: async () => ({ load: (args) => import('../lib/api/reports.js').then((module) => module.laravelReports.load(args)) }) }
export const loadOperationalReport = (args) => callBackend({ domain: 'reports', operation: 'load', args: [args], ...adapters })
