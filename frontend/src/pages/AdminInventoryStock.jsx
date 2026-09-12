import { useCallback, useEffect, useMemo, useState } from 'react'
import { ArrowDownToLine, ArrowUpFromLine, History, Package, Pencil, Plus, X } from 'lucide-react'
import {
  createAdminInventoryStockItem,
  getAdminInventoryStockItem,
  getAdminInventoryStockItems,
  getAdminInventoryStockMovements,
  recordAdminInventoryStockMovement,
  updateAdminInventoryStockItem,
} from '../lib/api'
import { ToastMessage } from '../context/ToastContext'

const emptyItem = {
  name: '',
  sku: '',
  category: '',
  unit: 'pieces',
  reorder_level: 0,
  location: '',
  is_active: true,
}

const emptyMovement = {
  quantity: 1,
  reason: '',
  reference: '',
  notes: '',
}

const statusLabels = {
  in_stock: 'In Stock',
  low_stock: 'Low Stock',
  out_of_stock: 'Out of Stock',
}

const movementLabels = {
  stock_in: 'Stock In',
  stock_out: 'Stock Out',
  adjustment: 'Adjustment',
}

function AdminInventoryStock({ view }) {
  const [items, setItems] = useState([])
  const [pagination, setPagination] = useState(null)
  const [summary, setSummary] = useState(null)
  const [meta, setMeta] = useState({ categories: [] })
  const [filters, setFilters] = useState({ search: '', category: '', status: '' })
  const [logs, setLogs] = useState([])
  const [logPagination, setLogPagination] = useState(null)
  const [logMeta, setLogMeta] = useState({ users: [] })
  const [logFilters, setLogFilters] = useState({ item_id: '', type: '', date: '', user_id: '' })
  const [isLoading, setIsLoading] = useState(true)
  const [pageError, setPageError] = useState('')
  const [pageMessage, setPageMessage] = useState('')
  const [modal, setModal] = useState(null)
  const [itemForm, setItemForm] = useState(emptyItem)
  const [movementForm, setMovementForm] = useState(emptyMovement)
  const [history, setHistory] = useState([])
  const [isSaving, setIsSaving] = useState(false)
  const [itemPage, setItemPage] = useState(1)
  const [logPage, setLogPage] = useState(1)

  const itemParams = useMemo(() => ({
    ...filters,
    page: itemPage,
    per_page: 10,
  }), [filters, itemPage])

  const logParams = useMemo(() => ({
    ...logFilters,
    page: logPage,
    per_page: 15,
  }), [logFilters, logPage])

  const loadItems = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getAdminInventoryStockItems(itemParams)
      setItems(result.items)
      setPagination(result.pagination)
      setSummary(result.summary)
      setMeta(result.meta)
    } catch {
      setPageError('Unable to load stock items.')
    } finally {
      setIsLoading(false)
    }
  }, [itemParams])

  const loadLogs = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const result = await getAdminInventoryStockMovements(logParams)
      setLogs(result.movements)
      setLogPagination(result.pagination)
      setLogMeta(result.meta)
    } catch {
      setPageError('Unable to load stock logs.')
    } finally {
      setIsLoading(false)
    }
  }, [logParams])

  useEffect(() => {
    if (view === 'stock') loadItems()
  }, [view, loadItems])

  useEffect(() => {
    if (view === 'logs') {
      loadLogs()
      getAdminInventoryStockItems({ per_page: 50 }).then((result) => {
        setItems(result.items)
        setSummary(result.summary)
      }).catch(() => {})
    }
  }, [view, loadLogs])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({ ...current, [name]: value }))
    setItemPage(1)
  }

  function updateLogFilter(event) {
    const { name, value } = event.target
    setLogFilters((current) => ({ ...current, [name]: value }))
    setLogPage(1)
  }

  function openNewItem() {
    setItemForm(emptyItem)
    setPageError('')
    setModal({ type: 'item' })
  }

  async function openEditItem(item) {
    try {
      const result = await getAdminInventoryStockItem(item.id)
      setItemForm({
        name: result.item.name,
        sku: result.item.sku,
        category: result.item.category,
        unit: result.item.unit,
        reorder_level: result.item.reorder_level,
        location: result.item.location ?? '',
        is_active: result.item.is_active,
      })
      setPageError('')
      setModal({ type: 'item', item: result.item })
    } catch {
      setPageError('Unable to load stock item details.')
    }
  }

  async function openHistory(item) {
    try {
      const result = await getAdminInventoryStockItem(item.id)
      setHistory(result.movements)
      setModal({ type: 'history', item: result.item })
    } catch {
      setPageError('Unable to load stock history.')
    }
  }

  function openMovement(item, type) {
    setMovementForm(emptyMovement)
    setPageError('')
    setModal({ type, item })
  }

  async function saveItem(event) {
    event.preventDefault()
    setIsSaving(true)
    setPageError('')

    try {
      const result = modal.item
        ? await updateAdminInventoryStockItem(modal.item.id, itemForm)
        : await createAdminInventoryStockItem(itemForm)
      setPageMessage(result.message)
      setModal(null)
      await loadItems()
    } catch (error) {
      setPageError(error?.response?.data?.message ?? 'Unable to save stock item.')
    } finally {
      setIsSaving(false)
    }
  }

  async function saveMovement(event) {
    event.preventDefault()
    setIsSaving(true)
    setPageError('')

    try {
      const result = await recordAdminInventoryStockMovement(
        modal.item.id,
        modal.type === 'stock_in' ? 'stock-in' : 'stock-out',
        { ...movementForm, quantity: Number(movementForm.quantity) },
      )
      setPageMessage(result.message)
      setModal(null)
      await loadItems()
    } catch (error) {
      const fieldError = error?.response?.data?.errors?.quantity?.[0]
      setPageError(fieldError ?? error?.response?.data?.message ?? 'Unable to record stock movement.')
    } finally {
      setIsSaving(false)
    }
  }

  const isItemForm = modal?.type === 'item'
  const isMovementForm = modal?.type === 'stock_in' || modal?.type === 'stock_out'
  const stockSummary = summary ?? { total_stock_items: 0, low_stock: 0, out_of_stock: 0, movements_today: 0 }
  const logPageInfo = logPagination ?? { current_page: 1, last_page: 1, from: 0, to: 0, total: 0 }
  const itemPageInfo = pagination ?? { current_page: 1, last_page: 1, from: 0, to: 0, total: 0 }

  return (
    <div className="admin-stock-module">
      <section className="admin-summary-grid admin-stock-summary-grid" aria-label="Stock summary">
        {[
          ['Total Stock Items', stockSummary.total_stock_items, 'stock-total', Package],
          ['Low Stock', stockSummary.low_stock, 'stock-low', ArrowDownToLine],
          ['Out of Stock', stockSummary.out_of_stock, 'stock-out', ArrowDownToLine],
          ['Movements Today', stockSummary.movements_today, 'stock-movement', History],
        ].map(([label, value, modifier, Icon]) => (
          <article className={`admin-summary-card admin-stock-summary-card ${modifier}`} key={label}>
            <div><span><Icon size={16} aria-hidden="true" /></span><strong>{label}</strong></div>
            <b>{value ?? 0}</b>
          </article>
        ))}
      </section>

      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      {view === 'stock' ? (
        <section className="admin-management-grid inventory-management-grid admin-inventory-management-grid">
          <div className="admin-table-panel">
            <div className="admin-table-heading admin-inventory-toolbar">
              <h2>Stock Items ({itemPageInfo.total})</h2>
              <button className="primary-button admin-inventory-add-button" type="button" onClick={openNewItem}><Plus size={15} aria-hidden="true" /> Add Stock Item</button>
            </div>
            <div className="admin-filter-bar inventory-filter-bar admin-stock-filter-bar">
              <label><span>Search</span><input name="search" value={filters.search} onChange={updateFilter} placeholder="Item, SKU, category" /></label>
              <label><span>Category</span><select name="category" value={filters.category} onChange={updateFilter}><option value="">All categories</option>{meta.categories.map((category) => <option key={category} value={category}>{category}</option>)}</select></label>
              <label><span>Status</span><select name="status" value={filters.status} onChange={updateFilter}><option value="">All statuses</option>{Object.entries(statusLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
            </div>
            <div className="admin-table-scroll">
              <table className="admin-data-table inventory-table admin-stock-table">
                <thead><tr><th>Item</th><th>Code</th><th>Category</th><th>Unit</th><th>Current Stock</th><th>Reorder Level</th><th>Status</th><th>Location</th><th>Actions</th></tr></thead>
                <tbody>
                  {isLoading ? <tr><td colSpan="9" aria-label="Loading stock items..." /></tr> : null}
                  {!isLoading && items.length === 0 ? <tr><td colSpan="9">No stock items match the current filters.</td></tr> : null}
                  {!isLoading ? items.map((item) => (
                    <tr key={item.id}>
                      <td><strong>{item.name}</strong></td>
                      <td><code>{item.sku}</code></td>
                      <td>{item.category}</td>
                      <td>{item.unit}</td>
                      <td><strong>{item.current_quantity}</strong></td>
                      <td>{item.reorder_level}</td>
                      <td><span className={`admin-status-pill stock-status-${item.status}`}>{statusLabels[item.status]}</span></td>
                      <td>{item.location || '—'}</td>
                      <td><div className="admin-row-actions">
                        <button type="button" title="Stock in" aria-label={`Stock in ${item.name}`} onClick={() => openMovement(item, 'stock_in')}><ArrowUpFromLine size={14} aria-hidden="true" /></button>
                        <button type="button" title="Stock out" aria-label={`Stock out ${item.name}`} onClick={() => openMovement(item, 'stock_out')} disabled={item.current_quantity === 0}><ArrowDownToLine size={14} aria-hidden="true" /></button>
                        <button type="button" title="Edit stock item" aria-label={`Edit ${item.name}`} onClick={() => openEditItem(item)}><Pencil size={14} aria-hidden="true" /></button>
                        <button type="button" title="View stock history" aria-label={`View history for ${item.name}`} onClick={() => openHistory(item)}><History size={14} aria-hidden="true" /></button>
                      </div></td>
                    </tr>
                  )) : null}
                </tbody>
              </table>
            </div>
            {itemPageInfo.total > 0 ? <StockPagination info={itemPageInfo} onPrevious={() => setItemPage((page) => Math.max(1, page - 1))} onNext={() => setItemPage((page) => Math.min(itemPageInfo.last_page, page + 1))} /> : null}
          </div>
        </section>
      ) : (
        <section className="admin-management-grid inventory-management-grid admin-inventory-management-grid">
          <div className="admin-table-panel">
            <div className="admin-table-heading admin-inventory-toolbar"><h2>Stock Logs ({logPageInfo.total})</h2></div>
            <div className="admin-filter-bar inventory-filter-bar admin-stock-filter-bar">
              <label><span>Item</span><select name="item_id" value={logFilters.item_id} onChange={updateLogFilter}><option value="">All items</option>{items.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
              <label><span>Type</span><select name="type" value={logFilters.type} onChange={updateLogFilter}><option value="">All types</option>{Object.entries(movementLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
              <label><span>Date</span><input name="date" type="date" value={logFilters.date} onChange={updateLogFilter} /></label>
              <label><span>User</span><select name="user_id" value={logFilters.user_id} onChange={updateLogFilter}><option value="">All users</option>{logMeta.users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}</select></label>
              <div className="admin-stock-filter-note">Movement history is immutable.</div>
            </div>
            <div className="admin-table-scroll">
              <table className="admin-data-table inventory-table admin-stock-log-table">
                <thead><tr><th>Date/Time</th><th>Item</th><th>Movement</th><th>Quantity</th><th>Previous</th><th>New Stock</th><th>Performed By</th><th>Reason</th></tr></thead>
                <tbody>
                  {isLoading ? <tr><td colSpan="8" aria-label="Loading stock logs..." /></tr> : null}
                  {!isLoading && logs.length === 0 ? <tr><td colSpan="8">No stock movements match the current filters.</td></tr> : null}
                  {!isLoading ? logs.map((log) => (
                    <tr key={log.id}>
                      <td>{formatDateTime(log.created_at)}</td>
                      <td><strong>{log.item?.name ?? 'Stock item'}</strong><small>{log.item?.sku}</small></td>
                      <td><span className={`admin-status-pill stock-movement-${log.type}`}>{movementLabels[log.type]}</span></td>
                      <td>{log.quantity}</td>
                      <td>{log.previous_quantity}</td>
                      <td><strong>{log.new_quantity}</strong></td>
                      <td>{log.performed_by?.name ?? 'System'}</td>
                      <td title={log.notes || log.reason}>{log.reason}</td>
                    </tr>
                  )) : null}
                </tbody>
              </table>
            </div>
            {logPageInfo.total > 0 ? <StockPagination info={logPageInfo} onPrevious={() => setLogPage((page) => Math.max(1, page - 1))} onNext={() => setLogPage((page) => Math.min(logPageInfo.last_page, page + 1))} /> : null}
          </div>
        </section>
      )}

      {modal ? (
        <>
          <button className="admin-inventory-drawer-backdrop" type="button" aria-label="Close stock panel" onClick={() => setModal(null)} />
          <aside className="admin-form-panel admin-inventory-drawer admin-stock-drawer" aria-label={isItemForm ? 'Stock item form' : isMovementForm ? 'Stock movement form' : 'Stock history'}>
            <div className="admin-inventory-drawer-header">
              <div><p className="eyebrow">{isItemForm ? 'Stock item' : isMovementForm ? movementLabels[modal.type] : 'Stock history'}</p><h2>{isItemForm ? (modal.item ? 'Edit Stock Item' : 'Add Stock Item') : isMovementForm ? movementLabels[modal.type] : modal.item.name}</h2></div>
              <button type="button" onClick={() => setModal(null)} aria-label="Close stock panel"><X size={17} aria-hidden="true" /></button>
            </div>

            {isItemForm ? (
              <form className="admin-embedded-form" onSubmit={saveItem} noValidate>
                {['name', 'sku', 'category', 'unit', 'location'].map((field) => (
                  <label key={field}><span>{field === 'sku' ? 'Code / SKU' : field.replace('_', ' ').replace(/^./, (letter) => letter.toUpperCase())}</span><input name={field} value={itemForm[field]} onChange={(event) => setItemForm((current) => ({ ...current, [field]: event.target.value }))} required={field !== 'location'} /></label>
                ))}
                <label><span>Reorder Level</span><input name="reorder_level" type="number" min="0" value={itemForm.reorder_level} onChange={(event) => setItemForm((current) => ({ ...current, reorder_level: event.target.value }))} /></label>
                <label className="admin-checkbox-label"><input type="checkbox" checked={itemForm.is_active} onChange={(event) => setItemForm((current) => ({ ...current, is_active: event.target.checked }))} /><span>Active stock item</span></label>
                <div className="admin-inventory-drawer-footer"><button className="outline-button" type="button" onClick={() => setModal(null)}>Cancel</button><button className="primary-button" type="submit" disabled={isSaving}>{isSaving ? 'Saving...' : 'Save Stock Item'}</button></div>
              </form>
            ) : isMovementForm ? (
              <form className="admin-embedded-form" onSubmit={saveMovement} noValidate>
                <div className="admin-stock-movement-summary"><strong>{modal.item.name}</strong><span>Available quantity: {modal.item.current_quantity} {modal.item.unit}</span></div>
                <label><span>Quantity</span><input name="quantity" type="number" min="1" max={modal.type === 'stock_out' ? modal.item.current_quantity : undefined} value={movementForm.quantity} onChange={(event) => setMovementForm((current) => ({ ...current, quantity: event.target.value }))} required /></label>
                <label><span>Reason</span><input name="reason" value={movementForm.reason} onChange={(event) => setMovementForm((current) => ({ ...current, reason: event.target.value }))} required /></label>
                <label><span>Reference</span><input name="reference" value={movementForm.reference} onChange={(event) => setMovementForm((current) => ({ ...current, reference: event.target.value }))} /></label>
                <label><span>Notes</span><textarea name="notes" rows="3" value={movementForm.notes} onChange={(event) => setMovementForm((current) => ({ ...current, notes: event.target.value }))} /></label>
                <div className="admin-inventory-drawer-footer"><button className="outline-button" type="button" onClick={() => setModal(null)}>Cancel</button><button className="primary-button" type="submit" disabled={isSaving || (modal.type === 'stock_out' && Number(movementForm.quantity) > modal.item.current_quantity)}>Confirm</button></div>
              </form>
            ) : (
              <div className="admin-stock-history-list">{history.length ? history.map((entry) => <article key={entry.id}><div><strong>{movementLabels[entry.type]}</strong><span>{formatDateTime(entry.created_at)}</span></div><p>{entry.quantity} {modal.item.unit}: {entry.previous_quantity} → {entry.new_quantity}</p><small>{entry.reason}</small></article>) : <p>No stock movements recorded.</p>}</div>
            )}
          </aside>
        </>
      ) : null}
    </div>
  )
}

function StockPagination({ info, onPrevious, onNext }) {
  return (
    <div className="admin-inventory-pagination admin-stock-pagination">
      <span>Showing {info.from ?? 0}–{info.to ?? 0} of {info.total ?? 0}</span>
      <div><button type="button" onClick={onPrevious} disabled={info.current_page <= 1}>Previous</button><strong>Page {info.current_page} of {info.last_page}</strong><button type="button" onClick={onNext} disabled={info.current_page >= info.last_page}>Next</button></div>
    </div>
  )
}

function formatDateTime(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

export default AdminInventoryStock
