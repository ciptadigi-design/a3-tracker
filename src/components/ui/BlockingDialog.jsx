import { useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'

const modalStack = []
const focusableSelector = 'button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [href], [tabindex]:not([tabindex="-1"])'

function isTopmost(token) {
  return modalStack.at(-1) === token
}

export function BlockingDialog({
  children,
  className,
  labelledBy,
  describedBy,
  onClose,
  busy = false,
  role = 'dialog',
  backdropClassName = '',
  closeOnBackdrop = true,
}) {
  const backdropRef = useRef(null)
  const dialogRef = useRef(null)
  const tokenRef = useRef(Symbol('blocking-dialog'))
  const busyRef = useRef(busy)
  const onCloseRef = useRef(onClose)

  useEffect(() => { busyRef.current = busy }, [busy])
  useEffect(() => { onCloseRef.current = onClose }, [onClose])

  useEffect(() => {
    const token = tokenRef.current
    const previouslyFocused = document.activeElement
    const previousRootOverflow = document.documentElement.style.overflow
    const previousBodyOverflow = document.body.style.overflow
    const previousBodyPaddingRight = document.body.style.paddingRight
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth
    const bodyPaddingRight = Number.parseFloat(window.getComputedStyle(document.body).paddingRight) || 0
    const backgroundState = [...document.body.children]
      .filter((element) => element instanceof HTMLElement && element !== backdropRef.current)
      .map((element) => ({ element, wasInert: element.inert, hadInertAttribute: element.hasAttribute('inert') }))

    modalStack.push(token)
    for (const { element } of backgroundState) element.inert = true
    document.documentElement.style.overflow = 'hidden'
    document.body.style.overflow = 'hidden'
    if (scrollbarWidth > 0) document.body.style.paddingRight = `${bodyPaddingRight + scrollbarWidth}px`

    const initialFocus = dialogRef.current?.querySelector('[data-dialog-initial-focus], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled)')
    ;(initialFocus ?? dialogRef.current)?.focus()

    function handleKeyDown(event) {
      if (!isTopmost(token)) return
      if (event.key === 'Escape' && !busyRef.current) {
        if (event.target instanceof HTMLElement && event.target.getAttribute('role') === 'combobox' && event.target.getAttribute('aria-expanded') === 'true') return
        event.preventDefault()
        event.stopImmediatePropagation()
        onCloseRef.current()
        return
      }
      if (event.key !== 'Tab') return

      const focusableElements = [...(dialogRef.current?.querySelectorAll(focusableSelector) ?? [])]
      if (!focusableElements.length) {
        event.preventDefault()
        dialogRef.current?.focus()
        return
      }
      const firstElement = focusableElements[0]
      const lastElement = focusableElements.at(-1)
      if (!dialogRef.current?.contains(document.activeElement)) {
        event.preventDefault()
        ;(event.shiftKey ? lastElement : firstElement).focus()
      } else if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault()
        lastElement.focus()
      } else if (!event.shiftKey && document.activeElement === lastElement) {
        event.preventDefault()
        firstElement.focus()
      }
    }

    window.addEventListener('keydown', handleKeyDown, true)
    return () => {
      window.removeEventListener('keydown', handleKeyDown, true)
      const stackIndex = modalStack.lastIndexOf(token)
      if (stackIndex >= 0) modalStack.splice(stackIndex, 1)
      for (const { element, wasInert, hadInertAttribute } of backgroundState) {
        element.inert = wasInert
        if (!hadInertAttribute && !wasInert) element.removeAttribute('inert')
      }
      document.documentElement.style.overflow = previousRootOverflow
      document.body.style.overflow = previousBodyOverflow
      document.body.style.paddingRight = previousBodyPaddingRight
      if (previouslyFocused instanceof HTMLElement && previouslyFocused.isConnected) previouslyFocused.focus()
    }
  }, [])

  function handleBackdropClick(event) {
    if (event.target !== event.currentTarget || !isTopmost(tokenRef.current)) return
    event.preventDefault()
    event.stopPropagation()
    if (closeOnBackdrop && !busy) onClose()
  }

  return createPortal(
    <div ref={backdropRef} className={`dialog-backdrop ${backdropClassName}`.trim()} role="presentation" onClick={handleBackdropClick}>
      <section ref={dialogRef} className={className} role={role} aria-modal="true" aria-labelledby={labelledBy} aria-describedby={describedBy} tabIndex={-1}>
        {children}
      </section>
    </div>,
    document.body,
  )
}
