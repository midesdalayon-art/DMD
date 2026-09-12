import { useEffect, useState } from 'react'
import AccommodationGrid from '../components/AccommodationGrid'
import EmptyState from '../components/EmptyState'
import LoadingState from '../components/LoadingState'
import SectionHeading from '../components/SectionHeading'
import { getAccommodations } from '../lib/api'

function FunctionHall() {
  const [halls, setHalls] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')

  useEffect(() => {
    let isMounted = true

    async function loadFunctionHalls() {
      setIsLoading(true)
      setErrorMessage('')

      try {
        const data = await getAccommodations({ type: 'function_hall' })

        if (isMounted) {
          setHalls(data)
        }
      } catch {
        if (isMounted) {
          setErrorMessage('Unable to load function hall listings right now.')
        }
      } finally {
        if (isMounted) {
          setIsLoading(false)
        }
      }
    }

    loadFunctionHalls()

    return () => {
      isMounted = false
    }
  }, [])

  return (
    <section className="site-shell page-section room-page">
      <SectionHeading eyebrow="Events" title="Function Hall" />
      {isLoading ? <LoadingState message="Loading function hall listings..." /> : null}
      {errorMessage ? <EmptyState title="Unable to load function hall" message={errorMessage} /> : null}
      {!isLoading && !errorMessage ? <AccommodationGrid accommodations={halls} /> : null}
    </section>
  )
}

export default FunctionHall

