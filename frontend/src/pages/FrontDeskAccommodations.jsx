import { useCallback, useEffect, useMemo, useState } from 'react'
import { Banknote, BedDouble, CalendarDays, CheckCircle2, Hotel, Search, Sparkles, Users } from 'lucide-react'
import FrontDeskLayout from '../components/FrontDeskLayout'
import ResortImagePlaceholder from '../components/ResortImagePlaceholder'
import { getFrontDeskAccommodations, getFrontDeskAccommodation, resolveAssetUrl } from '../lib/api'

const statusLabels = {
  available: 'Available',
  occupied: 'Occupied',
  needs_cleaning: 'Needs Cleaning',
  cleaning: 'Cleaning in Progress',
  unavailable: 'Maintenance / Unavailable',
  maintenance: 'Maintenance / Unavailable',
}

const statusTones = {
  available: 'green',
  occupied: 'blue',
  needs_cleaning: 'amber',
  cleaning: 'blue',
  unavailable: 'muted',
  maintenance: 'slate',
}

const typeLabels = {
  room: 'Room',
  cottage: 'Cottage',
  function_hall: 'Function Hall',
  exclusive_resort: 'Exclusive Resort Rental',
}

function imageProps(accommodation, preferredSize = 'medium') {
  const image = accommodation.images?.find((item) => item.is_primary) ?? accommodation.images?.[0]
  const fallback = accommodation.primary_image_url ?? accommodation.image_path
  const src = image?.[`${preferredSize}_url`] ?? image?.medium_url ?? image?.large_url ?? image?.url ?? fallback
  const srcSet = [
    image?.thumbnail_url ? `${resolveAssetUrl(image.thumbnail_url)} 640w` : null,
    image?.medium_url ? `${resolveAssetUrl(image.medium_url)} 960w` : null,
    image?.large_url ? `${resolveAssetUrl(image.large_url)} 1440w` : null,
  ].filter(Boolean).join(', ')

  return {
    src: resolveAssetUrl(src),
    ...(srcSet ? { srcSet } : {}),
  }
}

