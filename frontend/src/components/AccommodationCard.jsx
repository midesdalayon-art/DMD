import { Link } from 'react-router-dom'
import ResortImagePlaceholder from './ResortImagePlaceholder'
import { resolveAssetUrl } from '../lib/api'

function AccommodationCard({ accommodation }) {
  const imageUrl =
    accommodation.thumbnail_url
    ?? accommodation.medium_url
    ?? accommodation.primary_image_url
    ?? accommodation.image_path
  const imageSrcSet = buildSrcSet(accommodation)
  const priceLabel = formatPrice(accommodation.price_per_night)
  const detailPath = getAccommodationDetailPath(accommodation)

  return (
    <article className="accommodation-card">
      <div className="card-media">
        <Link to={detailPath} aria-label={`View ${accommodation.name}`}>
          {imageUrl ? (
            <img
              src={resolveAssetUrl(imageUrl)}
              srcSet={imageSrcSet}
              sizes="(max-width: 767px) 45vw, (max-width: 1280px) 28vw, 22vw"
              alt={accommodation.name}
              loading="lazy"
              decoding="async"
            />
          ) : (
            <ResortImagePlaceholder
              label="Photo pending"
              variant={accommodation.category}
            />
          )}
        </Link>
        <button className="favorite-button" type="button" aria-label="Save listing">
          <span aria-hidden="true">+</span>
        </button>
      </div>

      <div className="card-copy">
        <div>
          <h3>
            <Link to={detailPath}>{accommodation.name}</Link>
          </h3>
          <p>{accommodation.context}</p>
        </div>
        <dl>
          <div>
            <dt>Guests</dt>
            <dd>{accommodation.guests ?? 'To be added'}</dd>
          </div>
          <div>
            <dt>Price</dt>
          <dd>{priceLabel ? `${priceLabel} ${accommodation.type === 'exclusive_resort' ? '/ day' : '/ night'}` : accommodation.price ?? 'Rate pending'}</dd>
          </div>
        </dl>
        <span className="status-label">{accommodation.status}</span>
        <Link className="solid-button accommodation-card-book-button" to={detailPath}>{getBookingLabel(accommodation)}</Link>
      </div>
    </article>
  )
}

function getAccommodationDetailPath(accommodation) {
  return accommodation.type === 'exclusive_resort'
    ? `/exclusive-resort/${accommodation.id}`
    : `/rooms/${accommodation.id}`
}

function getBookingLabel(accommodation) {
  return {
    room: 'Book This Room',
    cottage: 'Book This Cottage',
    function_hall: 'Book Function Hall',
    exclusive_resort: 'Book Resort',
  }[accommodation.type] ?? 'Book Now'
}

export default AccommodationCard

function formatPrice(value) {
  const amount = Number(value)

  if (Number.isNaN(amount)) {
    return null
  }

  const formatted = new Intl.NumberFormat('en-PH', {
    minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
    maximumFractionDigits: Number.isInteger(amount) ? 0 : 2,
  }).format(amount)

  return `${String.fromCharCode(0x20B1)}${formatted}`
}

function buildSrcSet(accommodation) {
  return [
    accommodation.thumbnail_url ? `${resolveAssetUrl(accommodation.thumbnail_url)} 640w` : null,
    accommodation.medium_url ? `${resolveAssetUrl(accommodation.medium_url)} 960w` : null,
    accommodation.large_url ? `${resolveAssetUrl(accommodation.large_url)} 1440w` : null,
  ]
    .filter(Boolean)
    .join(', ')
}


