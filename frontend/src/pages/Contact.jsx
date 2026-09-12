import { MapPin } from 'lucide-react'
import SectionHeading from '../components/SectionHeading'
import { useResortBranding } from '../hooks/useResortBranding'

const DEFAULT_GOOGLE_MAPS_URL = 'https://maps.app.goo.gl/UGcrrgEkEkJMJLEr7'

function Contact() {
  const { publicSettings, resortName, isLoading, loadError } = useResortBranding()

  const contact = publicSettings?.contact ?? {}
  const resortLocationQuery = contact.resort_address || `${resortName} Resort`
  const embedMapUrl = `https://www.google.com/maps?output=embed&q=${encodeURIComponent(resortLocationQuery)}`
  const hasContactDetails = [
    contact.resort_address,
    contact.primary_phone,
    contact.secondary_phone,
    contact.email,
    contact.facebook_url,
    contact.google_maps_url,
    contact.business_hours,
  ].some(Boolean)

  return (
    <section className="site-shell page-section contact-page">
      <SectionHeading eyebrow="Contact" title={`Contact ${resortName}`} />
      <div className="contact-layout">
        <div>
          {isLoading ? <p className="sr-only" role="status">Loading contact information...</p> : null}
          {loadError ? <p>Unable to load contact information right now.</p> : null}
          {!isLoading && !loadError && hasContactDetails ? (
            <dl className="contact-list">
              {contact.resort_address ? (
                <div>
                  <dt>Address</dt>
                  <dd>{contact.resort_address}</dd>
                </div>
              ) : null}
              {contact.primary_phone ? (
                <div>
                  <dt>Primary Phone</dt>
                  <dd>
                    <a href={`tel:${phoneHref(contact.primary_phone)}`}>{contact.primary_phone}</a>
                  </dd>
                </div>
              ) : null}
              {contact.secondary_phone ? (
                <div>
                  <dt>Secondary Phone</dt>
                  <dd>
                    <a href={`tel:${phoneHref(contact.secondary_phone)}`}>{contact.secondary_phone}</a>
                  </dd>
                </div>
              ) : null}
              {contact.email ? (
                <div>
                  <dt>Email</dt>
                  <dd>
                    <a href={`mailto:${contact.email}`}>{contact.email}</a>
                  </dd>
                </div>
              ) : null}
              {contact.facebook_url ? (
                <div>
                  <dt>Facebook</dt>
                  <dd>
                    <a href={contact.facebook_url} target="_blank" rel="noreferrer">
                      Open Facebook Page
                    </a>
                  </dd>
                </div>
              ) : null}
              {contact.google_maps_url || contact.resort_address ? (
                <div>
                  <dt>Location</dt>
                  <dd className="contact-location-card">
                    <div className="contact-location-header">
                      <MapPin aria-hidden="true" size={16} />
                      <div>
                        <strong>DMD Family Resort</strong>
                      </div>
                    </div>
                    <div className="contact-location-map">
                      <iframe
                        title="DMD Family Resort location map"
                        src={embedMapUrl}
                        loading="lazy"
                        referrerPolicy="no-referrer-when-downgrade"
                        allowFullScreen
                      />
                    </div>
                    <a
                      className="contact-location-map-link"
                      href={contact.google_maps_url || DEFAULT_GOOGLE_MAPS_URL}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      View on Google Maps →
                    </a>
                  </dd>
                </div>
              ) : null}
              {contact.business_hours ? (
                <div>
                  <dt>Business Hours</dt>
                  <dd>{contact.business_hours}</dd>
                </div>
              ) : null}
            </dl>
          ) : null}
          {!isLoading && !loadError && !hasContactDetails ? (
            <p>Contact information will be published here once available.</p>
          ) : null}
        </div>
        <form className="contact-form">
          <label>
            <span>Name</span>
            <input type="text" name="name" autoComplete="name" />
          </label>
          <label>
            <span>Email</span>
            <input type="email" name="email" autoComplete="email" />
          </label>
          <label>
            <span>Message</span>
            <textarea name="message" rows="5" />
          </label>
          <button type="button" className="solid-button">
            Send Inquiry
          </button>
        </form>
      </div>
    </section>
  )
}

function phoneHref(value) {
  return String(value).replace(/[^\d+]/g, '')
}

export default Contact
