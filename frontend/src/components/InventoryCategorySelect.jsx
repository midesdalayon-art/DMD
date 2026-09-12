import { createPortal } from 'react-dom'
import { useEffect, useId, useMemo, useRef, useState } from 'react'

function getNextIndex(currentIndex, optionsLength, key) {
  if (optionsLength <= 0) return -1

  if (key === 'Home') {
    return 0
  }

  if (key === 'End') {
    return optionsLength - 1
  }

  if (key === 'ArrowDown') {
    return currentIndex >= optionsLength - 1 ? 0 : currentIndex + 1
  }

  if (key === 'ArrowUp') {
    return currentIndex <= 0 ? optionsLength - 1 : currentIndex - 1
  }

  return currentIndex
}

export default function InventoryCategorySelect({
  value,
  onChange,
  options,
  placeholder = 'Select category',
  allLabel = null,
  ariaLabel = 'Inventory category',
  className = '',
  disabled = false,
}) {
  const id = useId()
  const triggerRef = useRef(null)
  const menuRef = useRef(null)
  const [isOpen, setIsOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(-1)
  const [menuStyle, setMenuStyle] = useState(null)

  const mergedOptions = useMemo(() => {
    const list = []

    if (allLabel !== null) {
      list.push({ value: '', label: allLabel, isClear: true })
    }

    for (const option of options ?? []) {
      if (option?.value) {
        list.push(option)
      }
    }

    return list
  }, [allLabel, options])

  const selectedLabel = useMemo(() => {
    const selectedOption = mergedOptions.find((option) => option.value === value)
    return selectedOption?.label ?? placeholder
  }, [mergedOptions, placeholder, value])

  useEffect(() => {
    if (!isOpen) {
      return undefined
    }

    function updatePosition() {
      const trigger = triggerRef.current
      if (!trigger) return
      const rect = trigger.getBoundingClientRect()
      const availableBelow = Math.max(96, window.innerHeight - rect.bottom - 12)
      const width = Math.min(rect.width, window.innerWidth - 24)
      const left = Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))
      setMenuStyle({
        position: 'fixed',
        top: rect.bottom + 6,
        left,
        width,
        maxHeight: Math.min(280, availableBelow),
        zIndex: 9999,
      })
    }

    updatePosition()
    window.addEventListener('resize', updatePosition)
    window.addEventListener('scroll', updatePosition, true)

    function handlePointerDown(event) {
      const trigger = triggerRef.current
      const menu = menuRef.current
      const target = event.target
      if (trigger?.contains(target) || menu?.contains(target)) {
        return
      }
      setIsOpen(false)
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('touchstart', handlePointerDown)

    return () => {
      window.removeEventListener('resize', updatePosition)
      window.removeEventListener('scroll', updatePosition, true)
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('touchstart', handlePointerDown)
    }
  }, [isOpen])

  useEffect(() => {
    if (!isOpen) {
      setActiveIndex(-1)
      return
    }

    const selectedIndex = mergedOptions.findIndex((option) => option.value === value)
    setActiveIndex(selectedIndex >= 0 ? selectedIndex : 0)
  }, [isOpen, mergedOptions, value])

  function openMenu() {
    if (disabled) return
    setIsOpen(true)
  }

  function closeMenu() {
    setIsOpen(false)
  }

  function handleSelect(optionValue) {
    onChange(optionValue)
    closeMenu()
  }

  function handleTriggerKeyDown(event) {
    if (disabled) return

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Home' || event.key === 'End') {
      event.preventDefault()
      if (!isOpen) {
        openMenu()
        return
      }

      const nextIndex = getNextIndex(activeIndex < 0 ? 0 : activeIndex, mergedOptions.length, event.key)
      if (nextIndex >= 0) {
        setActiveIndex(nextIndex)
      }
      return
    }

    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      if (!isOpen) {
        openMenu()
        return
      }

      if (activeIndex >= 0 && mergedOptions[activeIndex]) {
        handleSelect(mergedOptions[activeIndex].value)
      }
      return
    }

    if (event.key === 'Escape') {
      closeMenu()
    }
  }

  function handleMenuKeyDown(event) {
    if (event.key === 'Escape') {
      event.preventDefault()
      closeMenu()
      triggerRef.current?.focus()
      return
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Home' || event.key === 'End') {
      event.preventDefault()
      setActiveIndex((current) => getNextIndex(current < 0 ? 0 : current, mergedOptions.length, event.key))
      return
    }

    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      if (activeIndex >= 0 && mergedOptions[activeIndex]) {
        handleSelect(mergedOptions[activeIndex].value)
      }
    }
  }

  return (
    <div className={`inventory-category-select ${className}`.trim()}>
      <button
        ref={triggerRef}
        type="button"
        className={`inventory-category-trigger ${isOpen ? 'is-open' : ''}`}
        onClick={() => (isOpen ? closeMenu() : openMenu())}
        onKeyDown={handleTriggerKeyDown}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
        aria-controls={id}
        disabled={disabled}
      >
        <span className={`inventory-category-value ${value ? '' : 'is-placeholder'}`}>{selectedLabel}</span>
        <span className="inventory-category-caret" aria-hidden="true">▾</span>
      </button>

      {isOpen && menuStyle && typeof document !== 'undefined'
        ? createPortal(
          <div
            ref={menuRef}
            id={id}
            className="inventory-category-menu"
            role="listbox"
            tabIndex={-1}
            style={menuStyle}
            aria-label={ariaLabel}
            onKeyDown={handleMenuKeyDown}
          >
            <div className="inventory-category-menu-inner">
              {mergedOptions.map((option, index) => {
                const isSelected = option.value === value
                const isActive = index === activeIndex

                return (
                  <button
                    key={`${option.value || 'clear'}-${option.label}`}
                    type="button"
                    role="option"
                    aria-selected={isSelected}
                    className={`inventory-category-option ${isSelected ? 'is-selected' : ''} ${isActive ? 'is-active' : ''}`}
                    onMouseEnter={() => setActiveIndex(index)}
                    onClick={() => handleSelect(option.value)}
                  >
                    {option.label}
                  </button>
                )
              })}
            </div>
          </div>,
          document.body,
        )
        : null}
    </div>
  )
}
