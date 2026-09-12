import { Link } from 'react-router-dom'
import ResortBrandMark from './ResortBrandMark'
import ResortBrandName from './ResortBrandName'
import { useResortBranding } from '../hooks/useResortBranding'

function Footer() {
  const year = new Date().getFullYear()
  const { shortDescription, publicSettings } = useResortBranding()
  const contact = publicSettings.contact ?? {}
  const resortAddress = contact.resort_address ?? contact.address ?? ''
  const contactEmail = contact.email ?? ''
  const contactNumber = contact.contact_number ?? ''

  return (
    <footer className="site-footer">
      <div className="site-shell footer-grid">
        <div className="footer-brand-column">
          <Link className="footer-brand" to="/">
            <ResortBrandMark size="small" />
            <ResortBrandName className="resort-brand-name" />
          </Link>
          <p>{shortDescription || 'Plan your stay and explore DMD Family Resort.'}</p>
        </div>

        <nav aria-label="Quick links" className="footer-links">
          <strong>Quick Links</strong>
          <div className="footer-links-row">
            <Link to="/">Home</Link>
            <span aria-hidden="true">·</span>
            <Link to="/about">About Us</Link>
            <span aria-hidden="true">·</span>
            <Link to="/rooms">Rooms</Link>
            <span aria-hidden="true">·</span>
            <Link to="/rooms?type=cottage">Cottages</Link>
            <span aria-hidden="true">·</span>
            <Link to="/function-hall">Function Hall</Link>
          </div>
        </nav>

        <div className="footer-contact">
          <strong>Contact</strong>
          {contactNumber ? <p><span>Phone:</span> {contactNumber}</p> : null}
          {contactEmail ? <p><span>Email:</span> {contactEmail}</p> : null}
          {resortAddress ? <p><span>Location:</span> {resortAddress}</p> : null}
        </div>

        <nav aria-label="Useful links" className="footer-links is-useful">
          <strong>Useful Links</strong>
          <Link to="/about">About Us</Link>
          <Link to="/contact">Contact</Link>
          <Link to="/rooms">Rooms</Link>
        </nav>
      </div>

      <div className="site-footer-bottom">
        <div className="site-shell footer-bottom-inner">
          <p>&copy; {year} <ResortBrandName as="span" className="resort-brand-name" />. All rights reserved.</p>
        </div>
      </div>
    </footer>
  )
}

export default Footer
