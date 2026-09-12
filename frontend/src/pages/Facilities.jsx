import FacilityCard from '../components/FacilityCard'
import SectionHeading from '../components/SectionHeading'
import { facilities } from '../data/mockPublicContent'
import { useResortBranding } from '../hooks/useResortBranding'

function Facilities() {
  const { resortName } = useResortBranding()

  return (
    <section className="site-shell page-section">
      <SectionHeading eyebrow="Facilities" title={`${resortName} facilities`}>
        These are placeholder entries. Replace them with real facility names,
        descriptions, and photos when available.
      </SectionHeading>
      <div className="facility-grid">
        {facilities.map((facility) => (
          <FacilityCard key={facility.id} facility={facility} />
        ))}
      </div>
    </section>
  )
}

export default Facilities
