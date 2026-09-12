import { useCallback, useEffect, useMemo, useState } from 'react'
import { AlertTriangle, Archive, Boxes, CheckCircle2, Eye, Search, UserRoundCheck, Wrench, X } from 'lucide-react'
import InventoryCategorySelect from '../components/InventoryCategorySelect'
import { ToastMessage } from '../context/ToastContext'
import AdminInventoryStock from './AdminInventoryStock'
import {
  INVENTORY_CATEGORY_OPTIONS,
  INVENTORY_CATEGORY_OTHER_VALUE,
  isPredefinedInventoryCategory,
  splitInventoryCategories,
} from '../constants/inventoryCategories'
import {
  assignAdminInventoryAsset,
  createAdminInventoryAsset,
  getAccommodations,
  getAdminInventoryAsset,
  getAdminInventoryAssets,
  updateAdminInventoryAsset,
  updateAdminInventoryAssetCondition,
  updateAdminInventoryAssetStatus,
} from '../lib/api'

const defaultFormValues = {
  name: '',
  category_option: '',
  custom_category: '',
  asset_code: '',
  quantity: 1,
  condition: 'good',
  status: 'available',
  location_type: 'storage',
  accommodation_id: '',
  location_name: 'Main storage',
  source_location_id: '',
  transfer_quantity: 1,
  destination_key: '',
  acquisition_date: '',
  purchase_cost: '',
  description: '',
  remarks: '',
}

const conditionLabels = {
  good: 'Good',
  fair: 'Fair',
  damaged: 'Damaged',
  under_repair: 'Under Repair',
  unusable: 'Unusable',
}

const statusLabels = {
  available: 'Available',
  assigned: 'Assigned',
  maintenance: 'Maintenance',
  retired: 'Retired',
}

const locationLabels = {
  accommodation: 'Room/Cottage',
  function_hall: 'Function Hall',
  pool_area: 'Pool Area',
  reception: 'Reception',
  storage: 'Storage',
  other: 'Other Area',
}

const transferDestinationOptions = [
  { key: 'storage:main-storage', label: 'Main Storage' },
  { key: 'storage:equipment-storage', label: 'Equipment Storage' },
  { key: 'maintenance:area', label: 'Maintenance Area' },
  { key: 'office:admin-office', label: 'Admin Office' },
  { key: 'pool:pool-area', label: 'Pool Area' },
]

