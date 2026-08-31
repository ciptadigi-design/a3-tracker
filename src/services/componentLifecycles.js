import { callBackend } from './dataBackend.js'
const adapters = { supabase: () => import('./supabase/componentLifecycles.js'), laravel: () => import('./laravel/componentLifecycles.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'component-lifecycles', operation, args, ...adapters })
export const loadMachineComponentLifecycles = (args) => invoke('loadMachineComponentLifecycles', args)
export const initializeComponentLifecycle = (args) => invoke('initializeComponentLifecycle', args)
export const replaceComponentLifecycle = (args) => invoke('replaceComponentLifecycle', args)
