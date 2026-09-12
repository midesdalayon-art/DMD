import SectionHeading from '../components/SectionHeading'
import { resolveAssetUrl } from '../lib/api'
import { useResortBranding } from '../hooks/useResortBranding'

function About() {
  const { publicSettings, resortName, isLoading, loadError } = useResortBranding()

  const about = publicSettings?.about ?? {}

  return (
    <section className="site-shell page-section narrow-page">
      {isLoading ? <p className="sr-only" role="status">Loading About Us information...</p> : null}
      {loadError ? <p>Unable to load About Us information right now.</p> : null}
      {!isLoading && !loadError ? (
        <>
          <SectionHeading eyebrow="About" title={about.title || `About ${resortName}`} />
          {about.image_url ? (
            <div className="public-about-image">
              <img src={resolveAssetUrl(about.image_url)} alt={about.title || resortName} />
            </div>
          ) : null}
          {about.short_introduction ? <p className="lead-copy">{about.short_introduction}</p> : null}
          {about.full_description ? <p>{about.full_description}</p> : null}
          <div className="public-about-values">
            {about.mission ? (
              <section>
                <h2>Mission</h2>
                <p>{about.mission}</p>
              </section>
            ) : null}
            {about.vision ? (
              <section>
                <h2>Vision</h2>
                <p>{about.vision}</p>
              </section>
            ) : null}
          </div>
        </>
      ) : null}
    </section>
  )
}

export default About
