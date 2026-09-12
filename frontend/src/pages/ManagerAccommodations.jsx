import { useCallback, useEffect, useMemo, useState } from 'react'
import ManagerLayout from '../components/ManagerLayout'
import { ToastMessage } from '../context/ToastContext'
import { getManagerAccommodations, updateManagerAccommodationStatus } from '../lib/api'

const statusLabels = {
  available: 'Available',
  unavailable: 'Unavailable',
  maintenance: 'Maintenance',
  needs_cleaning: 'Needs Cleaning',
  in_progress: 'Cleaning',
  ready: 'Ready',
}

const typeLabels = {
  room: 'Room',
  cottage: 'Cottage',
  function_hall: 'Function Hall',
  exclusive_resort: 'Exclusive Resort Rental',
}

function ManagerAccommodations() {
  const [accommodations, setAccommodations] = useState([])
  const [meta, setMeta] = useState({ statuses: [], types: [] })
  const [filters, setFilters] = useState({ search: '', type: '', status: '' })
  const [isLoading, setIsLoading] = useState(true)
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')

  const filterParams = useMemo(
    () => ({
      search: filters.search.trim() || undefined,
      type: filters.type || undefined,
      status: filters.status || undefined,
    }),
    [filters],
  )

  const types = meta.types?.length ? meta.types : [...new Set(accommodations.map((item) => item.type).filter(Boolean))]

  const loadAccommodations = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getManagerAccommodations(filterParams)
      setAccommodations(result.accommodations)
      setMeta(result.meta)
    } catch {
      setPageError('Unable to load accommodations.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(loadAccommodations, 250)

    return () => window.clearTimeout(timeoutId)
  }, [loadAccommodations])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
  }

  async function changeStatus(accommodation, status) {
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateManagerAccommodationStatus(accommodation.id, status)
      setAccommodations((current) =>
        current.map((item) => (item.id === result.accommodation.id ? result.accommodation : item)),
      )
      setPageMessage(result.message)
    } catch {
      setPageError('Unable to update accommodation status.')
    }
  }

  return (
    <ManagerLayout eyebrow="Accommodations" title="Accommodation Operations">
      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-table-panel">
        <div className="admin-filter-bar">
          <label>
            <span>Search</span>
            <input name="search" value={filters.search} onChange={updateFilter} />
          </label>
          <label>
            <span>Type</span>
            <select name="type" value={filters.type} onChange={updateFilter}>
              <option value="">All types</option>
              {types.map((type) => <option key={type} value={type}>{typeLabels[type] ?? type}</option>)}
            </select>
          </label>
          <label>
            <span>Status</span>
            <select name="status" value={filters.status} onChange={updateFilter}>
              <option value="">All statuses</option>
              {(meta.statuses ?? Object.keys(statusLabels)).map((status) => (
                <option key={status} value={status}>{statusLabels[status] ?? status}</option>
              ))}
            </select>
          </label>
        </div>
        <div className="admin-table-scroll">
          <table className="admin-data-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Capacity</th>
                <th>Price</th>
                <th>Cleaning Status</th>
                <th>Status</th>
                <th>Reservations</th>
              </tr>
            </thead>
            <tbody>
              {isLoading ? <tr><td colSpan="7" aria-label="Loading stays..." /></tr> : null}
              {!isLoading && accommodations.length === 0 ? <tr><td colSpan="7">No stays match the filters.</td></tr> : null}
              {!isLoading ? accommodations.map((accommodation) => (
                <tr key={accommodation.id}>
                  <td><strong>{accommodation.name}</strong><small>{accommodation.slug}</small></td>
                  <td>{accommodation.type_label ?? typeLabels[accommodation.type] ?? 'Accommodation'}</td>
                  <td>{accommodation.capacity}</td>
                  <td>PHP {Number(accommodation.price_per_night).toLocaleString()}</td>
                  <td>{statusLabels[accommodation.housekeeping_status] ?? accommodation.housekeeping_status?.replaceAll('_', ' ')}</td>
                  <td>
                    <select
                      value={accommodation.status}
                      onChange={(event) => changeStatus(accommodation, event.target.value)}
                      aria-label={`Status for ${accommodation.name}`}
                    >
                      {(meta.statuses ?? Object.keys(statusLabels)).map((status) => (
                        <option key={status} value={status}>{statusLabels[status] ?? status}</option>
                      ))}
                    </select>
                  </td>
                  <td>{accommodation.reservations_count ?? 0}</td>
                </tr>
              )) : null}
            </tbody>
          </table>
        </div>
      </section>
    </ManagerLayout>
  )
}

export default ManagerAccommodations
