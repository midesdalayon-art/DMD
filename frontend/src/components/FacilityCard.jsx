import ResortImagePlaceholder from './ResortImagePlaceholder'

function FacilityCard({ facility }) {
  return (
    <article className="facility-card">
      <ResortImagePlaceholder label="Facility photo pending" variant="facility" />
      <div>
        <h3>{facility.name}</h3>
        <p>{facility.description}</p>
      </div>
    </article>
  )
}

export default FacilityCard
