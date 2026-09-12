import { useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import EmptyState from '../components/EmptyState'
import LoadingState from '../components/LoadingState'
import SectionHeading from '../components/SectionHeading'
import { getAccommodations } from '../lib/api'

function ExclusiveResort() {
  const [resort, setResort] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')

  useEffect(() => {
    let isMounted = true

    async function loadExclusiveResort() {
      setIsLoading(true)
      setErrorMessage('')

      try {
        const data = await getAccommodations({ type: 'exclusive_resort' })
        if (isMounted) {
          setResort(data[0] ?? null)
        }
      } catch {
        if (isMounted) {
          setErrorMessage('Unable to load exclusive resort details right now.')
        }
      } finally {
        if (isMounted) {
          setIsLoading(false)
        }
      }
    }

    loadExclusiveResort()

    return () => {
      isMounted = false
    }
  }, [])

  if (isLoading) {
    return (
      <section className="site-shell page-section room-page">
        <LoadingState message="Loading exclusive resort..." />
      </section>
    )
  }

  if (errorMessage) {
    return (
      <section className="site-shell page-section room-page">
        <SectionHeading eyebrow="Exclusive Resort Rental" title="Reserve the Entire Resort" />
        <EmptyState title="Unable to load exclusive resort" message={errorMessage} />
      </section>
    )
  }

  if (!resort) {
    return (
      <section className="site-shell page-section room-page">
        <SectionHeading eyebrow="Exclusive Resort Rental" title="Reserve the Entire Resort" />
        <EmptyState title="Exclusive Resort not found" message="No exclusive resort listing is currently configured." />
      </section>
    )
  }

  return <Navigate to={`/exclusive-resort/${resort.id}`} replace />
}

export default ExclusiveResort
