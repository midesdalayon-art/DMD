import { Link } from 'react-router-dom'

function NotFound() {
  return (
    <section className="site-shell page-section narrow-page">
      <div className="empty-state">
        <h1>Page not found</h1>
        <p>The page you are looking for is not part of the public website.</p>
        <Link className="solid-button" to="/">
          Go Home
        </Link>
      </div>
    </section>
  )
}

export default NotFound