function FrontDeskAccommodations() {
  const [accommodations, setAccommodations] = useState([])
  const [summary, setSummary] = useState({
    total_accommodations: 0,
    available: 0,
    occupied: 0,
    maintenance: 0,
  })
  const [meta, setMeta] = useState({ types: [], statuses: [] })
  const [filters, setFilters] = useState({ search: '', type: '', status: '' })
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [selectedAccommodation, setSelectedAccommodation] = useState(null)
  const [isDetailLoading, setIsDetailLoading] = useState(false)
  const [detailError, setDetailError] = useState('')

  const filterParams = useMemo(
    () => ({
      search: filters.search.trim() || undefined,
      type: filters.type || undefined,
      status: filters.status || undefined,
    }),
    [filters],
  )

  const loadAccommodations = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getFrontDeskAccommodations(filterParams)
      setAccommodations(result.accommodations)
      setMeta(result.meta ?? { types: [], statuses: [] })
      setSummary(result.meta?.summary ?? {
        total_accommodations: result.accommodations.length,
        available: result.accommodations.filter((item) => item.operational_status === 'available').length,
        occupied: result.accommodations.filter((item) => item.operational_status === 'occupied').length,
        maintenance: result.accommodations.filter((item) => ['maintenance', 'unavailable'].includes(item.operational_status)).length,
      })
    } catch {
      setPageError("We couldn't load accommodations right now.")
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(loadAccommodations, 200)
    return () => window.clearTimeout(timeoutId)
  }, [loadAccommodations])

  async function openDetail(accommodation) {
    setSelectedAccommodation(accommodation)
    setDetailError('')
    setIsDetailLoading(true)

    try {
      const result = await getFrontDeskAccommodation(accommodation.id)
      setSelectedAccommodation(result.accommodation)
    } catch {
      setDetailError('Unable to load accommodation details.')
    } finally {
      setIsDetailLoading(false)
    }
  }

  const types = meta.types?.length ? meta.types : [...new Set(accommodations.map((item) => item.type).filter(Boolean))]
  const statuses = meta.statuses?.length ? meta.statuses : ['available', 'occupied', 'needs_cleaning', 'cleaning', 'unavailable', 'maintenance']

  return (
    <FrontDeskLayout eyebrow="Accommodations" title="Operational Accommodations">
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="frontdesk-accommodations-header">
        <div>
          <p className="eyebrow">Front Desk</p>
          <h2>Accommodations</h2>
          <p className="frontdesk-accommodations-subtitle">Quickly check availability, occupancy, and room details for guest operations.</p>
        </div>
      </section>

      <section className="dashboard-kpi-grid frontdesk-kpi-grid frontdesk-accommodations-summary" aria-label="Accommodation summary">
        <SummaryCard label="Total Accommodations" value={summary.total_accommodations} icon={Hotel} tone="green" note="All room and cottage listings" />
        <SummaryCard label="Available" value={summary.available} icon={BedDouble} tone="teal" note="Ready for assignment" />
        <SummaryCard label="Occupied" value={summary.occupied} icon={Users} tone="blue" note="Currently in use" />
        <SummaryCard label="Maintenance / Unavailable" value={summary.maintenance} icon={CalendarDays} tone="amber" note="Not ready for check-in" />
      </section>

      <section className="frontdesk-accommodations-panel">
        <div className="frontdesk-accommodations-toolbar">
          <label className="frontdesk-accommodations-search">
            <Search size={16} aria-hidden="true" />
            <input
              type="search"
              placeholder="Search accommodations..."
              value={filters.search}
              onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
            />
          </label>
          <div className="frontdesk-accommodations-filters">
            <label>
              <span>Type</span>
              <select
                value={filters.type}
                onChange={(event) => setFilters((current) => ({ ...current, type: event.target.value }))}
              >
                <option value="">All</option>
                {types.map((type) => (
                  <option key={type} value={type}>{typeLabels[type] ?? type}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Status</span>
              <select
                value={filters.status}
                onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}
              >
                <option value="">All</option>
                {statuses.map((status) => (
                  <option key={status} value={status}>{statusLabels[status] ?? status}</option>
                ))}
              </select>
            </label>
          </div>
        </div>

        {isLoading ? <div className="frontdesk-section-state" role="status" aria-label="Loading stays..." /> : null}

        {!isLoading && accommodations.length === 0 ? (
          <div className="frontdesk-empty-state frontdesk-accommodations-empty">
            <Hotel size={28} aria-hidden="true" />
            <div>
              <strong>No stays found</strong>
              <p>Adjust your search or filters to see matching rooms and cottages.</p>
            </div>
          </div>
        ) : null}

        {!isLoading && accommodations.length > 0 ? (
          <div className="frontdesk-accommodations-grid">
            {accommodations.map((accommodation) => (
              <article key={accommodation.id} className="frontdesk-accommodation-card">
                <button type="button" className="frontdesk-accommodation-media" style={{ aspectRatio: '4 / 3' }} onClick={() => openDetail(accommodation)}>
                  {accommodation.primary_image_url || accommodation.image_path ? (
                    <img {...imageProps(accommodation, 'thumbnail')} sizes="(max-width: 768px) 100vw, 320px" loading="lazy" alt={accommodation.name} />
                  ) : (
                    <ResortImagePlaceholder label="Photo pending" variant={accommodation.category ?? accommodation.type} />
                  )}
                </button>
                <div className="frontdesk-accommodation-body">
                  <div className="frontdesk-accommodation-head">
                    <div>
                      <h3>{accommodation.name}</h3>
                      <p>{typeLabels[accommodation.type] ?? accommodation.type_label ?? 'Accommodation'}</p>
                    </div>
                    <span className={`frontdesk-pill frontdesk-pill-status tone-${statusTones[accommodation.operational_status] ?? 'slate'}`}>
                      {statusLabels[accommodation.operational_status] ?? accommodation.operational_status ?? accommodation.status}
                    </span>
                  </div>
                  <dl className="frontdesk-accommodation-facts">
                    <div>
                      <dt>Capacity</dt>
                      <dd>Up to {accommodation.capacity} guests</dd>
                    </div>
                    <div>
                      <dt>Price</dt>
                      <dd>PHP {Number(accommodation.price_per_night).toLocaleString()} / night</dd>
                    </div>
                  </dl>
                  <div className="frontdesk-accommodation-meta">
                    {accommodation.is_currently_occupied ? (
                      <p><strong>Occupied</strong>{accommodation.current_reservation?.guest_name ? `Guest: ${accommodation.current_reservation.guest_name}` : ''}</p>
                    ) : null}
                    {accommodation.next_reservation ? (
                      <p><strong>Next check-in</strong><span>{accommodation.next_reservation.check_in}</span></p>
                    ) : null}
                    {accommodation.amenity_names?.length ? (
                      <p><strong>Amenities</strong><span className="frontdesk-accommodation-amenity-value">{accommodation.amenity_names.join(', ')}</span></p>
                    ) : null}
                  </div>
                  <div className="frontdesk-accommodation-actions">
                    <button type="button" className="outline-button" onClick={() => openDetail(accommodation)}>View Details</button>
                  </div>
                </div>
              </article>
            ))}
          </div>
        ) : null}
      </section>

      {selectedAccommodation ? (
        <AccommodationDetailModal
          accommodation={selectedAccommodation}
          isLoading={isDetailLoading}
          error={detailError}
          onClose={() => setSelectedAccommodation(null)}
        />
      ) : null}
    </FrontDeskLayout>
  )
}

function SummaryCard({ label, value, icon: Icon, tone, note }) {
  return (
    <article className={`dashboard-kpi-card frontdesk-kpi-card frontdesk-kpi-tone-${tone}`}>
      <span className={`dashboard-kpi-icon frontdesk-kpi-icon tone-${tone}`}>
        <Icon size={24} strokeWidth={2.5} aria-hidden="true" />
      </span>
      <div>
        <p>{label}</p>
        <strong>{value.toLocaleString()}</strong>
        <small>{note}</small>
      </div>
    </article>
  )
}

function AccommodationDetailModal({ accommodation, isLoading, error, onClose }) {
  const operationalStatus = accommodation.operational_status ?? accommodation.status
  const statusLabel = statusLabels[operationalStatus] ?? operationalStatus

  return (
    <>
      <button className="frontdesk-modal-backdrop" type="button" aria-label="Close accommodation details" onClick={onClose} />
      <section className="frontdesk-modal frontdesk-accommodation-modal" role="dialog" aria-modal="true" aria-labelledby="frontdesk-accommodation-title">
        <div className="frontdesk-modal-header">
          <div>
            <p className="eyebrow">Accommodation Details</p>
            <h3 id="frontdesk-accommodation-title">{accommodation.name}</h3>
          </div>
          <div className="frontdesk-accommodation-modal-heading">
            <span className={`frontdesk-pill frontdesk-pill-status tone-${statusTones[operationalStatus] ?? 'slate'}`}>{statusLabel}</span>
            <button type="button" onClick={onClose} aria-label="Close dialog">X</button>
          </div>
        </div>
        {isLoading ? <div className="frontdesk-section-state" role="status" aria-label="Loading accommodation details..." /> : null}
        {error ? <div className="admin-inline-alert" role="alert">{error}</div> : null}
        {!isLoading && !error ? (
          <div className="frontdesk-accommodation-detail">
            <div className="frontdesk-accommodation-detail-media">
              {accommodation.primary_image_url || accommodation.image_path ? (
                <img {...imageProps(accommodation)} sizes="(max-width: 768px) 100vw, 720px" loading="eager" alt={accommodation.name} />
              ) : (
                <ResortImagePlaceholder label="Photo pending" variant={accommodation.category ?? accommodation.type} />
              )}
            </div>
            <div className="frontdesk-accommodation-detail-grid">
              <DetailFact icon={Hotel} label="Type" value={accommodation.type_label ?? typeLabels[accommodation.type] ?? accommodation.type} />
              <DetailFact icon={Users} label="Capacity" value={`Up to ${accommodation.capacity} guests`} />
              <DetailFact icon={Banknote} label="Price" value={`PHP ${Number(accommodation.price_per_night).toLocaleString()} / night`} />
              <DetailFact icon={CheckCircle2} label="Status" value={statusLabel} />
            </div>
            <section className="frontdesk-accommodation-detail-section frontdesk-accommodation-description-section">
              <h4>Description</h4>
              <p>{accommodation.description ?? 'No description available.'}</p>
            </section>
            {accommodation.amenity_names?.length ? (
              <section className="frontdesk-accommodation-detail-section frontdesk-accommodation-amenities-section">
                <h4><Sparkles size={14} aria-hidden="true" /> Amenities</h4>
                <div className="frontdesk-accommodation-amenities">
                  {accommodation.amenity_names.map((amenity) => <span key={amenity}>{amenity}</span>)}
                </div>
              </section>
            ) : null}
              {accommodation.current_reservation ? <div><dt>Current Occupancy</dt><dd>{accommodation.current_reservation.booking_reference} · {accommodation.current_reservation.guest_name}</dd></div> : null}
              {accommodation.next_reservation ? <div><dt>Next Reservation</dt><dd>{accommodation.next_reservation.booking_reference} · {accommodation.next_reservation.check_in}</dd></div> : null}
          </div>
        ) : null}
      </section>
    </>
  )
}

function DetailFact({ icon: Icon, label, value }) {
  return (
    <div className="frontdesk-accommodation-detail-fact">
      <span className="frontdesk-accommodation-detail-fact-icon"><Icon size={15} aria-hidden="true" /></span>
      <div>
        <span className="frontdesk-accommodation-detail-fact-label">{label}</span>
        <strong>{value}</strong>
      </div>
    </div>
  )
}

export default FrontDeskAccommodations
