import { useCallback, useEffect, useMemo, useState } from 'react'
import { Hotel, Pencil, Plus, Search, Trash2, Users } from 'lucide-react'
import {
  createAdminAmenity,
  createAdminAccommodation,
  deleteAdminAccommodation,
  getAdminAccommodations,
  getAdminAmenities,
  updateAdminAccommodation,
  updateAdminAccommodationStatus,
  resolveAssetUrl,
} from '../lib/api'
import { ToastMessage } from '../context/ToastContext'

const defaultFormValues = {
  name: '',
  slug: '',
  type: 'room',
  capacity: 2,
  price_per_night: '',
  description: '',
  status: 'available',
  primary_image: null,
  gallery_images: [null, null, null, null],
  remove_image_ids: [],
  amenity_ids: [],
}

const statusOptions = [
  { value: 'available', label: 'Available' },
  { value: 'unavailable', label: 'Unavailable' },
  { value: 'maintenance', label: 'Maintenance' },
]

const typeOptions = [
  { value: 'room', label: 'Room' },
  { value: 'cottage', label: 'Cottage' },
  { value: 'function_hall', label: 'Function Hall' },
  { value: 'exclusive_resort', label: 'Exclusive Resort Rental' },
]

function AdminAccommodations() {
  const [accommodations, setAccommodations] = useState([])
  const [amenities, setAmenities] = useState([])
  const [filters, setFilters] = useState({
    search: '',
    type: '',
    status: '',
  })
  const [formValues, setFormValues] = useState(defaultFormValues)
  const [editingAccommodation, setEditingAccommodation] = useState(null)
  const [isFormDrawerOpen, setIsFormDrawerOpen] = useState(false)
  const [fieldErrors, setFieldErrors] = useState({})
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  const [newAmenityName, setNewAmenityName] = useState('')
  const [amenityError, setAmenityError] = useState('')
  const [isSavingAmenity, setIsSavingAmenity] = useState(false)
  const [pendingActionId, setPendingActionId] = useState(null)
  const [currentPage, setCurrentPage] = useState(1)
  const pageSize = 10

  const filterParams = useMemo(
    () => ({
      search: filters.search.trim() || undefined,
      type: filters.type || undefined,
      status: filters.status || undefined,
    }),
    [filters],
  )

  const totalPages = Math.max(1, Math.ceil(accommodations.length / pageSize))
  const accommodationSummary = useMemo(() => ({
    total: accommodations.length,
    available: accommodations.filter((item) => item.status === 'available').length,
    occupied: accommodations.filter((item) => item.is_currently_occupied || item.status === 'unavailable').length,
    maintenance: accommodations.filter((item) => item.status === 'maintenance').length,
  }), [accommodations])
  const visibleAccommodations = useMemo(
    () => accommodations.slice((currentPage - 1) * pageSize, currentPage * pageSize),
    [accommodations, currentPage],
  )

  const loadAccommodations = useCallback(async () => {
    setIsLoading(true)
    setPageError('')

    try {
      const [accommodationResult, amenityResult] = await Promise.allSettled([
        getAdminAccommodations(filterParams),
        getAdminAmenities(),
      ])
      if (accommodationResult.status === 'rejected') throw accommodationResult.reason

      setAccommodations(accommodationResult.value)
      setAmenities(amenityResult.status === 'fulfilled' ? amenityResult.value : [])
    } catch {
      setPageError("We couldn't load accommodations right now.")
    } finally {
      setIsLoading(false)
    }
  }, [filterParams])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      loadAccommodations()
    }, 250)

    return () => window.clearTimeout(timeoutId)
  }, [loadAccommodations])

  useEffect(() => {
    setCurrentPage(1)
  }, [filters.search, filters.type, filters.status])

  useEffect(() => {
    setCurrentPage((page) => Math.min(page, totalPages))
  }, [totalPages])

  function updateFilter(event) {
    const { name, value } = event.target
    setFilters((current) => ({
      ...current,
      [name]: value,
    }))
  }

  function updateFormField(event) {
    const { name, value } = event.target
    setFormValues((current) => ({
      ...current,
      [name]: value,
      ...(name === 'name' && !editingAccommodation
        ? { slug: value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') }
        : {}),
    }))
    setFieldErrors((current) => ({
      ...current,
      [name]: '',
    }))
    setPageError('')
    setPageMessage('')
  }

  function startCreate() {
    setEditingAccommodation(null)
    setFormValues(defaultFormValues)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')
  }

  function openCreateDrawer() {
    startCreate()
    setIsFormDrawerOpen(true)
  }

  function closeFormDrawer() {
    setIsFormDrawerOpen(false)
  }

  function startEdit(accommodation) {
    const primaryImage = getPrimaryImage(accommodation)
    const additionalImages = getAdditionalImages(accommodation)

    setEditingAccommodation(accommodation)
    setFormValues({
      name: accommodation.name ?? '',
      slug: accommodation.slug ?? '',
      type: accommodation.type ?? 'room',
      capacity: accommodation.capacity ?? 1,
      price_per_night: accommodation.price_per_night ?? '',
      description: accommodation.description ?? '',
      status: accommodation.status ?? 'available',
      primary_image: null,
      gallery_images: [null, null, null, null],
      remove_image_ids: [],
      existing_primary_image: primaryImage,
      existing_gallery_images: additionalImages,
      amenity_ids: (accommodation.amenities ?? []).map((amenity) => amenity.id),
    })
    setFieldErrors({})
    setPageError('')
    setPageMessage('')
    setIsFormDrawerOpen(true)
  }

  function validateForm() {
    const errors = {}

    if (!formValues.name.trim()) {
      errors.name = 'Name is required.'
    }

    if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(formValues.slug.trim())) {
      errors.slug = 'Use lowercase letters, numbers, and hyphens only.'
    }

    if (!formValues.type.trim()) {
      errors.type = 'Type is required.'
    }

    if (!Number.isInteger(Number(formValues.capacity)) || Number(formValues.capacity) < 1) {
      errors.capacity = 'Capacity must be at least 1.'
    }

    if (Number(formValues.price_per_night) < 0 || formValues.price_per_night === '') {
      errors.price_per_night = 'Price must be zero or higher.'
    }

    if (formValues.description.trim().length < 10) {
      errors.description = 'Description must be at least 10 characters.'
    }

    if (!statusOptions.some((status) => status.value === formValues.status)) {
      errors.status = 'Choose a valid status.'
    }

    if (!editingAccommodation && !formValues.primary_image) {
      errors.primary_image = 'Main image is required.'
    }

    const existingAdditionalCount = (formValues.existing_gallery_images ?? [])
      .filter((image) => !(formValues.remove_image_ids ?? []).includes(image.id))
      .length
    const newAdditionalCount = formValues.gallery_images.filter(Boolean).length

    if (existingAdditionalCount + newAdditionalCount > 4) {
      errors.gallery_images = 'Add up to 4 gallery images only.'
    }

    return errors
  }

  async function handleSubmit(event) {
    event.preventDefault()

    const errors = validateForm()
    setFieldErrors(errors)
    setPageError('')
    setPageMessage('')

    if (Object.keys(errors).length > 0) {
      return
    }

    setIsSaving(true)

    const payload = buildAccommodationPayload(formValues, editingAccommodation)

    try {
      const result = editingAccommodation
        ? await updateAdminAccommodation(editingAccommodation.id, payload)
        : await createAdminAccommodation(payload)

      setPageMessage(result.message)
      setEditingAccommodation(result.accommodation)
      setFormValues({
        name: result.accommodation.name ?? '',
        slug: result.accommodation.slug ?? '',
        type: result.accommodation.type ?? 'room',
        capacity: result.accommodation.capacity ?? 1,
        price_per_night: result.accommodation.price_per_night ?? '',
        description: result.accommodation.description ?? '',
        status: result.accommodation.status ?? 'available',
        primary_image: null,
        gallery_images: [null, null, null, null],
        remove_image_ids: [],
        existing_primary_image: getPrimaryImage(result.accommodation),
        existing_gallery_images: getAdditionalImages(result.accommodation),
        amenity_ids: (result.accommodation.amenities ?? []).map((amenity) => amenity.id),
      })
      await loadAccommodations()
    } catch (error) {
      const errors = error?.response?.data?.errors

      if (errors) {
        setFieldErrors(
          Object.fromEntries(
            Object.entries(errors).map(([key, value]) => [
              key.startsWith('amenity_ids.') ? 'amenity_ids' : key,
              value[0],
            ]),
          ),
        )
      } else {
        setPageError('Unable to save accommodation right now.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  async function handleStatusChange(accommodation, status) {
    setPendingActionId(accommodation.id)
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateAdminAccommodationStatus(accommodation.id, status)
      setPageMessage(result.message)
      setAccommodations((current) =>
        current.map((item) => (item.id === accommodation.id ? result.accommodation : item)),
      )
      if (editingAccommodation?.id === accommodation.id) {
        startEdit(result.accommodation)
      }
    } catch {
      setPageError('Unable to update accommodation status.')
    } finally {
      setPendingActionId(null)
    }
  }

  async function handleDelete(accommodation) {
    const confirmed = window.confirm(
      `Delete ${accommodation.name}? Accommodations with reservation history cannot be permanently deleted.`,
    )

    if (!confirmed) {
      return
    }

    setPendingActionId(accommodation.id)
    setPageError('')
    setPageMessage('')

    try {
      const result = await deleteAdminAccommodation(accommodation.id)
      setPageMessage(result.message)
      setAccommodations((current) => current.filter((item) => item.id !== accommodation.id))
      if (editingAccommodation?.id === accommodation.id) {
        startCreate()
      }
    } catch (error) {
      const accommodationError = error?.response?.data?.errors?.accommodation?.[0]
      setPageError(
        accommodationError ??
          'Unable to delete this accommodation. Change its status if it should no longer be bookable.',
      )
    } finally {
      setPendingActionId(null)
    }
  }

  function formatCurrency(value) {
    return new Intl.NumberFormat('en-PH', {
      style: 'currency',
      currency: 'PHP',
    }).format(Number(value ?? 0))
  }

  function updatePrimaryImage(event) {
    const file = event.target.files?.[0] ?? null
    setFormValues((current) => ({
      ...current,
      primary_image: file,
    }))
    setFieldErrors((current) => ({ ...current, primary_image: '' }))
  }

  function updateGalleryImage(index, event, existingImageId = null) {
    const file = event.target.files?.[0] ?? null
    setFormValues((current) => ({
      ...current,
      gallery_images: current.gallery_images.map((image, imageIndex) => (
        imageIndex === index ? file : image
      )),
      remove_image_ids: existingImageId
        ? [...new Set([...(current.remove_image_ids ?? []), existingImageId])]
        : current.remove_image_ids,
    }))
    setFieldErrors((current) => ({ ...current, gallery_images: '' }))
  }

  function clearGalleryImage(index) {
    setFormValues((current) => ({
      ...current,
      gallery_images: current.gallery_images.map((image, imageIndex) => (
        imageIndex === index ? null : image
      )),
    }))
  }

  function removeExistingGalleryImage(imageId) {
    setFormValues((current) => ({
      ...current,
      remove_image_ids: [...new Set([...(current.remove_image_ids ?? []), imageId])],
    }))
  }

  function toggleAmenity(amenityId) {
    setFormValues((current) => {
      const currentIds = current.amenity_ids ?? []
      const nextIds = currentIds.includes(amenityId)
        ? currentIds.filter((id) => id !== amenityId)
        : [...currentIds, amenityId]

      return {
        ...current,
        amenity_ids: nextIds,
      }
    })
    setFieldErrors((current) => ({ ...current, amenity_ids: '' }))
    setAmenityError('')
  }

  async function handleCreateAmenity() {
    const name = newAmenityName.trim()

    if (!name) {
      setAmenityError('Amenity name is required.')
      return
    }

    setIsSavingAmenity(true)
    setAmenityError('')

    try {
      const result = await createAdminAmenity({ name })
      setAmenities((current) => [...current, result.amenity].sort((a, b) => a.name.localeCompare(b.name)))
      setFormValues((current) => ({
        ...current,
        amenity_ids: [...new Set([...(current.amenity_ids ?? []), result.amenity.id])],
      }))
      setNewAmenityName('')
      setPageMessage(result.message)
    } catch (error) {
      setAmenityError(error?.response?.data?.errors?.name?.[0] ?? 'Unable to add amenity right now.')
    } finally {
      setIsSavingAmenity(false)
    }
  }

  return (
    <>
      <ToastMessage message={pageMessage} />
      {pageError ? (
        <div className="admin-inline-alert" role="alert">
          {pageError}
        </div>
      ) : null}

      <section className="admin-management-grid admin-accommodation-management-grid">
        <div className="admin-table-panel">
          <div className="admin-accommodation-toolbar">
            <div className="admin-accommodation-summary" aria-label="Accommodation summary">
              <span className="admin-accommodation-summary-total">
                <strong>{accommodationSummary.total}</strong> Accommodations
              </span>
              <span className="admin-accommodation-summary-item is-available">
                <strong>{accommodationSummary.available}</strong> Available
              </span>
              <span className="admin-accommodation-summary-item is-occupied">
                <strong>{accommodationSummary.occupied}</strong> Occupied
              </span>
              <span className="admin-accommodation-summary-item is-maintenance">
                <strong>{accommodationSummary.maintenance}</strong> Maintenance
              </span>
            </div>
            <button className="primary-button admin-accommodation-add-button" type="button" onClick={openCreateDrawer}>
              <Plus size={17} aria-hidden="true" />
              Add Accommodation
            </button>
          </div>
          <div className="admin-filter-bar admin-accommodation-filter-bar">
            <label>
              <span>Search</span>
              <div className="admin-accommodation-search-input">
                <Search size={15} aria-hidden="true" />
                <input
                  name="search"
                  value={filters.search}
                  onChange={updateFilter}
                  placeholder="Name, slug, or description"
                />
              </div>
            </label>
            <label>
              <span>Type</span>
              <select name="type" value={filters.type} onChange={updateFilter}>
                <option value="">All types</option>
                {typeOptions.map((type) => (
                  <option key={type.value} value={type.value}>
                    {type.label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              <span>Status</span>
              <select name="status" value={filters.status} onChange={updateFilter}>
                <option value="">All statuses</option>
                {statusOptions.map((status) => (
                  <option key={status.value} value={status.value}>
                    {status.label}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className="admin-table-scroll">
            <table className="admin-data-table admin-accommodation-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Capacity</th>
                  <th>Price</th>
                  <th>Status</th>
                  <th>Reservations</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {isLoading ? (
                  <tr>
                  <td colSpan="7" aria-label="Loading stays..." />
                </tr>
              ) : null}
              {!isLoading && accommodations.length === 0 ? (
                <tr>
                  <td colSpan="7">
                    <div className="admin-accommodation-table-empty">
                      <Hotel size={22} aria-hidden="true" />
                      <strong>No accommodations found</strong>
                      <span>Try adjusting the search or filters.</span>
                    </div>
                  </td>
                </tr>
                ) : null}
                  {!isLoading
                  ? visibleAccommodations.map((accommodation) => (
                      <tr key={accommodation.id}>
                        <td>
                          <strong>{accommodation.name}</strong>
                          <small>{accommodation.slug}</small>
                        </td>
                        <td><span className={`admin-status-pill accommodation-type-${accommodation.type}`}>{formatAccommodationType(accommodation.type)}</span></td>
                        <td><span className="accommodation-capacity"><Users size={14} aria-hidden="true" />{accommodation.capacity} guests</span></td>
                        <td><strong className="accommodation-price">{formatCurrency(accommodation.price_per_night)}</strong><small>per night</small></td>
                        <td>
                          <select
                            className={`admin-status-select status-${accommodation.status}`}
                            value={accommodation.status}
                            onChange={(event) => handleStatusChange(accommodation, event.target.value)}
                            disabled={pendingActionId === accommodation.id}
                            aria-label={`Change status for ${accommodation.name}`}
                          >
                            {statusOptions.map((status) => (
                              <option key={status.value} value={status.value}>
                                {status.label}
                              </option>
                            ))}
                          </select>
                        </td>
                        <td><span className="accommodation-reservation-count">{accommodation.reservations_count ?? 0}</span><small>reservations</small></td>
                        <td>
                          <div className="admin-row-actions admin-accommodation-row-actions">
                            <button type="button" onClick={() => startEdit(accommodation)} aria-label={`Edit ${accommodation.name}`} title="Edit accommodation">
                              <Pencil size={15} aria-hidden="true" />
                            </button>
                            <button
                              className="danger-button"
                              type="button"
                              onClick={() => handleDelete(accommodation)}
                              disabled={pendingActionId === accommodation.id}
                              aria-label={`Delete ${accommodation.name}`}
                              title="Delete accommodation"
                            >
                              <Trash2 size={15} aria-hidden="true" />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))
                  : null}
              </tbody>
            </table>
          </div>
          {!isLoading && accommodations.length > 0 ? (
            <div className="admin-accommodation-pagination" aria-label="Accommodation pagination">
              <span>Showing {((currentPage - 1) * pageSize) + 1}–{Math.min(currentPage * pageSize, accommodations.length)} of {accommodations.length}</span>
              <div>
                <button type="button" onClick={() => setCurrentPage((page) => Math.max(1, page - 1))} disabled={currentPage === 1}>Previous</button>
                <strong>Page {currentPage} of {totalPages}</strong>
                <button type="button" onClick={() => setCurrentPage((page) => Math.min(totalPages, page + 1))} disabled={currentPage === totalPages}>Next</button>
              </div>
            </div>
          ) : null}
        </div>
      </section>

      {isFormDrawerOpen ? (
        <>
          <button
            className="admin-accommodation-drawer-backdrop"
            type="button"
            aria-label="Close accommodation form"
            onClick={closeFormDrawer}
          />
          <form className="admin-form-panel admin-accommodation-drawer" onSubmit={handleSubmit} noValidate>
            <div className="admin-accommodation-drawer-header">
              <div>
                <p className="eyebrow">{editingAccommodation ? 'Edit Listing' : 'New Listing'}</p>
                <h2>{editingAccommodation ? editingAccommodation.name : 'Add accommodation'}</h2>
              </div>
              <button type="button" onClick={closeFormDrawer} aria-label="Close accommodation form">
                X
              </button>
            </div>

          <label>
            <span>Name</span>
            <input name="name" value={formValues.name} onChange={updateFormField} />
            {fieldErrors.name ? <small role="alert">{fieldErrors.name}</small> : null}
          </label>

          <label>
            <span>Slug</span>
            <input name="slug" value={formValues.slug} onChange={updateFormField} />
            {fieldErrors.slug ? <small role="alert">{fieldErrors.slug}</small> : null}
          </label>

          <div className="admin-form-row">
            <label>
              <span>Type</span>
              <select name="type" value={formValues.type} onChange={updateFormField}>
                {typeOptions.map((type) => (
                  <option key={type.value} value={type.value}>
                    {type.label}
                  </option>
                ))}
              </select>
              {fieldErrors.type ? <small role="alert">{fieldErrors.type}</small> : null}
            </label>

            <label>
              <span>Status</span>
              <select name="status" value={formValues.status} onChange={updateFormField}>
                {statusOptions.map((status) => (
                  <option key={status.value} value={status.value}>
                    {status.label}
                  </option>
                ))}
              </select>
              {fieldErrors.status ? <small role="alert">{fieldErrors.status}</small> : null}
            </label>
          </div>

          <div className="admin-form-row">
            <label>
              <span>Capacity</span>
              <input
                min="1"
                name="capacity"
                type="number"
                value={formValues.capacity}
                onChange={updateFormField}
              />
              {fieldErrors.capacity ? <small role="alert">{fieldErrors.capacity}</small> : null}
            </label>

            <label>
              <span>Price per night</span>
              <input
                min="0"
                name="price_per_night"
                step="0.01"
                type="number"
                value={formValues.price_per_night}
                onChange={updateFormField}
              />
              {fieldErrors.price_per_night ? (
                <small role="alert">{fieldErrors.price_per_night}</small>
              ) : null}
            </label>
          </div>

          <section className="admin-image-upload-panel" aria-label="Accommodation image gallery">
            <div>
              <span>Main Image</span>
              <p>Required primary photo for public listings and accommodation details.</p>
            </div>
            <ImageUploadSlot
              id="primary-image"
              label="Upload main image"
              file={formValues.primary_image}
              existingImage={formValues.existing_primary_image}
              onChange={updatePrimaryImage}
            />
            {fieldErrors.primary_image ? <small role="alert">{fieldErrors.primary_image}</small> : null}

            <div>
              <span>Gallery Images</span>
              <p>Optional. Add up to 4 more images.</p>
            </div>
            <div className="admin-gallery-upload-grid">
              {[0, 1, 2, 3].map((index) => {
                const visibleExistingImages = (formValues.existing_gallery_images ?? [])
                  .filter((image) => !(formValues.remove_image_ids ?? []).includes(image.id))
                const existingImage = visibleExistingImages[index]

                return (
                  <ImageUploadSlot
                    id={`gallery-image-${index}`}
                    key={index}
                    label={`Gallery image ${index + 1}`}
                    file={formValues.gallery_images[index]}
                    existingImage={existingImage}
                    onChange={(event) => updateGalleryImage(index, event, existingImage?.id)}
                    onClearFile={() => clearGalleryImage(index)}
                    onRemoveExisting={existingImage ? () => removeExistingGalleryImage(existingImage.id) : null}
                  />
                )
              })}
            </div>
            {fieldErrors.gallery_images ? <small role="alert">{fieldErrors.gallery_images}</small> : null}
          </section>

          <section className="admin-amenity-panel" aria-label="Accommodation amenities">
            <div>
              <span>Amenities</span>
              <p>Select the amenities available for this accommodation.</p>
            </div>
            <div className="admin-amenity-chip-grid">
              {amenities.map((amenity) => {
                const isSelected = (formValues.amenity_ids ?? []).includes(amenity.id)

                return (
                  <button
                    className={`admin-amenity-chip ${isSelected ? 'is-selected' : ''}`}
                    key={amenity.id}
                    type="button"
                    onClick={() => toggleAmenity(amenity.id)}
                    aria-pressed={isSelected}
                  >
                    <span aria-hidden="true">{isSelected ? '✓' : ''}</span>
                    {amenity.name}
                  </button>
                )
              })}
            </div>
            {fieldErrors.amenity_ids ? <small role="alert">{fieldErrors.amenity_ids}</small> : null}
            <div className="admin-add-amenity-row">
              <label htmlFor="new-amenity-name">
                <span className="sr-only">New amenity name</span>
                <input
                  id="new-amenity-name"
                  value={newAmenityName}
                  onChange={(event) => {
                    setNewAmenityName(event.target.value)
                    setAmenityError('')
                  }}
                  placeholder="Add custom amenity"
                />
              </label>
              <button
                className="outline-button"
                type="button"
                onClick={handleCreateAmenity}
                disabled={isSavingAmenity}
              >
                {isSavingAmenity ? 'Adding...' : '+ Add Amenity'}
              </button>
            </div>
            {amenityError ? <small role="alert">{amenityError}</small> : null}
          </section>

          <label>
            <span>Description</span>
            <textarea
              name="description"
              rows="5"
              value={formValues.description}
              onChange={updateFormField}
            />
            {fieldErrors.description ? <small role="alert">{fieldErrors.description}</small> : null}
          </label>

          <div className="admin-form-actions">
            <button className="primary-button" type="submit" disabled={isSaving}>
              {isSaving ? 'Saving...' : editingAccommodation ? 'Save Changes' : 'Create Accommodation'}
            </button>
            <button className="outline-button" type="button" onClick={startCreate}>
              Clear
            </button>
          </div>
          </form>
        </>
      ) : null}
    </>
  )
}

function ImageUploadSlot({
  existingImage = null,
  file = null,
  id,
  label,
  onChange,
  onClearFile = null,
  onRemoveExisting = null,
}) {
  const [previewUrl, setPreviewUrl] = useState('')

  useEffect(() => {
    if (!file) {
      setPreviewUrl('')
      return undefined
    }

    const objectUrl = URL.createObjectURL(file)
    setPreviewUrl(objectUrl)

    return () => URL.revokeObjectURL(objectUrl)
  }, [file])

  const imageUrl = previewUrl || existingImage?.url || existingImage?.image_path || ''

  return (
    <div className="admin-image-upload-slot">
      <label htmlFor={id}>
        {imageUrl ? (
          <img src={resolveAssetUrl(imageUrl)} alt={label} />
        ) : (
          <span>No image selected</span>
        )}
      </label>
      <input id={id} type="file" accept="image/jpeg,image/png,image/webp" onChange={onChange} />
      <div>
        <label className="outline-button" htmlFor={id}>
          {imageUrl ? 'Replace' : 'Upload'}
        </label>
        {file && onClearFile ? (
          <button type="button" onClick={onClearFile}>Remove</button>
        ) : null}
        {!file && existingImage && onRemoveExisting ? (
          <button type="button" onClick={onRemoveExisting}>Remove</button>
        ) : null}
      </div>
    </div>
  )
}

function buildAccommodationPayload(formValues, editingAccommodation) {
  const payload = new FormData()

  if (editingAccommodation) {
    payload.append('_method', 'PUT')
  }

  payload.append('name', formValues.name.trim())
  payload.append('slug', formValues.slug.trim())
  payload.append('type', formValues.type.trim())
  payload.append('capacity', String(Number(formValues.capacity)))
  payload.append('price_per_night', String(Number(formValues.price_per_night)))
  payload.append('description', formValues.description.trim())
  payload.append('status', formValues.status)

  if (formValues.primary_image) {
    payload.append('primary_image', formValues.primary_image)
  }

  formValues.gallery_images.filter(Boolean).forEach((file) => {
    payload.append('gallery_images[]', file)
  })

  ;(formValues.remove_image_ids ?? []).forEach((imageId) => {
    payload.append('remove_image_ids[]', String(imageId))
  })

  ;(formValues.amenity_ids ?? []).forEach((amenityId) => {
    payload.append('amenity_ids[]', String(amenityId))
  })

  return payload
}

function getPrimaryImage(accommodation) {
  return (accommodation.images ?? accommodation.gallery_images ?? []).find((image) => image.is_primary) ?? null
}

function getAdditionalImages(accommodation) {
  return (accommodation.images ?? accommodation.gallery_images ?? []).filter((image) => !image.is_primary).slice(0, 4)
}

function formatAccommodationType(type) {
  return typeOptions.find((option) => option.value === type)?.label ?? 'Accommodation'
}

export default AdminAccommodations
