import { callBackend } from './dataBackend.js'
const adapters = { supabase: () => import('./supabase/clickTargets.js'), laravel: () => import('./laravel/clickTargets.js') }
const invoke = (operation, ...args) => callBackend({ domain: 'click-targets', operation, args, ...adapters })
export const loadClickTargetProjection = (args) => invoke('loadClickTargetProjection', args)
export const saveMachineClickTarget = (args) => invoke('saveMachineClickTarget', args)
export const loadClickTargetHistory = (args) => invoke('loadClickTargetHistory', args)
export const loadCalendarExceptions = (args) => invoke('loadCalendarExceptions', args)
export const createCalendarException = (args) => invoke('createCalendarException', args)
export const removeCalendarException = (args) => invoke('removeCalendarException', args)
