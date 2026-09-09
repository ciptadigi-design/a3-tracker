// M2.13 click targets were introduced Laravel-first, alongside the ongoing
// Supabase -> Laravel cutover. No Supabase-backed implementation exists;
// callBackend surfaces a clean "not implemented for backend supabase" error
// for any operation invoked here rather than silently no-op-ing.
export {}
