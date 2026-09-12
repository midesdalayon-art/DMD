import { createContext, useContext, useEffect, useMemo, useState } from 'react'
import { useLocation } from 'react-router-dom'

const BookingSearchContext = createContext(null)

const defaultGuestCounts = {
  adults: 1,
  children: 0,
  infants: 0,
}

function parsePositiveInteger(value, fallback = 0) {
  if (value === null || value === undefined || value === '') {
    return fallback
  }

  const parsed = Number.parseInt(String(value), 10)

  return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback
}

function normalizeGuestCounts(nextCounts = {}) {
  return {
    adults: Math.max(1, parsePositiveInteger(nextCounts.adults, defaultGuestCounts.adults)),
    children: Math.max(0, parsePositiveInteger(nextCounts.children, defaultGuestCounts.children)),
    infants: Math.max(0, parsePositiveInteger(nextCounts.infants, defaultGuestCounts.infants)),
  }
}

function readSearchParams(search) {
  const params = new URLSearchParams(search)

  return {
    checkIn: params.get('check_in') ?? '',
    checkOut: params.get('check_out') ?? '',
    guestCounts: normalizeGuestCounts({
      adults: params.get('adults'),
      children: params.get('children'),
      infants: params.get('infants'),
    }),
  }
}

export function BookingSearchProvider({ children }) {
  const location = useLocation()
  const [checkIn, setCheckIn] = useState('')
  const [checkOut, setCheckOut] = useState('')
  const [guestCounts, setGuestCounts] = useState(defaultGuestCounts)

  useEffect(() => {
    const nextSearch = readSearchParams(location.search)

    setCheckIn(nextSearch.checkIn)
    setCheckOut(nextSearch.checkOut)
    setGuestCounts((currentCounts) => {
      const currentSnapshot = JSON.stringify(currentCounts)
      const nextSnapshot = JSON.stringify(nextSearch.guestCounts)

      return currentSnapshot === nextSnapshot ? currentCounts : nextSearch.guestCounts
    })
  }, [location.search])

  const value = useMemo(() => {
    const totalGuests = guestCounts.adults + guestCounts.children

    return {
      checkIn,
      checkOut,
      guestCounts,
      totalGuests,
      setCheckIn,
      setCheckOut,
      setGuestCounts: (nextCounts) =>
        setGuestCounts((currentCounts) => {
          const resolvedCounts =
            typeof nextCounts === 'function' ? nextCounts(currentCounts) : nextCounts

          return normalizeGuestCounts(resolvedCounts)
        }),
      setBookingSearch(nextSearch = {}) {
        if (Object.prototype.hasOwnProperty.call(nextSearch, 'checkIn')) {
          setCheckIn(nextSearch.checkIn ?? '')
        }

        if (Object.prototype.hasOwnProperty.call(nextSearch, 'checkOut')) {
          setCheckOut(nextSearch.checkOut ?? '')
        }

        if (Object.prototype.hasOwnProperty.call(nextSearch, 'guestCounts')) {
          setGuestCounts(normalizeGuestCounts(nextSearch.guestCounts))
        }
      },
    }
  }, [checkIn, checkOut, guestCounts])

  return <BookingSearchContext.Provider value={value}>{children}</BookingSearchContext.Provider>
}

export function useBookingSearch() {
  const context = useContext(BookingSearchContext)

  if (!context) {
    throw new Error('useBookingSearch must be used within a BookingSearchProvider')
  }

  return context
}
