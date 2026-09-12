import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { CalendarDays, Heart, Hotel, Mail, MapPin, Navigation, Phone, Users } from 'lucide-react'
import AccommodationCarousel from '../components/AccommodationCarousel'
import InlineLoader from '../components/InlineLoader'
import SearchBar from '../components/SearchBar'
import { useResortBranding } from '../hooks/useResortBranding'
import { getAccommodations, resolveAssetUrl } from '../lib/api'

let initialAccommodationsRequest = null

function getInitialAccommodations() {
  if (!initialAccommodationsRequest) {
    initialAccommodationsRequest = getAccommodations().finally(() => {
      initialAccommodationsRequest = null
    })
  }

  return initialAccommodationsRequest
}

function Home() {
  const location = useLocation()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [accommodations, setAccommodations] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [hasLoadedAccommodations, setHasLoadedAccommodations] = useState(false)
  const [accommodationRetryKey, setAccommodationRetryKey] = useState(0)
  const [pageError, setPageError] = useState('')
  const [searchSummary, setSearchSummary] = useState(null)
  const [heroViewIndex, setHeroViewIndex] = useState(0)
  const accommodationsRequestId = useRef(0)
  const { publicSettings } = useResortBranding()
  const authMessage = location.state?.authMessage
  const googleError = searchParams.get('google_error')

  useEffect(() => {
    if (!authMessage) {
      return undefined
    }

    const timer = window.setTimeout(() => {
      navigate(location.pathname, { replace: true, state: null })
    }, 5000)

    return () => window.clearTimeout(timer)
  }, [authMessage, location.pathname, navigate])

  useEffect(() => {
    let isMounted = true

    async function loadAccommodations() {
      const requestId = ++accommodationsRequestId.current
      setIsLoading(true)
      setPageError('')

      try {
        const data = await getInitialAccommodations()

        if (isMounted && requestId === accommodationsRequestId.current) {
          setAccommodations(data)
          setHasLoadedAccommodations(true)
        }
      } catch {
        if (isMounted && requestId === accommodationsRequestId.current) {
          setPageError("We couldn't load the stays right now. Please try again.")
        }
      } finally {
        if (isMounted && requestId === accommodationsRequestId.current) {
          setIsLoading(false)
        }
      }
    }

    loadAccommodations()

    return () => {
      isMounted = false
    }
  }, [accommodationRetryKey])

  useEffect(() => {
    const timer = window.setInterval(() => {
      setHeroViewIndex((current) => (current + 1) % heroAccommodationViews.length)
    }, 3000)

    return () => window.clearInterval(timer)
  }, [])

  async function handleSearch(searchDetails) {
    const requestId = ++accommodationsRequestId.current
    setIsLoading(true)
    setPageError('')

    try {
      const filters = {
        adults: searchDetails.adults,
        children: searchDetails.children,
        infants: searchDetails.infants,
        guests: searchDetails.guests,
      }

      if (searchDetails.check_in) {
        filters.check_in = searchDetails.check_in
      }

      if (searchDetails.check_out) {
        filters.check_out = searchDetails.check_out
      }

      const data = await getAccommodations(filters)
      if (requestId === accommodationsRequestId.current) {
        setAccommodations(data)
        setHasLoadedAccommodations(true)
        setSearchSummary(
          searchDetails.check_in && searchDetails.check_out
            ? {
                title: 'Showing available stays',
                dates: `${formatStayDate(searchDetails.check_in)} to ${formatStayDate(searchDetails.check_out)}`,
              }
            : {
                title: 'Showing stays that match your guest count.',
                dates: '',
              },
        )
      }
    } catch {
      if (requestId === accommodationsRequestId.current) {
        setPageError("We couldn't search stays right now. Please try again.")
      }
    } finally {
      if (requestId === accommodationsRequestId.current) {
        setIsLoading(false)
      }
    }
  }

  const groupedAccommodations = useMemo(() => {
    return accommodations.reduce(
      (groups, accommodation) => {
        const type = String(accommodation.type ?? accommodation.category ?? '').toLowerCase()

        if (type === 'cottage') {
          groups.cottages.push(accommodation)
        } else if (type === 'function_hall') {
          groups.functionHalls.push(accommodation)
        } else if (type === 'exclusive_resort') {
          groups.exclusiveResort = accommodation
        } else if (type === 'room') {
          groups.rooms.push(accommodation)
        }

        return groups
      },
      { rooms: [], cottages: [], functionHalls: [], exclusiveResort: null },
    )
  }, [accommodations])

  const heroImageUrl = publicSettings.branding?.homepage_hero_image_url ?? ''
  const configuredHeroImages = [
    publicSettings.branding?.hero_image_1_url || heroImageUrl,
    publicSettings.branding?.hero_image_2_url,
    publicSettings.branding?.hero_image_3_url,
    publicSettings.branding?.hero_image_4_url,
    publicSettings.branding?.hero_image_5_url,
    publicSettings.branding?.hero_image_6_url,
  ].filter((url, index, images) => url && images.indexOf(url) === index)
  const heroAnimationEnabled = publicSettings.branding?.hero_animation_enabled === true
  const heroSlides = heroAnimationEnabled ? configuredHeroImages : configuredHeroImages.slice(0, 1)
  const [heroSlideIndex, setHeroSlideIndex] = useState(0)
  const heroView = heroAccommodationViews[heroViewIndex]

  useEffect(() => {
    setHeroSlideIndex(0)
    if (!heroAnimationEnabled || heroSlides.length < 2) return undefined

    const timer = window.setInterval(() => {
      setHeroSlideIndex((current) => (current + 1) % heroSlides.length)
    }, 6000)

    return () => window.clearInterval(timer)
  }, [heroAnimationEnabled, heroSlides.length])

  const activeHeroSlideIndex = heroSlides.length ? heroSlideIndex % heroSlides.length : 0

  function handleViewAccommodations() {
    document.getElementById(heroView.id)?.scrollIntoView({
      behavior: 'smooth',
      block: 'start',
    })
  }

  return (
    <div className="storefront-page">
      {authMessage ? <div className="homepage-auth-toast" role="status">{authMessage}</div> : null}
      {googleError ? <div className="homepage-auth-toast is-error" role="alert">{googleError}</div> : null}
      <section className="hero-section" aria-labelledby="homepage-hero-title">
        <div className="hero-media" aria-hidden="true">
          {heroSlides.length ? (
            heroSlides.map((image, index) => (
              <img
                className={`hero-background-image hero-background-slide ${index === 0 ? 'hero-background-base' : ''} ${index === activeHeroSlideIndex ? 'is-active' : ''}`}
                key={image}
                src={resolveAssetUrl(image)}
                alt=""
              />
            ))
          ) : (
            <div className="hero-background-fallback" />
          )}
        </div>
        <div className="hero-overlay" aria-hidden="true" />

        <div className="hero-viewport">
          <div className="site-shell hero-layout">
            <div className="hero-copy">
              <p className="eyebrow hero-eyebrow">Relax • Unwind • Enjoy</p>
              <h1 id="homepage-hero-title">Your Perfect Family Getaway Awaits</h1>
              <div className="hero-actions" aria-label="Homepage actions">
                <Link className="solid-button hero-primary-button" to="/rooms">
                  Book Now
                </Link>
                <button className="outline-button hero-secondary-button" type="button" onClick={handleViewAccommodations}>
                  <span key={heroView.id} className="hero-secondary-label">{heroView.label}</span>
                </button>
              </div>
            </div>
          </div>

          <div className="site-shell hero-search-shell">
            <SearchBar className="home-hero-search-desktop" onSearch={handleSearch} />
            <SearchBar className="home-hero-search-mobile" isMobileTrigger onSearch={handleSearch} />
          </div>
        </div>
      </section>

      <main className="site-shell storefront-main">
        <section className="storefront-intro-section" id="accommodations" aria-label="Find your perfect stay">
          <h2 className="accommodation-section-heading">
            Find Your Perfect Stay
            <Heart className="accommodation-heading-heart" aria-hidden="true" />
          </h2>
        </section>

        {pageError ? (
          <div className="public-inline-alert" role="alert">
            <span>{pageError}</span>
            {!hasLoadedAccommodations ? (
              <button className="outline-button" type="button" onClick={() => setAccommodationRetryKey((key) => key + 1)}>
                Retry
              </button>
            ) : null}
          </div>
        ) : null}
        {searchSummary ? (
          <div className="public-search-summary" role="status" aria-live="polite">
            <CalendarDays aria-hidden="true" size={18} />
            <div>
              <strong>{searchSummary.title}</strong>
              {searchSummary.dates ? <span>{searchSummary.dates}</span> : null}
            </div>
          </div>
        ) : null}
        {isLoading ? <StorefrontLoading /> : null}
        {!isLoading && hasLoadedAccommodations ? (
          <>
            <AccommodationCarousel
              id="rooms"
              title="Featured Rooms"
              accommodations={groupedAccommodations.rooms}
              emptyMessage="No room listings are currently available from the accommodation database."
              actionTo="/rooms"
            />
            <AccommodationCarousel
              id="cottages"
              title="Featured Cottages"
              accommodations={groupedAccommodations.cottages}
              emptyMessage="No cottage listings are currently available from the accommodation database."
              actionTo="/rooms"
            />
            <AccommodationCarousel
              id="function-halls"
              title="Featured Function Hall"
              accommodations={groupedAccommodations.functionHalls}
              emptyMessage="No function hall listing is currently available in the accommodation database."
              actionTo="/function-hall"
            />

            {groupedAccommodations.exclusiveResort ? (
              <section className="exclusive-resort-feature" id="exclusive-resort" aria-labelledby="exclusive-resort-heading">
                <div className="exclusive-resort-feature-media">
                  {groupedAccommodations.exclusiveResort.primary_image_url ? (
                    <img
                      src={resolveAssetUrl(groupedAccommodations.exclusiveResort.primary_image_url)}
                      alt={groupedAccommodations.exclusiveResort.name}
                      loading="lazy"
                    />
                  ) : null}
                </div>
                <div className="exclusive-resort-feature-copy">
                  <p className="eyebrow">Exclusive Resort Rental</p>
                  <h2 id="exclusive-resort-heading">Reserve the Entire Resort</h2>
                  <p>Enjoy private use of DMD Family Resort for your group, celebration, or special event.</p>
                  <p className="exclusive-resort-feature-rate">
                    Starting at {formatCurrency(groupedAccommodations.exclusiveResort.price_per_night)} / day
                  </p>
                  <Link className="solid-button" to={`/exclusive-resort/${groupedAccommodations.exclusiveResort.id}`}>
                    View Exclusive Resort Rental
                  </Link>
                </div>
              </section>
            ) : null}
          </>
        ) : null}

        <section className="storefront-info-grid">
          <article className="storefront-info-card homepage-about-card">
            <div className="homepage-info-card-heading">
              <span className="homepage-info-card-icon" aria-hidden="true"><Hotel size={18} /></span>
              <p className="eyebrow">About Us</p>
            </div>
            <h2>About DMD Resort</h2>
            <p>
              A relaxing place for families, friends, and groups to enjoy comfortable stays, gatherings, and special occasions.
            </p>
            <ul className="homepage-info-highlights">
              <li><Hotel size={15} aria-hidden="true" /> Rooms &amp; Cottages</li>
              <li><CalendarDays size={15} aria-hidden="true" /> Function Hall</li>
              <li><Users size={15} aria-hidden="true" /> Family-friendly Facilities</li>
            </ul>
            <Link className="outline-button homepage-info-action" to="/about">Learn More</Link>
          </article>

          <article className="storefront-info-card homepage-contact-card">
            <div className="homepage-info-card-heading">
              <span className="homepage-info-card-icon" aria-hidden="true"><MapPin size={18} /></span>
              <p className="eyebrow">Contact</p>
            </div>
            <h2>Contact / Location</h2>
            <p>Need help or planning a visit?</p>
            <div className="homepage-contact-details">
              {publicSettings.contact?.resort_address ? (
                <div><MapPin size={15} aria-hidden="true" /><span><small>Location</small><strong>{publicSettings.contact.resort_address}</strong></span></div>
              ) : null}
              {publicSettings.contact?.primary_phone || publicSettings.contact?.secondary_phone ? (
                <div><Phone size={15} aria-hidden="true" /><span><small>Contact number</small><strong>{publicSettings.contact.primary_phone || publicSettings.contact.secondary_phone}</strong></span></div>
              ) : null}
              {publicSettings.contact?.email ? (
                <div><Mail size={15} aria-hidden="true" /><span><small>Email address</small><strong>{publicSettings.contact.email}</strong></span></div>
              ) : null}
            </div>
            <div className="homepage-info-actions">
              <a className="outline-button" href={publicSettings.contact?.google_maps_url || 'https://maps.app.goo.gl/UGcrrgEkEkJMJLEr7'} target="_blank" rel="noopener noreferrer">
                <Navigation size={15} aria-hidden="true" /> Get Directions
              </a>
              <Link className="solid-button" to="/contact">Contact Us</Link>
            </div>
          </article>
        </section>
      </main>
    </div>
  )
}

const heroAccommodationViews = [
  { id: 'rooms', label: 'View Rooms' },
  { id: 'cottages', label: 'View Cottages' },
  { id: 'function-halls', label: 'View Function Hall' },
  { id: 'exclusive-resort', label: 'View Exclusive Resort' },
]

function formatCurrency(value) {
  const amount = Number(value ?? 0)

  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    maximumFractionDigits: 0,
  }).format(amount)
}

function formatStayDate(value) {
  const date = new Date(`${value}T00:00:00`)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(date)
}

function StorefrontLoading() {
  return (
    <section className="storefront-loading-section" aria-label="Loading accommodations">
      <InlineLoader message="Loading stays..." />
    </section>
  )
}

export default Home