function AdminInventory() {
  const [inventoryView, setInventoryView] = useState('assets')
  const [assets, setAssets] = useState([])
  const [summary, setSummary] = useState(null)
  const [meta, setMeta] = useState({ conditions: [], statuses: [], location_types: [] })
  const [accommodations, setAccommodations] = useState([])
  const [history, setHistory] = useState([])
  const [selectedAsset, setSelectedAsset] = useState(null)
  const [formValues, setFormValues] = useState(defaultFormValues)
  const [filters, setFilters] = useState({
    search: '',
    category: '',
    condition: '',
    status: '',
    location_type: '',
  })
  const [fieldErrors, setFieldErrors] = useState({})
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [pendingActionId, setPendingActionId] = useState(null)
  const [isDrawerOpen, setIsDrawerOpen] = useState(false)
  const [totalAssetCount, setTotalAssetCount] = useState(null)
  const [currentPage, setCurrentPage] = useState(1)
  const pageSize = 10

  const filterParams = useMemo(
    () => ({
      search: filters.search.trim() || undefined,
      category: filters.category.trim() || undefined,
      condition: filters.condition || undefined,
      status: filters.status || undefined,
      location_type: filters.location_type || undefined,
    }),
    [filters],
  )

  const loadInventory = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const [inventoryResult, accommodationResult] = await Promise.allSettled([
        getAdminInventoryAssets(filterParams),
        getAccommodations(),
      ])
      if (inventoryResult.status === 'rejected') throw inventoryResult.reason

      const inventoryData = inventoryResult.value
      setAssets(inventoryData.assets)
      setSummary(inventoryData.summary)
      setMeta(inventoryData.meta)
      setAccommodations(accommodationResult.status === 'fulfilled' ? accommodationResult.value : [])

      const hasRequestFilters = Object.values(filterParams).some(Boolean)

      if (!hasRequestFilters) {
        setTotalAssetCount(inventoryData.assets.length)
      }
    } catch {
      setPageError('Unable to load inventory assets right now.')
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      loadInventory()
    }, 250)

    return () => window.clearTimeout(timeoutId)
  }, [loadInventory])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
  }

  function updateFormField(event) {
    const { name, value } = event.target
    setFormValues((current) => ({
      ...current,
      [name]: value,
      ...(name === 'category_option' && value !== INVENTORY_CATEGORY_OTHER_VALUE
        ? { custom_category: '' }
        : {}),
      ...(name === 'location_type' && value === 'accommodation'
        ? { location_name: '', accommodation_id: current.accommodation_id || accommodations[0]?.id || '' }
        : {}),
      ...(name === 'location_type' && value !== 'accommodation'
        ? { accommodation_id: '', location_name: current.location_name || locationLabels[value] || '' }
        : {}),
    }))
    setFieldErrors((current) => ({ ...current, [name]: '' }))
    setPageError('')
    setPageMessage('')
  }

  function startCreate() {
    setSelectedAsset(null)
    setHistory([])
    setFormValues(defaultFormValues)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')
    setIsDrawerOpen(true)
  }

  async function selectAsset(asset) {
    setPageError('')
    setPageMessage('')

    try {
      const result = await getAdminInventoryAsset(asset.id)
      setSelectedAsset(result.asset)
      setHistory(result.history)
      setFormValues(assetToForm(result.asset))
      setFieldErrors({})
      setIsDrawerOpen(true)
    } catch {
      setPageError('Unable to load asset details.')
    }
  }

  function closeDrawer() {
    setIsDrawerOpen(false)
  }

  function clearFilters() {
    setFilters({
      search: '',
      category: '',
      condition: '',
      status: '',
      location_type: '',
    })
  }

  function assetToForm(asset) {
    const categoryOption = isPredefinedInventoryCategory(asset.category)
      ? asset.category
      : INVENTORY_CATEGORY_OTHER_VALUE

    return {
      name: asset.name ?? '',
      category_option: categoryOption,
      custom_category: categoryOption === INVENTORY_CATEGORY_OTHER_VALUE ? asset.category ?? '' : '',
      asset_code: asset.asset_code ?? '',
      quantity: asset.quantity ?? 1,
      condition: asset.condition ?? 'good',
      status: asset.status ?? 'available',
      location_type: asset.location_type ?? 'storage',
      accommodation_id: asset.accommodation_id ?? '',
      location_name: asset.location_name ?? '',
      source_location_id: asset.location_allocations?.[0]?.id ?? '',
      transfer_quantity: 1,
      destination_key: '',
      acquisition_date: asset.acquisition_date ?? '',
      purchase_cost: asset.purchase_cost ?? '',
      description: asset.description ?? '',
      remarks: '',
    }
  }

  function buildPayload() {
    const category = formValues.category_option === INVENTORY_CATEGORY_OTHER_VALUE
      ? formValues.custom_category.trim()
      : formValues.category_option.trim()

    return {
      name: formValues.name.trim(),
      category,
      quantity: Number(formValues.quantity),
      condition: formValues.condition,
      status: formValues.status,
      location_type: formValues.location_type,
      accommodation_id: formValues.location_type === 'accommodation' ? Number(formValues.accommodation_id) : null,
      location_name: formValues.location_type === 'accommodation' ? null : formValues.location_name.trim(),
      acquisition_date: formValues.acquisition_date || null,
      purchase_cost: formValues.purchase_cost === '' ? null : Number(formValues.purchase_cost),
      description: formValues.description.trim() || null,
      remarks: formValues.remarks.trim() || null,
    }
  }

  function buildTransferPayload() {
    return {
      source_location_id: Number(formValues.source_location_id),
      transfer_quantity: Number(formValues.transfer_quantity),
      destination_key: formValues.destination_key,
      remarks: formValues.remarks.trim() || null,
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setIsSaving(true)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')

    try {
      const result = selectedAsset
        ? await updateAdminInventoryAsset(selectedAsset.id, buildPayload())
        : await createAdminInventoryAsset(buildPayload())
      setPageMessage(result.message)
      await loadInventory()
      await selectAsset(result.asset)
    } catch (error) {
      const errors = error?.response?.data?.errors
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
      } else if (error?.response?.data?.message) {
        setPageError(error.response.data.message)
      } else {
        setPageError('Unable to save inventory asset.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  async function quickStatus(asset, status) {
    setPendingActionId(asset.id)
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateAdminInventoryAssetStatus(asset.id, {
        status,
        remarks: status === 'retired' ? 'Retired from inventory management.' : null,
      })
      setPageMessage(result.message)
      await loadInventory()
      if (selectedAsset?.id === asset.id) await selectAsset(result.asset)
    } catch (error) {
      setPageError(error?.response?.data?.errors?.status?.[0] ?? 'Unable to update asset status.')
    } finally {
      setPendingActionId(null)
    }
  }

  async function quickCondition(asset, condition) {
    setPendingActionId(asset.id)
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateAdminInventoryAssetCondition(asset.id, {
        condition,
        remarks: 'Condition updated from inventory table.',
      })
      setPageMessage(result.message)
      await loadInventory()
      if (selectedAsset?.id === asset.id) await selectAsset(result.asset)
    } catch (error) {
      setPageError(error?.response?.data?.errors?.condition?.[0] ?? 'Unable to update asset condition.')
    } finally {
      setPendingActionId(null)
    }
  }

  async function handleTransfer(event) {
    event.preventDefault()
    if (!selectedAsset) return

    setIsSaving(true)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')

    try {
      const result = await assignAdminInventoryAsset(selectedAsset.id, buildTransferPayload())
      setPageMessage(result.message)
      await loadInventory()
      await selectAsset(result.asset)
    } catch (error) {
      const errors = error?.response?.data?.errors
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
      } else {
        setPageError('Unable to assign inventory asset.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  function formatDate(value) {
    if (!value) return 'Not set'
    return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(value))
  }

  const categories = useMemo(() => splitInventoryCategories(assets.map((asset) => asset.category).filter(Boolean)), [assets])
  const hasActiveFilters = Object.values(filters).some(Boolean)
  const hasAnyAssets = (totalAssetCount ?? (!hasActiveFilters ? assets.length : 0)) > 0
  const totalPages = Math.max(1, Math.ceil(assets.length / pageSize))
  const visibleAssets = assets.slice((currentPage - 1) * pageSize, currentPage * pageSize)

  useEffect(() => {
    setCurrentPage(1)
  }, [filters.search, filters.category, filters.condition, filters.status, filters.location_type])

  useEffect(() => {
    setCurrentPage((page) => Math.min(page, totalPages))
  }, [totalPages])

  return (
    <>
      <nav className="admin-inventory-view-tabs" aria-label="Inventory sections">
        <button type="button" className={inventoryView === 'assets' ? 'is-active' : ''} onClick={() => setInventoryView('assets')}>Assets</button>
        <button type="button" className={inventoryView === 'stock' ? 'is-active' : ''} onClick={() => setInventoryView('stock')}>Stock Items</button>
        <button type="button" className={inventoryView === 'logs' ? 'is-active' : ''} onClick={() => setInventoryView('logs')}>Stock Logs</button>
      </nav>
      {inventoryView === 'assets' ? (
        <>
      <section className="admin-summary-grid inventory-summary-grid" aria-label="Inventory summary">
        {[
          ['Total Assets', summary?.total_assets ?? 0, 'summary-total', Boxes],
          ['Available', summary?.available ?? 0, 'summary-available', CheckCircle2],
          ['Assigned', summary?.assigned ?? 0, 'summary-assigned', UserRoundCheck],
          ['Under Maintenance', summary?.under_maintenance ?? 0, 'summary-maintenance', Wrench],
          ['Damaged', summary?.damaged ?? 0, 'summary-damaged', AlertTriangle],
        ].map(([label, value, modifier, Icon]) => (
          <article className={`admin-summary-card inventory-summary-card ${modifier}`} key={label}>
            <div className="inventory-summary-card-label"><span className="inventory-summary-icon"><Icon size={16} aria-hidden="true" /></span><span>{label}</span></div>
            <strong>{isLoading ? '...' : value}</strong>
          </article>
        ))}
      </section>

      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="admin-management-grid inventory-management-grid admin-inventory-management-grid">
        <div className="admin-table-panel">
          <div className="admin-table-heading admin-inventory-toolbar">
            <h2>Assets ({assets.length.toLocaleString()})</h2>
            <div className="admin-inventory-toolbar-actions">
              {hasActiveFilters ? <button type="button" onClick={clearFilters}>Clear filters</button> : null}
              <button className="primary-button admin-inventory-add-button" type="button" onClick={startCreate}>
                + Add Asset
              </button>
            </div>
          </div>
          <div className="admin-filter-bar inventory-filter-bar">
            <label>
              <span>Search</span>
              <div className="admin-inventory-search-input">
                <Search size={14} aria-hidden="true" />
                <input name="search" value={filters.search} onChange={updateFilter} placeholder="Asset, code, category" />
              </div>
            </label>
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
            <label>
              <span>Condition</span>
              <select name="condition" value={filters.condition} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.conditions ?? Object.keys(conditionLabels)).map((condition) => (
                  <option key={condition} value={condition}>{conditionLabels[condition]}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Status</span>
              <select name="status" value={filters.status} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.statuses ?? Object.keys(statusLabels)).map((status) => (
                  <option key={status} value={status}>{statusLabels[status]}</option>
                ))}
              </select>
            </label>
            <label>
              <span>Location</span>
              <select name="location_type" value={filters.location_type} onChange={updateFilter}>
                <option value="">All</option>
                {(meta.location_types ?? Object.keys(locationLabels)).map((type) => (
                  <option key={type} value={type}>{locationLabels[type]}</option>
                ))}
              </select>
            </label>
          </div>

          <div className="admin-table-scroll">
            <table className="admin-data-table inventory-table">
              <thead>
                <tr>
                  <th>Asset Code</th>
                  <th>Item</th>
                  <th>Category</th>
                  <th>Qty</th>
                  <th>Location</th>
                  <th>Condition</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? <tr><td colSpan="8" aria-label="Loading inventory assets..." /></tr> : null}
                {!isLoading && assets.length === 0 ? (
                  <tr>
                    <td colSpan="8">
                      {hasActiveFilters && hasAnyAssets
                        ? 'No assets match the current filters.'
                        : 'No inventory assets have been added yet.'}
                    </td>
                  </tr>
                ) : null}
                {!isLoading ? visibleAssets.map((asset) => (
                  <tr key={asset.id}>
                    <td><strong>{asset.asset_code}</strong></td>
                    <td>{asset.name}</td>
                    <td>{asset.category}</td>
                    <td>{asset.quantity}</td>
                    <td>{asset.location_label}</td>
                    <td>
                      <select value={asset.condition} onChange={(event) => quickCondition(asset, event.target.value)} disabled={pendingActionId === asset.id || asset.status === 'retired'}>
                        {Object.keys(conditionLabels).map((condition) => <option key={condition} value={condition}>{conditionLabels[condition]}</option>)}
                      </select>
                    </td>
                    <td><span className={`admin-status-pill status-${asset.status}`}>{statusLabels[asset.status]}</span></td>
                    <td>
                      <div className="admin-row-actions">
                        <button type="button" onClick={() => selectAsset(asset)} aria-label={`View ${asset.name}`} title="View asset">
                          <Eye size={14} aria-hidden="true" />
                        </button>
                        <button
                          type="button"
                          onClick={() => quickStatus(asset, 'maintenance')}
                          disabled={pendingActionId === asset.id || asset.status === 'retired'}
                          aria-label={`Mark ${asset.name} for maintenance`}
                          title="Mark for maintenance"
                        >
                          <Wrench size={14} aria-hidden="true" />
                        </button>
                        <button
                          type="button"
                          onClick={() => quickStatus(asset, 'retired')}
                          disabled={pendingActionId === asset.id || asset.status === 'retired'}
                          aria-label={`Retire ${asset.name}`}
                          title="Retire asset"
                        >
                          <Archive size={14} aria-hidden="true" />
                        </button>
                      </div>
                    </td>
                  </tr>
                )) : null}
              </tbody>
            </table>
          </div>
          {!isLoading && assets.length > 0 ? (
            <div className="admin-inventory-pagination" aria-label="Inventory pagination">
              <span>Showing {((currentPage - 1) * pageSize) + 1}–{Math.min(currentPage * pageSize, assets.length)} of {assets.length}</span>
              <div>
                <button type="button" onClick={() => setCurrentPage((page) => Math.max(1, page - 1))} disabled={currentPage === 1}>Previous</button>
                <strong>Page {currentPage} of {totalPages}</strong>
                <button type="button" onClick={() => setCurrentPage((page) => Math.min(totalPages, page + 1))} disabled={currentPage === totalPages}>Next</button>
              </div>
            </div>
          ) : null}
        </div>
      </section>

      {isDrawerOpen ? (
        <>
          <button
            className="admin-inventory-drawer-backdrop"
            type="button"
            aria-label="Close inventory asset panel"
            onClick={closeDrawer}
          />
          <aside className="admin-form-panel admin-inventory-drawer" aria-label="Inventory asset details">
            <div className="admin-inventory-drawer-header">
              <div>
                <h2>{selectedAsset ? selectedAsset.name : 'Add Asset'}</h2>
                {!selectedAsset ? <p>Create a new inventory asset.</p> : null}
              </div>
              <button type="button" onClick={closeDrawer} aria-label="Close inventory asset panel">
                <X size={17} aria-hidden="true" />
              </button>
            </div>

          <form className="admin-embedded-form" onSubmit={handleSubmit} noValidate>
            <section className="admin-inventory-form-section">
              <h3>Asset Information</h3>
              <label>
                <span>Asset Name</span>
                <input name="name" value={formValues.name} onChange={updateFormField} />
                {fieldErrors.name ? <small role="alert">{fieldErrors.name}</small> : null}
              </label>
              <div className="admin-form-row">
                <label>
                  <span>Category</span>
                  <InventoryCategorySelect
                    ariaLabel="Inventory category"
                    value={formValues.category_option}
                    onChange={(nextValue) => updateFormField({ target: { name: 'category_option', value: nextValue } })}
                    options={[
                      ...INVENTORY_CATEGORY_OPTIONS,
                      { value: INVENTORY_CATEGORY_OTHER_VALUE, label: INVENTORY_CATEGORY_OTHER_VALUE },
                    ]}
                    placeholder="Select category"
                  />
                </label>
                <label className="admin-inventory-generated-field">
                  <span>Asset Code</span>
                  <input name="asset_code" value={selectedAsset?.asset_code ?? ''} placeholder="Generated on save" readOnly />
                  <small>Generated automatically when the asset is saved.</small>
                </label>
              </div>
              {formValues.category_option === INVENTORY_CATEGORY_OTHER_VALUE ? (
                <label>
                  <span>Custom Category</span>
                  <input name="custom_category" value={formValues.custom_category} onChange={updateFormField} placeholder="Enter custom category" />
                  {fieldErrors.category ? <small role="alert">{fieldErrors.category}</small> : null}
                </label>
              ) : null}
              <div className="admin-form-row">
                <label>
                  <span>Quantity</span>
                  <input min="1" name="quantity" type="number" value={formValues.quantity} onChange={updateFormField} />
                </label>
                <label>
                  <span>Purchase Cost</span>
                  <div className="admin-currency-input">
                    <span aria-hidden="true">₱</span>
                    <input min="0" name="purchase_cost" type="number" step="0.01" value={formValues.purchase_cost} onChange={updateFormField} />
                  </div>
                </label>
              </div>
            </section>

            <section className="admin-inventory-form-section">
              <h3>Condition &amp; Status</h3>
              <div className="admin-form-row">
              <label>
                <span>Condition</span>
                <select name="condition" value={formValues.condition} onChange={updateFormField}>
                  {Object.keys(conditionLabels).map((condition) => <option key={condition} value={condition}>{conditionLabels[condition]}</option>)}
                </select>
              </label>
              <label>
                <span>Status</span>
                <select name="status" value={formValues.status} onChange={updateFormField}>
                  {Object.keys(statusLabels).map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
                </select>
              </label>
              </div>
            </section>

            <section className="admin-inventory-form-section">
              <h3>Location</h3>
              <LocationFields
                accommodations={accommodations}
                fieldErrors={fieldErrors}
                formValues={formValues}
                updateFormField={updateFormField}
              />
            </section>

            <section className="admin-inventory-form-section admin-inventory-form-section-additional">
              <h3>Additional details</h3>
              <label>
                <span>Acquisition Date</span>
                <input name="acquisition_date" type="date" value={formValues.acquisition_date} onChange={updateFormField} />
              </label>
              <label>
                <span>Description / Remarks</span>
                <textarea name="description" rows="3" value={formValues.description} onChange={updateFormField} />
              </label>
              <label>
                <span>Change Remarks</span>
                <textarea name="remarks" rows="2" value={formValues.remarks} onChange={updateFormField} />
              </label>
            </section>

            <div className="admin-inventory-drawer-footer">
              <button className="outline-button" type="button" onClick={closeDrawer}>Cancel</button>
              <button className="outline-button admin-inventory-clear-button" type="button" onClick={startCreate}>Clear</button>
              <button className="primary-button" type="submit" disabled={isSaving || selectedAsset?.status === 'retired'}>
                {isSaving ? 'Saving...' : selectedAsset ? 'Save Changes' : 'Add Asset'}
              </button>
            </div>
          </form>

          {selectedAsset ? (
            <form className="admin-password-reset-panel" onSubmit={handleTransfer} noValidate>
              <div>
                <p className="eyebrow">Assignment</p>
                <h2>Assign or transfer</h2>
                <p className="inventory-transfer-summary">
                  Current Location: <strong>{selectedAsset.location_label}</strong>
                </p>
                <p className="inventory-transfer-summary">
                  Available Quantity: <strong>{selectedAsset.quantity}</strong>
                </p>
              </div>
              <label>
                <span>Source Location</span>
                <select name="source_location_id" value={formValues.source_location_id} onChange={updateFormField}>
                  <option value="">Select source location</option>
                  {(selectedAsset.location_allocations ?? []).map((allocation) => (
                    <option key={allocation.id} value={allocation.id}>
                      {allocation.location_label} ({allocation.quantity})
                    </option>
                  ))}
                </select>
                {fieldErrors.source_location_id ? <small role="alert">{fieldErrors.source_location_id}</small> : null}
              </label>
              <label>
                <span>Transfer Quantity</span>
                <input min="1" name="transfer_quantity" type="number" value={formValues.transfer_quantity} onChange={updateFormField} />
                {fieldErrors.transfer_quantity ? <small role="alert">{fieldErrors.transfer_quantity}</small> : null}
              </label>
              <label>
                <span>Transfer To</span>
                <select name="destination_key" value={formValues.destination_key} onChange={updateFormField}>
                  <option value="">Select destination</option>
                  {transferDestinationOptions.map((option) => (
                    <option key={option.key} value={option.key}>{option.label}</option>
                  ))}
                  {accommodations.map((accommodation) => (
                    <option key={`accommodation-${accommodation.id}`} value={`accommodation:${accommodation.id}`}>
                      {accommodation.name}
                    </option>
                  ))}
                </select>
                {fieldErrors.destination_key ? <small role="alert">{fieldErrors.destination_key}</small> : null}
              </label>
              <label>
                <span>Transfer Remarks</span>
                <textarea name="remarks" rows="2" value={formValues.remarks} onChange={updateFormField} />
              </label>
              <button className="outline-button" type="submit" disabled={isSaving || selectedAsset.status === 'retired'}>
                Assign / Transfer
              </button>
            </form>
          ) : null}

          {selectedAsset ? (
            <section className="admin-password-reset-panel">
              <div>
                <p className="eyebrow">Asset History</p>
                <h2>Audit trail</h2>
              </div>
              <div className="inventory-history-list">
                {history.length > 0 ? history.map((entry) => (
                  <article key={entry.id}>
                    <strong>{entry.action.replaceAll('_', ' ')}</strong>
                    <span>{formatDate(entry.created_at)} · {entry.performed_by?.name ?? 'System'}</span>
                    {entry.remarks ? <p>{entry.remarks}</p> : null}
                  </article>
                )) : <p>No history recorded yet.</p>}
              </div>
            </section>
          ) : null}
          </aside>
        </>
      ) : null}
        </>
      ) : <AdminInventoryStock view={inventoryView} />}
    </>
  )
}

function LocationFields({ accommodations, fieldErrors, formValues, updateFormField }) {
  return (
    <div className="admin-form-row">
      <label>
        <span>Location Type</span>
        <select name="location_type" value={formValues.location_type} onChange={updateFormField}>
          {Object.keys(locationLabels).map((type) => <option key={type} value={type}>{locationLabels[type]}</option>)}
        </select>
      </label>
      {formValues.location_type === 'accommodation' ? (
        <label>
          <span>Room / Cottage</span>
          <select name="accommodation_id" value={formValues.accommodation_id} onChange={updateFormField}>
            <option value="">Select accommodation</option>
            {accommodations.map((accommodation) => <option key={accommodation.id} value={accommodation.id}>{accommodation.name}</option>)}
          </select>
          {fieldErrors.accommodation_id ? <small role="alert">{fieldErrors.accommodation_id}</small> : null}
        </label>
      ) : (
        <label>
          <span>Location Name</span>
          <input name="location_name" value={formValues.location_name} onChange={updateFormField} />
          {fieldErrors.location_name ? <small role="alert">{fieldErrors.location_name}</small> : null}
        </label>
      )}
    </div>
  )
}

export default AdminInventory
