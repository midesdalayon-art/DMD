import { useLayoutEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import ResortImagePlaceholder from './ResortImagePlaceholder'
import { resolveAssetUrl } from '../lib/api'

function AccommodationCarousel({ title, accommodations, emptyMessage, actionTo, id }) {
  const trackRef = useRef(null)
  const [scrollState, setScrollState] = useState({
    canScrollLeft: false,
    canScrollRight: false,
  })
  const hasItems = accommodations.length > 0

  function updateScrollState() {
    const track = trackRef.current

    if (!track || !hasItems) {
      setScrollState({ canScrollLeft: false, canScrollRight: false })
      return
    }

    const { scrollLeft, clientWidth, scrollWidth } = track

    setScrollState({
      canScrollLeft: scrollLeft > 1,
      canScrollRight: scrollLeft + clientWidth < scrollWidth - 2,
    })
  }

  function getScrollAmount() {
    const track = trackRef.current

    if (!track) {
      return 0
    }

    const firstCard = track.querySelector('.storefront-card')
    const computedStyles = window.getComputedStyle(track)
    const gap = Number.parseFloat(computedStyles.columnGap || computedStyles.gap || '12') || 12
    const cardWidth = firstCard?.offsetWidth ?? Math.max(track.clientWidth * 0.82, 280)

    return Math.max(cardWidth + gap, track.clientWidth * 0.82, 280)
  }

  function scrollPrevious() {
    trackRef.current?.scrollBy({
      left: -getScrollAmount(),
      behavior: 'smooth',
    })
  }

  function scrollNext() {
    trackRef.current?.scrollBy({
      left: getScrollAmount(),
      behavior: 'smooth',
    })
  }

  useLayoutEffect(() => {
    const track = trackRef.current

    if (!track || !hasItems) {
      setScrollState({ canScrollLeft: false, canScrollRight: false })
      return undefined
    }

    let frameId = 0

    const onScrollOrResize = () => {
      window.cancelAnimationFrame(frameId)
      frameId = window.requestAnimationFrame(updateScrollState)
    }

    updateScrollState()
    track.addEventListener('scroll', onScrollOrResize, { passive: true })
    window.addEventListener('resize', onScrollOrResize)

    const resizeObserver =
      typeof ResizeObserver !== 'undefined'
        ? new ResizeObserver(onScrollOrResize)
        : null

    resizeObserver?.observe(track)

    return () => {
      window.cancelAnimationFrame(frameId)
      track.removeEventListener('scroll', onScrollOrResize)
      window.removeEventListener('resize', onScrollOrResize)
      resizeObserver?.disconnect()
    }
  }, [accommodations.length, hasItems])

  return (
    <section className="storefront-section" id={id} aria-labelledby={`${title}-heading`}>
      <div className="accommodation-carousel-header">
        <div className="accommodation-carousel-heading">
          <h2 className="accommodation-section-heading" id={`${title}-heading`}>{title}</h2>
          {hasItems ? (
            <p>
              {accommodations.length} listing{accommodations.length === 1 ? '' : 's'} available to view.
            </p>
          ) : null}
        </div>
      </div>

      {hasItems ? (
        <div className="accommodation-carousel-viewport">
          <button
            type="button"
            className="accommodation-carousel-arrow previous"
            onClick={scrollPrevious}
            aria-label="Previous accommodations"
            disabled={!hasItems || !scrollState.canScrollLeft}
          >
            <span aria-hidden="true">‹</span>
          </button>

          <div className="accommodation-carousel" ref={trackRef} tabIndex="0" aria-label={`${title} listings`}>
            {accommodations.map((accommodation) => (
              <AccommodationStorefrontCard key={accommodation.id} accommodation={accommodation} />
            ))}
          </div>

          <button
            type="button"
            className="accommodation-carousel-arrow next"
            onClick={scrollNext}
            aria-label="Next accommodations"
            disabled={!hasItems || !scrollState.canScrollRight}
          >
            <span aria-hidden="true">›</span>
          </button>
        </div>
      ) : (
        <div className="storefront-empty-state">
          <p>{emptyMessage}</p>
          {actionTo ? (
            <Link className="outline-button" to={actionTo}>
              View Details
            </Link>
          ) : null}
        </div>
      )}
    </section>
  )
}

function AccommodationStorefrontCard({ accommodation }) {
  const imageUrl =
    accommodation.thumbnail_url ??
    accommodation.medium_url ??
    accommodation.primary_image_url ??
    accommodation.image_path
  const imageSrcSet = buildSrcSet(accommodation)
  const detailPath = getAccommodationDetailPath(accommodation)

  return (
    <article className="storefront-card">
      <Link className="storefront-card-media" to={detailPath} aria-label={`View and book ${accommodation.name}`}>
        {imageUrl ? (
          <img
            src={resolveAssetUrl(imageUrl)}
            srcSet={imageSrcSet}
            sizes="(max-width: 767px) 80vw, (max-width: 1280px) 32vw, 28vw"
            alt={accommodation.name}
            loading="lazy"
            decoding="async"
          />
        ) : (
          <ResortImagePlaceholder label="Photo pending" variant={accommodation.category} />
        )}
      </Link>
      <div className="storefront-card-body">
        <div>
          <span>{formatType(accommodation.type)}</span>
          <h3>
            <Link to={detailPath}>{accommodation.name}</Link>
          </h3>
        </div>
        <div className="storefront-card-meta">
          <span>Up to {accommodation.capacity} guests</span>
          <span>{formatCurrency(accommodation.price_per_night)} / night</span>
        </div>
        <div className="storefront-card-footer">
          <span className={`status-label status-${accommodation.status}`}>{formatType(accommodation.status)}</span>
          <Link to={detailPath}>View Details</Link>
        </div>
        <Link className="solid-button accommodation-card-book-button" to={detailPath}>
          {getBookingLabel(accommodation)}
        </Link>
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

function formatCurrency(value) {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 0,
  }).format(Number(value ?? 0))
}

function formatType(value) {
  const labels = {
    room: 'Room',
    cottage: 'Cottage',
    function_hall: 'Function Hall',
    exclusive_resort: 'Exclusive Resort Rental',
    available: 'Available',
    unavailable: 'Unavailable',
    maintenance: 'Maintenance',
  }

  return labels[value] ?? 'Accommodation'
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

export default AccommodationCarousel
