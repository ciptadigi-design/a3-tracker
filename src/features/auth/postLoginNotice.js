// A tiny, best-effort handoff for a one-time notice shown on the next Login render.
// Used when the client proactively ends the current session (e.g. right after the
// user changed their own password) so Login can explain why they landed there
// instead of showing a bare sign-in form. Never carries credentials or tokens.
export const POST_LOGIN_NOTICE_KEY = 'a3-tracker:post-login-notice'

function getStorage() {
  if (typeof window === 'undefined') return null
  try {
    return window.sessionStorage
  } catch {
    return null
  }
}

export function setPostLoginNotice(message) {
  const storage = getStorage()
  if (!storage) return
  try {
    storage.setItem(POST_LOGIN_NOTICE_KEY, message)
  } catch {
    // Notice is a UX nicety only; a stale/unavailable session storage stays silent.
  }
}

export function consumePostLoginNotice() {
  const storage = getStorage()
  if (!storage) return null
  try {
    const message = storage.getItem(POST_LOGIN_NOTICE_KEY)
    if (message) storage.removeItem(POST_LOGIN_NOTICE_KEY)
    return message
  } catch {
    return null
  }
}
