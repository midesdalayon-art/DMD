import { useEffect, useMemo, useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import AccommodationGrid from '../components/AccommodationGrid'
import CategoryNavigation from '../components/CategoryNavigation'
import EmptyState from '../components/EmptyState'
import LoadingState from '../components/LoadingState'
import SearchBar from '../components/SearchBar'
import { categories } from '../data/mockPublicContent'
import { getAccommodations } from '../lib/api'

function Rooms() {
  const location = useLocation()
  const [activeCategory, setActiveCategory] = useState('all')
  const [accommodations, setAccommodations] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')
  const requestSequenceRef = useRef(0)

  useEffect(() => {
    const controller = new AbortController()
    const requestSequence = ++requestSequenceRef.current
    let isMounted = true

    async function loadAccommodations() {
      setIsLoading(true)
      setErrorMessage('')

      try {
        const params = new URLSearchParams(location.search)
        const filters = Object.fromEntries(
          ['check_in', 'check_out', 'guests', 'adults', 'children', 'infants']
            .map((key) => [key, params.get(key)])
            .filter(([, value]) => value),
        )
        const data = await getAccommodations(filters, { signal: controller.signal })
        if (isMounted && requestSequence === requestSequenceRef.current) {
          setAccommodations(data)
        }
      } catch (error) {
        const wasCancelled = controller.signal.aborted
          || error?.code === 'ERR_CANCELED'
          || error?.name === 'CanceledError'
          || error?.name === 'AbortError'

        if (isMounted && requestSequence === requestSequenceRef.current && !wasCancelled) {
          setErrorMessage('We could not load the stays right now. Please try again.')
        }
      } finally {
        if (isMounted && requestSequence === requestSequenceRef.current) {
          setIsLoading(false)
        }
      }
    }

    loadAccommodations()

    return () => {
      isMounted = false
      controller.abort()
    }
  }, [location.search])

  useEffect(() => {
    const params = new URLSearchParams(location.search)
    const type = params.get('type')

    if (type) {
      setActiveCategory(type)
    }
  }, [location.search])

  const visibleAccommodations = useMemo(() => {
    if (activeCategory === 'all') {
      return accommodations
    }

    return accommodations.filter((item) => normalizeAccommodationType(item) === activeCategory)
  }, [accommodations, activeCategory])

  return (
    <section className="site-shell page-section room-page">
      <SearchBar />
      <CategoryNavigation
        activeCategory={activeCategory}
        categories={categories}
        onChange={setActiveCategory}
      />
      {isLoading ? <LoadingState message="Loading stays..." /> : null}
      {errorMessage ? <EmptyState title="Unable to load stays" message={errorMessage} /> : null}
      {!isLoading && !errorMessage ? (
        <AccommodationGrid accommodations={visibleAccommodations} />
      ) : null}
    </section>
  )
}

export default Rooms

function normalizeAccommodationType(accommodation) {
  const rawType = String(accommodation?.category ?? accommodation?.type ?? '').trim().toLowerCase()

  if (rawType === 'functionhall' || rawType === 'function hall' || rawType === 'hall') {
    return 'function_hall'
  }

  return rawType.replace(/\s+/g, '_')
}

