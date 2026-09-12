import AccommodationCard from './AccommodationCard'
import EmptyState from './EmptyState'
import { useResortBranding } from '../hooks/useResortBranding'

function AccommodationGrid({ accommodations }) {
  const { resortName } = useResortBranding()

  if (accommodations.length === 0) {
    return (
      <EmptyState
        title="No accommodations in this category yet"
        message={`Verified ${resortName} listings can be added here when available.`}
      />
    )
  }

  return (
    <div className="accommodation-grid">
      {accommodations.map((accommodation) => (
        <AccommodationCard key={accommodation.id} accommodation={accommodation} />
      ))}
    </div>
  )
}

export default AccommodationGrid
