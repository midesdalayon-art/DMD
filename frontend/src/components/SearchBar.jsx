import { useNavigate } from 'react-router-dom'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useBookingSearch } from '../context/BookingSearchContext'
import { useResortBranding } from '../hooks/useResortBranding'

const fieldConfig = [
  { id: 'check_in', label: 'Check-in' },
  { id: 'check_out', label: 'Check-out' },
  { id: 'guests', label: 'Guests' },
]

const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const guestCategories = [
  ['adults', 'Adults', 'Ages 13+', 1],
  ['children', 'Children', 'Ages 2-12', 0],
  ['infants', 'Infants', 'Under 2', 0],
]
const monthFormatter = new Intl.DateTimeFormat('en', { month: 'long', year: 'numeric' })
const dateFormatter = new Intl.DateTimeFormat('en', { month: 'short', day: 'numeric', year: 'numeric' })

function startOfDay(date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

function startOfMonth(date) {
  return new Date(date.getFullYear(), date.getMonth(), 1)
}

function addMonths(date, amount) {
  return new Date(date.getFullYear(), date.getMonth() + amount, 1)
}

function toDateValue(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

function parseDateValue(value) {
  if (!value) {
    return null
  }

  const [year, month, day] = value.split('-').map(Number)

  if (!year || !month || !day) {
    return null
  }

  return new Date(year, month - 1, day)
}

function getMonthCells(monthDate) {
  const monthStart = startOfMonth(monthDate)
  const totalDays = new Date(monthStart.getFullYear(), monthStart.getMonth() + 1, 0).getDate()
  const leadingBlanks = monthStart.getDay()
  const cells = Array.from({ length: leadingBlanks }, () => null)

  for (let day = 1; day <= totalDays; day += 1) {
    cells.push(new Date(monthStart.getFullYear(), monthStart.getMonth(), day))
  }

  return cells
}

function formatDisplayDate(value, fallback) {
  const date = parseDateValue(value)

  return date ? dateFormatter.format(date) : fallback
}

function isDateBetween(date, checkIn, checkOut) {
  const checkInDate = parseDateValue(checkIn)
  const checkOutDate = parseDateValue(checkOut)

  if (!checkInDate || !checkOutDate) {
    return false
  }

  const dateTime = startOfDay(date).getTime()

  return dateTime > checkInDate.getTime() && dateTime < checkOutDate.getTime()
}

function normalizeWholeNumber(value, minimum) {
  const digits = String(value).replace(/\D/g, '')

  if (!digits) {
    return minimum
  }

  return Math.max(minimum, Number(digits))
}

function SearchBar({ className = '', isCompact = false, isMobileTrigger = false, onSearch }) {
  const navigate = useNavigate()
  const { resortName } = useResortBranding()
  const { checkIn, checkOut, guestCounts, setCheckIn, setCheckOut, setGuestCounts, totalGuests } =
    useBookingSearch()
  const today = useMemo(() => startOfDay(new Date()), [])
  const [activeField, setActiveField] = useState(null)
  const [activePill, setActivePill] = useState({ fieldLeft: 0, formLeft: 0, width: 0 })
  const [visibleMonth, setVisibleMonth] = useState(() => startOfMonth(new Date()))
  const [isMobileSheetOpen, setIsMobileSheetOpen] = useState(false)
  const formRef = useRef(null)
  const fieldsRef = useRef(null)
  const fieldRefs = useRef([])

  const isDatePickerOpen = activeField === 0 || activeField === 1
  const isGuestPickerOpen = activeField === 2
  const occupancy = totalGuests
  const guestSummary = `${occupancy} ${occupancy === 1 ? 'guest' : 'guests'}${
    guestCounts.infants > 0
      ? `, ${guestCounts.infants} ${guestCounts.infants === 1 ? 'infant' : 'infants'}`
      : ''
  }`

  useEffect(() => {
    const activeElement = activeField === null ? null : fieldRefs.current[activeField]
    const fieldsElement = fieldsRef.current
    const formElement = formRef.current

    function updateActivePill() {
      if (!activeElement || !fieldsElement || !formElement) {
        setActivePill({ fieldLeft: 0, formLeft: 0, width: 0 })
        return
      }

      const activeRect = activeElement.getBoundingClientRect()
      const fieldsRect = fieldsElement.getBoundingClientRect()
      const formRect = formElement.getBoundingClientRect()

      setActivePill({
        fieldLeft: activeRect.left - fieldsRect.left,
        formLeft: activeRect.left - formRect.left,
        width: activeRect.width,
      })
    }

    updateActivePill()

    if (!activeElement || !fieldsElement) {
      return undefined
    }

    const resizeObserver =
      typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(updateActivePill)

    resizeObserver?.observe(activeElement)
    resizeObserver?.observe(fieldsElement)
    resizeObserver?.observe(formElement)
    window.addEventListener('resize', updateActivePill)

    return () => {
      resizeObserver?.disconnect()
      window.removeEventListener('resize', updateActivePill)
    }
  }, [activeField])

  useEffect(() => {
    function handleDocumentPointerDown(event) {
      if (!formRef.current?.contains(event.target)) {
        setActiveField(null)
      }
    }

    function handleDocumentKeyDown(event) {
      if (event.key === 'Escape') {
        setActiveField(null)
      }
    }

    document.addEventListener('pointerdown', handleDocumentPointerDown)
    document.addEventListener('keydown', handleDocumentKeyDown)

    return () => {
      document.removeEventListener('pointerdown', handleDocumentPointerDown)
      document.removeEventListener('keydown', handleDocumentKeyDown)
    }
  }, [])

  useEffect(() => {
    if (!isMobileSheetOpen) {
      return undefined
    }

    function handleDocumentKeyDown(event) {
      if (event.key === 'Escape') {
        setIsMobileSheetOpen(false)
        setActiveField(null)
      }
    }

    document.body.classList.add('has-mobile-search-open')
    document.addEventListener('keydown', handleDocumentKeyDown)

    return () => {
      document.body.classList.remove('has-mobile-search-open')
      document.removeEventListener('keydown', handleDocumentKeyDown)
    }
  }, [isMobileSheetOpen])

  const handleSubmit = (event) => {
    event.preventDefault()
    const searchDetails = {
      check_in: checkIn,
      check_out: checkOut,
      adults: guestCounts.adults,
      children: guestCounts.children,
      infants: guestCounts.infants,
      guests: occupancy,
    }

    if (onSearch) {
      onSearch(searchDetails)
      setActiveField(null)
      setIsMobileSheetOpen(false)
      return
    }

    const params = new URLSearchParams()

    if (checkIn) {
      params.set('check_in', checkIn)
    }

    if (checkOut) {
      params.set('check_out', checkOut)
    }

    params.set('adults', String(guestCounts.adults))
    params.set('children', String(guestCounts.children))
    params.set('infants', String(guestCounts.infants))

    if (occupancy) {
      params.set('guests', String(occupancy))
    }

    navigate(`/rooms${params.toString() ? `?${params.toString()}` : ''}`)
    setIsMobileSheetOpen(false)
  }

  function openField(index) {
    setActiveField(index)

    if ((index === 0 || index === 1) && checkIn) {
      setVisibleMonth(startOfMonth(parseDateValue(checkIn)))
    }
  }

  function handleDateSelect(date) {
    if (date < today) {
      return
    }

    const selectedValue = toDateValue(date)
    const currentCheckInDate = parseDateValue(checkIn)

    if (activeField === 1 && currentCheckInDate && date > currentCheckInDate) {
      setCheckOut(selectedValue)
      setActiveField(null)
      return
    }

    setCheckIn(selectedValue)
    setCheckOut('')
    setActiveField(1)
  }

  function changeGuestCategory(category, amount) {
    setGuestCounts((currentCounts) => {
      const minimum = category === 'adults' ? 1 : 0

      return {
        ...currentCounts,
        [category]: Math.max(minimum, currentCounts[category] + amount),
      }
    })
  }

  function updateGuestCategory(category, value) {
    setGuestCounts((currentCounts) => {
      const minimum = category === 'adults' ? 1 : 0

      return {
        ...currentCounts,
        [category]: normalizeWholeNumber(value, minimum),
      }
    })
  }

  function renderCalendarMonth(monthDate) {
    const cells = getMonthCells(monthDate)

    return (
      <section className="date-picker-month" key={monthDate.toISOString()}>
        <h3>{monthFormatter.format(monthDate)}</h3>
        <div className="date-picker-weekdays" aria-hidden="true">
          {weekdayLabels.map((day) => (
            <span key={day}>{day}</span>
          ))}
        </div>
        <div className="date-picker-grid">
          {cells.map((date, index) => {
            if (!date) {
              return <span className="date-picker-empty-cell" key={`empty-${index}`} />
            }

            const dateValue = toDateValue(date)
            const isDisabled = date < today
            const isCheckIn = dateValue === checkIn
            const isCheckOut = dateValue === checkOut
            const isInRange = isDateBetween(date, checkIn, checkOut)

            return (
              <button
                className={[
                  'date-picker-day',
                  isCheckIn ? 'is-check-in' : '',
                  isCheckOut ? 'is-check-out' : '',
                  isInRange ? 'is-in-range' : '',
                ]
                  .filter(Boolean)
                  .join(' ')}
                disabled={isDisabled}
                key={dateValue}
                type="button"
                onClick={() => handleDateSelect(date)}
              >
                <span>{date.getDate()}</span>
              </button>
            )
          })}
        </div>
      </section>
    )
  }

  const isPreviousMonthDisabled = visibleMonth <= startOfMonth(today)
  const hiddenInputs = (
    <>
      <input name="check_in" readOnly type="hidden" value={checkIn} />
      <input name="check_out" readOnly type="hidden" value={checkOut} />
      <input name="adults" readOnly type="hidden" value={guestCounts.adults} />
      <input name="children" readOnly type="hidden" value={guestCounts.children} />
      <input name="infants" readOnly type="hidden" value={guestCounts.infants} />
      <input name="guests" readOnly type="hidden" value={occupancy} />
    </>
  )

  const searchFields = (
    <div className="search-fields" ref={fieldsRef}>
      <span className="search-active-pill" aria-hidden="true" />
      {fieldConfig.map((field, index) => {
        const value =
          field.id === 'check_in'
            ? formatDisplayDate(checkIn, 'Add dates')
            : field.id === 'check_out'
              ? formatDisplayDate(checkOut, 'Add dates')
              : guestSummary

        if (field.id === 'guests') {
          return (
            <div className="guest-field-wrapper" key={field.id}>
              <button
                aria-expanded={activeField === index}
                className={`search-field ${activeField === index ? 'is-active' : ''}`}
                ref={(element) => {
                  fieldRefs.current[index] = element
                }}
                type="button"
                onClick={() => openField(index)}
              >
                <span>{field.label}</span>
                <strong>{value}</strong>
              </button>
              {isGuestPickerOpen ? (
                <div className="search-dropdown-panel guest-picker-panel">
                  {guestCategories.map(([category, label, note, minimum]) => {
                    const count = guestCounts[category]

                    return (
                      <div className="guest-picker-row" key={category}>
                        <div>
                          <strong>{label}</strong>
                          <span>{note}</span>
                        </div>
                        <div className="guest-counter">
                          <button
                            aria-label={`Decrease ${label.toLowerCase()}`}
                            disabled={count <= minimum}
                            type="button"
                            onClick={() => changeGuestCategory(category, -1)}
                          >
                            -
                          </button>
                          <input
                            aria-label={`${label} count`}
                            inputMode="numeric"
                            min={minimum}
                            pattern="[0-9]*"
                            type="text"
                            value={count}
                            onChange={(event) => updateGuestCategory(category, event.target.value)}
                            onFocus={(event) => event.target.select()}
                          />
                          <button
                            aria-label={`Increase ${label.toLowerCase()}`}
                            type="button"
                            onClick={() => changeGuestCategory(category, 1)}
                          >
                            +
                          </button>
                        </div>
                      </div>
                    )
                  })}
                </div>
              ) : null}
            </div>
          )
        }

        return (
          <button
            aria-expanded={activeField === index}
            className={`search-field ${activeField === index ? 'is-active' : ''}`}
            key={field.id}
            ref={(element) => {
              fieldRefs.current[index] = element
            }}
            type="button"
            onClick={() => openField(index)}
          >
            <span>{field.label}</span>
            <strong>{value}</strong>
          </button>
        )
      })}
    </div>
  )

  const searchDropdowns = (
    <>
      {isDatePickerOpen ? (
        <div className="search-dropdown-panel date-picker-panel">
          <div className="date-picker-header">
            <div>
              <strong>{activeField === 0 ? 'Choose check-in' : 'Choose check-out'}</strong>
              <span>
                {checkIn && !checkOut
                  ? 'Select a checkout date after your arrival.'
                  : 'Select your stay dates.'}
              </span>
            </div>
            <div className="date-picker-nav">
              <button
                aria-label="Previous month"
                disabled={isPreviousMonthDisabled}
                type="button"
                onClick={() => setVisibleMonth((month) => addMonths(month, -1))}
              >
                <span aria-hidden="true">&lsaquo;</span>
              </button>
              <button
                aria-label="Next month"
                type="button"
                onClick={() => setVisibleMonth((month) => addMonths(month, 1))}
              >
                <span aria-hidden="true">&rsaquo;</span>
              </button>
            </div>
          </div>
          <div className="date-picker-months">
            {[visibleMonth, addMonths(visibleMonth, 1)].map((month) => renderCalendarMonth(month))}
          </div>
        </div>
      ) : null}

    </>
  )

  if (isMobileTrigger) {
    return (
      <div className={`mobile-search-control ${className}`.trim()}>
        <button
          className="mobile-search-trigger"
          type="button"
          onClick={() => {
            setIsMobileSheetOpen(true)
            setActiveField(0)
          }}
        >
          <span aria-hidden="true">Search</span>
          <strong>Search {resortName}</strong>
        </button>

        {isMobileSheetOpen ? (
          <div className="mobile-search-overlay">
            <button
              className="mobile-search-backdrop"
              type="button"
              aria-label="Close search"
              onClick={() => {
                setIsMobileSheetOpen(false)
                setActiveField(null)
              }}
            />
            <form
              className="search-bar mobile-search-sheet"
              ref={formRef}
              style={{
                '--active-search-left': `${activePill.fieldLeft}px`,
                '--active-panel-left': `${activePill.formLeft}px`,
                '--active-search-width': `${activePill.width}px`,
              }}
              data-has-active-field={activeField !== null}
              onSubmit={handleSubmit}
            >
              <div className="mobile-search-sheet-header">
                <div>
                  <strong>Search {resortName}</strong>
                  <span>Choose dates and guests</span>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    setIsMobileSheetOpen(false)
                    setActiveField(null)
                  }}
                >
                  Close
                </button>
              </div>
              {hiddenInputs}
              {searchFields}
              <button className="search-button" type="submit">Search</button>
              {searchDropdowns}
            </form>
          </div>
        ) : null}
      </div>
    )
  }

  return (
    <form
      className={`search-bar ${className}`.trim()}
      ref={formRef}
      style={{
        '--active-search-left': `${activePill.fieldLeft}px`,
        '--active-panel-left': `${activePill.formLeft}px`,
        '--active-search-width': `${activePill.width}px`,
      }}
      data-has-active-field={activeField !== null}
      onSubmit={handleSubmit}
    >
      <input name="check_in" readOnly type="hidden" value={checkIn} />
      <input name="check_out" readOnly type="hidden" value={checkOut} />
      <input name="adults" readOnly type="hidden" value={guestCounts.adults} />
      <input name="children" readOnly type="hidden" value={guestCounts.children} />
      <input name="infants" readOnly type="hidden" value={guestCounts.infants} />
      <input name="guests" readOnly type="hidden" value={occupancy} />

      <div className="search-fields" ref={fieldsRef}>
        <span className="search-active-pill" aria-hidden="true" />
        {fieldConfig.map((field, index) => {
          const value =
            field.id === 'check_in'
              ? formatDisplayDate(checkIn, 'Add dates')
              : field.id === 'check_out'
                ? formatDisplayDate(checkOut, 'Add dates')
                : guestSummary

          return (
            <button
              aria-expanded={activeField === index}
              className={`search-field ${activeField === index ? 'is-active' : ''}`}
              key={field.id}
              ref={(element) => {
                fieldRefs.current[index] = element
              }}
              type="button"
              onClick={() => openField(index)}
            >
              <span>{field.label}</span>
              <strong>{value}</strong>
            </button>
          )
        })}
      </div>

      <button className="search-button" type="submit">
        {isCompact ? 'Go' : 'Search'}
      </button>

      {isDatePickerOpen ? (
        <div className="search-dropdown-panel date-picker-panel">
          <div className="date-picker-header">
            <div>
              <strong>{activeField === 0 ? 'Choose check-in' : 'Choose check-out'}</strong>
              <span>
                {checkIn && !checkOut
                  ? 'Select a checkout date after your arrival.'
                  : 'Select your stay dates.'}
              </span>
            </div>
            <div className="date-picker-nav">
              <button
                aria-label="Previous month"
                disabled={isPreviousMonthDisabled}
                type="button"
                onClick={() => setVisibleMonth((month) => addMonths(month, -1))}
              >
                <span aria-hidden="true">‹</span>
              </button>
              <button
                aria-label="Next month"
                type="button"
                onClick={() => setVisibleMonth((month) => addMonths(month, 1))}
              >
                <span aria-hidden="true">›</span>
              </button>
            </div>
          </div>
          <div className="date-picker-months">
            {[visibleMonth, addMonths(visibleMonth, 1)].map((month) => renderCalendarMonth(month))}
          </div>
        </div>
      ) : null}

      {isGuestPickerOpen ? (
        <div className="search-dropdown-panel guest-picker-panel">
          {guestCategories.map(([category, label, note, minimum]) => {
            const count = guestCounts[category]

            return (
              <div className="guest-picker-row" key={category}>
                <div>
                  <strong>{label}</strong>
                  <span>{note}</span>
                </div>
                <div className="guest-counter">
                  <button
                    aria-label={`Decrease ${label.toLowerCase()}`}
                    disabled={count <= minimum}
                    type="button"
                    onClick={() => changeGuestCategory(category, -1)}
                  >
                    -
                  </button>
                  <input
                    aria-label={`${label} count`}
                    inputMode="numeric"
                    min={minimum}
                    pattern="[0-9]*"
                    type="text"
                    value={count}
                    onChange={(event) => updateGuestCategory(category, event.target.value)}
                    onFocus={(event) => event.target.select()}
                  />
                  <button
                    aria-label={`Increase ${label.toLowerCase()}`}
                    type="button"
                    onClick={() => changeGuestCategory(category, 1)}
                  >
                    +
                  </button>
                </div>
              </div>
            )
          })}
        </div>
      ) : null}
    </form>
  )
}

export default SearchBar
