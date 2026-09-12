import { useCallback, useEffect, useMemo, useState } from 'react'
import ManagerLayout from '../components/ManagerLayout'
import PaginationControls from '../components/PaginationControls'
import { ToastMessage } from '../context/ToastContext'
import InventoryCategorySelect from '../components/InventoryCategorySelect'
import {
  INVENTORY_CATEGORY_OPTIONS,
  INVENTORY_CATEGORY_OTHER_VALUE,
  splitInventoryCategories,
} from '../constants/inventoryCategories'
import {
  getManagerInventoryAsset,
  getManagerInventoryAssets,
  updateManagerInventoryAssetCondition,
  updateManagerInventoryAssetStatus,
} from '../lib/api'

function ManagerInventory() {
  const [assets, setAssets] = useState([])
  const [summary, setSummary] = useState({})
  const [meta, setMeta] = useState({ conditions: [], statuses: [], location_types: [] })
  const [selectedAsset, setSelectedAsset] = useState(null)
  const [history, setHistory] = useState([])
  const [filters, setFilters] = useState({ search: '', category: '', condition: '', status: '', location_type: '' })
  const [isLoading, setIsLoading] = useState(true)
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [currentPage, setCurrentPage] = useState(1)
  const pageSize = 10

  const filterParams = useMemo(
    () => Object.fromEntries(Object.entries(filters).map(([key, value]) => [key, value.trim?.() || value || undefined])),
    [filters],
  )

  const categories = useMemo(() => splitInventoryCategories(assets.map((asset) => asset.category).filter(Boolean)), [assets])
  const pageCount = Math.max(1, Math.ceil(assets.length / pageSize))
  const visibleAssets = assets.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  const loadAssets = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getManagerInventoryAssets(filterParams)
      setAssets(result.assets)
      setSummary(result.summary)
      setMeta(result.meta)
    } catch {
      setPageError('Unable to load inventory assets.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(loadAssets, 250)

    return () => window.clearTimeout(timeoutId)
  }, [loadAssets])

  useEffect(() => {
    setCurrentPage(1)
  }, [filterParams])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
  }

  async function selectAsset(asset) {
    setPageError('')

    try {
      const result = await getManagerInventoryAsset(asset.id)
      setSelectedAsset(result.asset)
      setHistory(result.history)
    } catch {
      setPageError('Unable to load asset history.')
    }
  }

  async function changeCondition(asset, condition) {
    await changeAsset(asset, () => updateManagerInventoryAssetCondition(asset.id, { condition }))
  }

  async function changeStatus(asset, status) {
    await changeAsset(asset, () => updateManagerInventoryAssetStatus(asset.id, { status }))
  }

  async function changeAsset(asset, request) {
    setPageError('')
    setPageMessage('')

    try {
      const result = await request()
      setAssets((current) => current.map((item) => (item.id === result.asset.id ? result.asset : item)))
      if (selectedAsset?.id === asset.id) {
        setSelectedAsset(result.asset)
      }
      setPageMessage(result.message)
    } catch (error) {
      const firstError = Object.values(error?.response?.data?.errors ?? {})[0]?.[0]
      setPageError(firstError ?? 'Unable to update asset.')
    }
  }

  const cards = [
    ['Total Assets', summary.total_assets],
    ['Available', summary.available],
    ['Assigned', summary.assigned],
    ['Maintenance', summary.under_maintenance],
    ['Damaged', summary.damaged],
  ]

  return (
    <ManagerLayout eyebrow="Inventory" title="Inventory Operations">
      <section className="admin-summary-grid inventory-summary-grid manager-inventory-summary-grid" aria-label="Inventory summary">
        {cards.map(([label, value]) => (
          <article className="admin-summary-card" key={label}>
            <span>{value ?? 0}</span>
            <strong>{label}</strong>
          </article>
        ))}
      </section>
      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-management-grid inventory-management-grid manager-inventory-management-grid">
        <div className="admin-table-panel">
          <div className="admin-filter-bar inventory-filter-bar manager-inventory-filter-bar">
            <label><span>Search</span><input name="search" value={filters.search} onChange={updateFilter} /></label>
            <label>
              <span>Category</span>
              <InventoryCategorySelect
                ariaLabel="Inventory category filter"
                value={filters.category}
                onChange={(nextValue) => setFilters((current) => ({ ...current, category: nextValue }))}
                options={[
                  ...INVENTORY_CATEGORY_OPTIONS,
                  { value: INVENTORY_CATEGORY_OTHER_VALUE, label: INVENTORY_CATEGORY_OTHER_VALUE },
                  ...categories.custom.map((category) => ({ value: category, label: category })),
                ]}
                allLabel="All categories"
                placeholder="All categories"
              />
            </label>
            <label><span>Condition</span><select name="condition" value={filters.condition} onChange={updateFilter}><option value="">All conditions</option>{meta.conditions.map((condition) => <option key={condition} value={condition}>{condition.replaceAll('_', ' ')}</option>)}</select></label>
            <label><span>Status</span><select name="status" value={filters.status} onChange={updateFilter}><option value="">All statuses</option>{meta.statuses.map((status) => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}</select></label>
            <label><span>Location</span><select name="location_type" value={filters.location_type} onChange={updateFilter}><option value="">All locations</option>{meta.location_types.map((location) => <option key={location} value={location}>{location.replaceAll('_', ' ')}</option>)}</select></label>
          </div>
          <div className="admin-table-scroll">
            <table className="admin-data-table inventory-table manager-inventory-table">
              <thead><tr><th>Asset Code</th><th>Item</th><th>Category</th><th>Qty</th><th>Location</th><th>Condition</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
                {isLoading ? <tr><td colSpan="8" aria-label="Loading assets..." /></tr> : null}
                {!isLoading && assets.length === 0 ? <tr><td colSpan="8">No inventory assets match the filters.</td></tr> : null}
                {!isLoading ? visibleAssets.map((asset) => (
                  <tr key={asset.id}>
                    <td><strong>{asset.asset_code}</strong></td>
                    <td>{asset.name}</td>
                    <td>{asset.category}</td>
                    <td>{asset.quantity}</td>
                    <td>{asset.location_label}</td>
                    <td><select value={asset.condition} onChange={(event) => changeCondition(asset, event.target.value)} disabled={asset.status === 'retired'}>{meta.conditions.map((condition) => <option key={condition} value={condition}>{condition.replaceAll('_', ' ')}</option>)}</select></td>
                    <td><select value={asset.status} onChange={(event) => changeStatus(asset, event.target.value)} disabled={asset.status === 'retired'}>{meta.statuses.filter((status) => status !== 'retired').map((status) => <option key={status} value={status}>{status.replaceAll('_', ' ')}</option>)}</select></td>
                    <td><button type="button" onClick={() => selectAsset(asset)}>History</button></td>
                  </tr>
                )) : null}
              </tbody>
            </table>
          </div>
          {!isLoading && assets.length > 0 ? <PaginationControls page={currentPage} pageCount={pageCount} total={assets.length} pageSize={pageSize} label="inventory assets" onPageChange={setCurrentPage} /> : null}
        </div>
      </section>

      {selectedAsset ? (
        <>
          <button
            className="manager-inventory-drawer-backdrop"
            type="button"
            aria-label="Close asset history"
            onClick={() => setSelectedAsset(null)}
          />
          <aside className="admin-form-panel manager-inventory-drawer" aria-label="Asset details and history">
            <div className="manager-inventory-drawer-header">
              <div>
                <p className="eyebrow">Asset History</p>
                <h2>{selectedAsset.name}</h2>
              </div>
              <button type="button" onClick={() => setSelectedAsset(null)} aria-label="Close asset history">
                X
              </button>
            </div>

            <dl className="admin-detail-list manager-inventory-detail-list">
              <div><dt>Asset Code</dt><dd>{selectedAsset.asset_code}</dd></div>
              <div><dt>Category</dt><dd>{selectedAsset.category}</dd></div>
              <div><dt>Quantity</dt><dd>{selectedAsset.quantity}</dd></div>
              <div><dt>Location</dt><dd>{selectedAsset.location_label}</dd></div>
              <div><dt>Condition</dt><dd>{selectedAsset.condition?.replaceAll('_', ' ')}</dd></div>
              <div><dt>Status</dt><dd>{selectedAsset.status?.replaceAll('_', ' ')}</dd></div>
            </dl>

            <div className="inventory-history-list">
              {history.length > 0 ? history.map((entry) => (
                <article key={entry.id}>
                  <strong>{entry.action.replaceAll('_', ' ')}</strong>
                  <span>{entry.performed_by?.name ?? 'System'} - {entry.created_at ? new Date(entry.created_at).toLocaleString() : ''}</span>
                  {entry.remarks ? <p>{entry.remarks}</p> : null}
                </article>
              )) : <p>No history recorded for this asset.</p>}
            </div>
          </aside>
        </>
      ) : null}
    </ManagerLayout>
  )
}

export default ManagerInventory
