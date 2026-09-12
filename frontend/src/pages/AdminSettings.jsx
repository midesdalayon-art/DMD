import { useEffect, useMemo, useState } from 'react'
import LoadingButton from '../components/LoadingButton'
import ResortBrandName, { getResortNameFontFamily } from '../components/ResortBrandName'
import { getAdminSettings, resolveAssetUrl, updateAdminSettings } from '../lib/api'
import { setResortBranding } from '../hooks/useResortBranding'
import { ToastMessage } from '../context/ToastContext'

const tabs = [
  ['general', 'General'],
  ['branding', 'Branding'],
  ['about', 'About Us'],
  ['contact', 'Contact Information'],
  ['booking', 'Booking'],
  ['attendance', 'Attendance'],
  ['notifications', 'Notifications'],
  ['regional', 'Regional'],
  ['payments', 'Payments'],
  ['devices', 'Devices'],
]

const resortNameFontOptions = [
  ['default', 'Default'],
  ['Times New Roman', 'Times New Roman'],
  ['Georgia', 'Georgia'],
  ['Arial', 'Arial'],
  ['Verdana', 'Verdana'],
  ['Trebuchet MS', 'Trebuchet MS'],
  ['Courier New', 'Courier New'],
  ['Garamond', 'Garamond'],
]

function AdminSettings() {
  const [activeTab, setActiveTab] = useState('general')
  const [settings, setSettings] = useState(null)
  const [meta, setMeta] = useState({ date_formats: [], time_formats: [], currencies: [], timezones: [] })
  const [fieldErrors, setFieldErrors] = useState({})
  const [pageMessage, setPageMessage] = useState('')
  const [pageError, setPageError] = useState('')
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)

  useEffect(() => {
    let isMounted = true

    async function loadSettings() {
      setIsLoading(true)
      setPageError('')

      try {
        const result = await getAdminSettings()
        if (isMounted) {
          setSettings(result.settings)
          setMeta(result.meta)
          setResortBranding(result.settings.branding, result.settings.general)
        }
      } catch {
        if (isMounted) setPageError('Unable to load system settings right now.')
      } finally {
        if (isMounted) setIsLoading(false)
      }
    }

    loadSettings()

    return () => {
      isMounted = false
    }
  }, [])

  const currentSection = useMemo(() => settings?.[activeTab] ?? {}, [activeTab, settings])

  function updateField(section, field, value) {
    setSettings((current) => ({
      ...current,
      [section]: {
        ...current[section],
        [field]: value,
      },
    }))
    setFieldErrors((current) => ({ ...current, [`${section}.${field}`]: '' }))
    setPageError('')
    setPageMessage('')
  }

  function updateAboutImage(file) {
    setSettings((current) => ({
      ...current,
      about: {
        ...current.about,
        about_image_file: file,
        remove_image: false,
      },
    }))
    setFieldErrors((current) => ({ ...current, 'about.about_image': '' }))
    setPageError('')
    setPageMessage('')
  }

  function updateBrandingLogo(file) {
    setSettings((current) => ({
      ...current,
      branding: {
        ...current.branding,
        logo_file: file,
        remove_logo: false,
      },
    }))
    setFieldErrors((current) => ({ ...current, 'branding.logo_file': '' }))
    setPageError('')
    setPageMessage('')
  }

  function removeBrandingLogo() {
    setSettings((current) => ({
      ...current,
      branding: {
        ...current.branding,
        logo_file: null,
        logo_path: null,
        remove_logo: true,
      },
    }))
    setFieldErrors((current) => ({ ...current, 'branding.logo_file': '' }))
    setPageError('')
    setPageMessage('')
  }

  function updateBrandingFavicon(file) {
    setSettings((current) => ({
      ...current,
      branding: {
        ...current.branding,
        favicon_file: file,
        remove_favicon: false,
      },
    }))
    setFieldErrors((current) => ({ ...current, 'branding.favicon_file': '' }))
    setPageError('')
    setPageMessage('')
  }

  function removeBrandingFavicon() {
    setSettings((current) => ({
      ...current,
      branding: {
        ...current.branding,
        favicon_file: null,
        favicon_path: null,
        remove_favicon: true,
      },
    }))
    setFieldErrors((current) => ({ ...current, 'branding.favicon_file': '' }))
    setPageError('')
    setPageMessage('')
  }

  function updateBrandingHeroImage(file) {
    setSettings((current) => ({
      ...current,
      branding: {
        ...current.branding,
        homepage_hero_image_file: file,
        remove_homepage_hero_image: false,
      },
    }))
    setFieldErrors((current) => ({ ...current, 'branding.homepage_hero_image_file': '' }))
    setPageError('')
    setPageMessage('')
  }

  function removeBrandingHeroImage() {
    setSettings((current) => ({
      ...current,
      branding: {
        ...current.branding,
        homepage_hero_image_file: null,
        homepage_hero_image_path: null,
        remove_homepage_hero_image: true,
      },
    }))
    setFieldErrors((current) => ({ ...current, 'branding.homepage_hero_image_file': '' }))
    setPageError('')
    setPageMessage('')
  }

  async function persistHeroSettings(nextSettings) {
    setIsSaving(true)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateAdminSettings(buildPayload(nextSettings))
      setSettings(result.settings)
      setResortBranding(result.settings.branding, result.settings.general)
      setPageMessage(result.message)
    } catch (error) {
      const errors = error?.response?.data?.errors
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
      } else {
        setPageError('Unable to save hero image settings.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  async function updateBrandingHeroSlot(slot, file) {
    const key = `hero_image_${slot}`
    const nextSettings = {
      ...settings,
      branding: {
        ...settings.branding,
        [`${key}_file`]: file,
        [`remove_${key}`]: false,
      },
    }
    setSettings(nextSettings)
    setFieldErrors((current) => ({ ...current, [`branding.${key}_file`]: '' }))
    setPageError('')
    setPageMessage('')
    await persistHeroSettings(nextSettings)
  }

  async function removeBrandingHeroSlot(slot) {
    const key = `hero_image_${slot}`
    const nextSettings = {
      ...settings,
      branding: {
        ...settings.branding,
        [`${key}_file`]: null,
        [`${key}_path`]: null,
        [`remove_${key}`]: true,
        ...(slot === 1 ? { homepage_hero_image_path: null, remove_homepage_hero_image: true } : {}),
      },
    }
    setSettings(nextSettings)
    setFieldErrors((current) => ({ ...current, [`branding.${key}_file`]: '' }))
    setPageError('')
    setPageMessage('')
    await persistHeroSettings(nextSettings)
  }

  function removeAboutImage() {
    setSettings((current) => ({
      ...current,
      about: {
        ...current.about,
        about_image_file: null,
        image_url: null,
        image_path: null,
        remove_image: true,
      },
    }))
    setFieldErrors((current) => ({ ...current, 'about.about_image': '' }))
    setPageError('')
    setPageMessage('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (!settings) return

    setIsSaving(true)
    setFieldErrors({})
    setPageError('')
    setPageMessage('')

    try {
      const result = await updateAdminSettings(buildPayload(settings))
      setSettings(result.settings)
      setResortBranding(result.settings.branding, result.settings.general)
      setPageMessage(result.message)
    } catch (error) {
      const errors = error?.response?.data?.errors
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, value]) => [key, value[0]])))
      } else {
        setPageError('Unable to save settings.')
      }
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <>
      <ToastMessage message={pageMessage} />
      {pageError ? <div className="admin-inline-alert" role="alert">{pageError}</div> : null}

      <section className="settings-shell">
        <nav className="settings-tabs" aria-label="Settings sections">
          {tabs.map(([key, label]) => (
            <button
              className={activeTab === key ? 'is-active' : ''}
              key={key}
              type="button"
              onClick={() => setActiveTab(key)}
            >
              {label}
            </button>
          ))}
        </nav>

        <form className="admin-form-panel settings-panel" onSubmit={handleSubmit} noValidate>
          <div>
            <p className="eyebrow">{tabs.find(([key]) => key === activeTab)?.[1]}</p>
            <h2>{sectionTitle(activeTab)}</h2>
          </div>

          {isLoading ? <p className="sr-only" role="status">Loading settings...</p> : null}
          {!isLoading && settings ? (
            <SettingsSection
              activeTab={activeTab}
              currentSection={currentSection}
              fieldErrors={fieldErrors}
              meta={meta}
              settings={settings}
              removeBrandingFavicon={removeBrandingFavicon}
              removeBrandingLogo={removeBrandingLogo}
              removeBrandingHeroImage={removeBrandingHeroImage}
              updateField={updateField}
              updateAboutImage={updateAboutImage}
              updateBrandingFavicon={updateBrandingFavicon}
              updateBrandingLogo={updateBrandingLogo}
              updateBrandingHeroImage={updateBrandingHeroImage}
              updateBrandingHeroSlot={updateBrandingHeroSlot}
              removeBrandingHeroSlot={removeBrandingHeroSlot}
              removeAboutImage={removeAboutImage}
            />
          ) : null}

          <div className="admin-form-actions">
            <LoadingButton
              className="primary-button"
              type="submit"
              isLoading={isSaving}
              loadingLabel="Saving..."
              disabled={isLoading}
            >
              Save Changes
            </LoadingButton>
          </div>
        </form>
      </section>
    </>
  )
}

function SettingsSection({
  activeTab,
  currentSection,
  fieldErrors,
  meta,
  removeBrandingFavicon,
  removeBrandingLogo,
  removeBrandingHeroImage,
  removeAboutImage,
  settings,
  updateAboutImage,
  updateBrandingFavicon,
  updateBrandingLogo,
  updateBrandingHeroImage,
  updateBrandingHeroSlot,
  removeBrandingHeroSlot,
  updateField,
}) {
  if (activeTab === 'general') {
    return (
      <>
        <section className="settings-section-group">
          <div>
            <h3>Resort Information</h3>
            <p className="settings-note">Primary resort identity used across the public site and staff consoles.</p>
          </div>
          <TextField field="resort_name" label="Resort Name" section="general" settings={settings} updateField={updateField} errors={fieldErrors} />
          <div className="admin-form-row">
            <SelectField
              field="resort_name_font"
              label="Resort Name Font"
              options={resortNameFontOptions}
              section="general"
              settings={settings}
              updateField={updateField}
              errors={fieldErrors}
            />
            <BrandPreview settings={settings} />
          </div>
          <TextAreaField field="short_description" label="Short Description" section="general" settings={settings} updateField={updateField} errors={fieldErrors} />
        </section>

        <section className="settings-section-group">
          <div>
            <h3>Contact Information</h3>
            <p className="settings-note">Used for public contact details and automated system references.</p>
          </div>
          <div className="admin-form-row">
            <TextField field="contact_number" label="Contact Number" section="general" settings={settings} updateField={updateField} errors={fieldErrors} />
            <TextField field="email" label="Email Address" section="general" type="email" settings={settings} updateField={updateField} errors={fieldErrors} />
          </div>
          <TextField field="address" label="Resort Address" section="general" settings={settings} updateField={updateField} errors={fieldErrors} />
        </section>

        <section className="settings-section-group">
          <div>
            <h3>Operating Information</h3>
            <p className="settings-note">These defaults are used by booking and scheduling workflows.</p>
          </div>
          <div className="admin-form-row">
            <TextField field="default_check_in_time" label="Default Check-In Time" section="booking" type="time" settings={settings} updateField={updateField} errors={fieldErrors} />
            <TextField field="default_check_out_time" label="Default Check-Out Time" section="booking" type="time" settings={settings} updateField={updateField} errors={fieldErrors} />
          </div>
          <div className="admin-form-row">
            <SelectField field="timezone" label="Timezone" options={meta.timezones} section="regional" settings={settings} updateField={updateField} errors={fieldErrors} />
            <SelectField field="currency" label="Currency" options={meta.currencies} section="regional" settings={settings} updateField={updateField} errors={fieldErrors} />
          </div>
        </section>
      </>
    )
  }

  if (activeTab === 'branding') {
    return (
              <BrandingSection
                errors={fieldErrors}
                removeBrandingFavicon={removeBrandingFavicon}
                removeBrandingLogo={removeBrandingLogo}
                settings={settings}
                updateBrandingFavicon={updateBrandingFavicon}
                updateBrandingLogo={updateBrandingLogo}
                updateBrandingHeroSlot={updateBrandingHeroSlot}
                removeBrandingHeroSlot={removeBrandingHeroSlot}
                updateField={updateField}
              />
    )
  }

  if (activeTab === 'about') {
    return (
      <>
        <TextField field="title" label="About Us Title" section="about" settings={settings} updateField={updateField} errors={fieldErrors} />
        <TextAreaField field="short_introduction" label="Short Introduction" section="about" settings={settings} updateField={updateField} errors={fieldErrors} rows={3} />
        <TextAreaField field="full_description" label="Full Resort Description" section="about" settings={settings} updateField={updateField} errors={fieldErrors} rows={6} />
        <div className="admin-form-row">
          <TextAreaField field="mission" label="Mission" section="about" settings={settings} updateField={updateField} errors={fieldErrors} rows={5} />
          <TextAreaField field="vision" label="Vision" section="about" settings={settings} updateField={updateField} errors={fieldErrors} rows={5} />
        </div>
        <AboutImageField
          errors={fieldErrors}
          removeAboutImage={removeAboutImage}
          settings={settings}
          updateAboutImage={updateAboutImage}
        />
      </>
    )
  }

  if (activeTab === 'contact') {
    return (
      <>
        <TextAreaField field="resort_address" label="Resort Address" section="contact" settings={settings} updateField={updateField} errors={fieldErrors} rows={3} />
        <div className="admin-form-row">
          <TextField field="primary_phone" label="Primary Phone Number" section="contact" settings={settings} updateField={updateField} errors={fieldErrors} />
          <TextField field="secondary_phone" label="Secondary Phone Number" section="contact" settings={settings} updateField={updateField} errors={fieldErrors} />
        </div>
        <TextField field="email" label="Email Address" section="contact" type="email" settings={settings} updateField={updateField} errors={fieldErrors} />
        <TextField field="facebook_url" label="Facebook Page URL" section="contact" type="url" settings={settings} updateField={updateField} errors={fieldErrors} />
        <TextField field="google_maps_url" label="Google Maps URL" section="contact" type="url" settings={settings} updateField={updateField} errors={fieldErrors} />
        <TextAreaField field="business_hours" label="Business Hours / Operating Hours" section="contact" settings={settings} updateField={updateField} errors={fieldErrors} rows={4} />
      </>
    )
  }

  if (activeTab === 'booking') {
    return (
      <>
        <div className="admin-form-row">
          <TextField field="default_check_in_time" label="Default Check-In Time" section="booking" type="time" settings={settings} updateField={updateField} errors={fieldErrors} />
          <TextField field="default_check_out_time" label="Default Check-Out Time" section="booking" type="time" settings={settings} updateField={updateField} errors={fieldErrors} />
        </div>
        <div className="admin-form-row">
          <TextField field="max_advance_booking_days" label="Max Advance Booking Days" section="booking" type="number" settings={settings} updateField={updateField} errors={fieldErrors} />
          <TextField field="minimum_booking_notice_hours" label="Minimum Booking Notice Hours" section="booking" type="number" settings={settings} updateField={updateField} errors={fieldErrors} />
        </div>
        <TextField field="cancellation_cutoff_hours" label="Cancellation Cutoff Hours" section="booking" type="number" settings={settings} updateField={updateField} errors={fieldErrors} />
        <ToggleField field="guest_cancellation_enabled" label="Allow Guest Cancellation" section="booking" settings={settings} updateField={updateField} />
        <section className="settings-section-group booking-house-rules-group">
          <div>
            <h3>House Rules</h3>
            <p className="settings-note">These rules are shown on public accommodation detail pages and can be adjusted by the administrator.</p>
          </div>
          <div className="admin-form-row">
            <TextField field="house_rules_check_in_time" label="Check-in after" section="booking" type="time" settings={settings} updateField={updateField} errors={fieldErrors} />
            <TextField field="house_rules_check_out_time" label="Check-out before" section="booking" type="time" settings={settings} updateField={updateField} errors={fieldErrors} />
          </div>
          <div className="admin-form-row">
            <TextField field="house_rules_quiet_hours_start" label="Quiet Hours Start" section="booking" type="time" settings={settings} updateField={updateField} errors={fieldErrors} />
            <TextField field="house_rules_quiet_hours_end" label="Quiet Hours End" section="booking" type="time" settings={settings} updateField={updateField} errors={fieldErrors} />
          </div>
          <div className="admin-form-row booking-rules-text-grid">
            <TextAreaField field="house_rules_smoking_policy" label="Smoking Policy" section="booking" settings={settings} updateField={updateField} errors={fieldErrors} rows={2} />
            <TextAreaField field="house_rules_capacity_rule" label="Maximum Guest Capacity Rule" section="booking" settings={settings} updateField={updateField} errors={fieldErrors} rows={2} />
          </div>
          <div className="admin-form-row booking-rules-text-grid">
            <TextAreaField field="house_rules_pool_safety_rule" label="Pool / Safety Rule" section="booking" settings={settings} updateField={updateField} errors={fieldErrors} rows={2} />
            <TextAreaField field="house_rules_cleanliness_rule" label="Cleanliness Rule" section="booking" settings={settings} updateField={updateField} errors={fieldErrors} rows={2} />
          </div>
          <TextAreaField field="house_rules_damage_rule" label="Damage / Lost Property Rule" section="booking" settings={settings} updateField={updateField} errors={fieldErrors} rows={2} />
        </section>
      </>
    )
  }

  if (activeTab === 'attendance') {
    return (
      <>
        <div className="admin-form-row">
          <TextField field="standard_work_start_time" label="Standard Work Start" section="attendance" type="time" settings={settings} updateField={updateField} errors={fieldErrors} />
          <TextField field="standard_work_end_time" label="Standard Work End" section="attendance" type="time" settings={settings} updateField={updateField} errors={fieldErrors} />
        </div>
        <TextField field="late_grace_period_minutes" label="Late Grace Period Minutes" section="attendance" type="number" settings={settings} updateField={updateField} errors={fieldErrors} />
      </>
    )
  }

  if (activeTab === 'notifications') {
    return (
      <>
        <ToggleField field="booking_confirmation_email" label="Booking Confirmation Email" section="notifications" settings={settings} updateField={updateField} />
        <ToggleField field="booking_cancellation_email" label="Booking Cancellation Email" section="notifications" settings={settings} updateField={updateField} />
        <ToggleField field="announcement_notifications" label="Announcement Notifications" section="notifications" settings={settings} updateField={updateField} />
        <ToggleField field="low_priority_operational_notifications" label="Low-Priority Operational Notifications" section="notifications" settings={settings} updateField={updateField} />
        <p className="settings-note">Email delivery is configuration-only until notification sending is implemented.</p>
      </>
    )
  }

  if (activeTab === 'regional') {
    return (
      <>
        <SelectField field="timezone" label="Timezone" options={meta.timezones} section="regional" settings={settings} updateField={updateField} errors={fieldErrors} />
        <div className="admin-form-row">
          <SelectField field="date_format" label="Date Format" options={meta.date_formats} section="regional" settings={settings} updateField={updateField} errors={fieldErrors} />
          <SelectField field="time_format" label="Time Format" options={meta.time_formats} section="regional" settings={settings} updateField={updateField} errors={fieldErrors} />
        </div>
        <SelectField field="currency" label="Currency" options={meta.currencies} section="regional" settings={settings} updateField={updateField} errors={fieldErrors} />
      </>
    )
  }

  if (activeTab === 'payments') {
    return (
      <>
        <ToggleField field="paymongo_enabled" label="PayMongo Enabled" section="payments" settings={settings} updateField={updateField} />
        <SelectField field="paymongo_mode" label="PayMongo Mode" options={['test', 'live']} section="payments" settings={settings} updateField={updateField} errors={fieldErrors} />
        <div className="settings-status-grid">
          <StatusItem label="Public Key Configured" value={currentSection.paymongo_public_key_configured} />
          <StatusItem label="Secret Key Configured" value={currentSection.paymongo_secret_key_configured} />
          <StatusItem label="Payment Processing" value={currentSection.payment_processing_implemented} />
        </div>
        <p className="settings-note">Payment status is configuration-only. Secret keys are never returned to React.</p>
      </>
    )
  }

  return (
    <>
      <TextField field="attendance_device_name" label="Attendance Device Name" section="devices" settings={settings} updateField={updateField} errors={fieldErrors} />
      <TextField field="attendance_device_identifier" label="Device Identifier" section="devices" settings={settings} updateField={updateField} errors={fieldErrors} />
      <ToggleField field="attendance_device_enabled" label="Device Enabled" section="devices" settings={settings} updateField={updateField} />
      <p className="settings-note">Device settings are ready for future AS608 integration. Hardware communication is not implemented.</p>
    </>
  )
}

function TextField({ errors, field, label, section, settings, type = 'text', updateField }) {
  return (
    <label>
      <span>{label}</span>
      <input
        name={`${section}.${field}`}
        type={type}
        value={settings[section][field] ?? ''}
        onChange={(event) => updateField(section, field, type === 'number' ? Number(event.target.value) : event.target.value)}
      />
      {errors[`${section}.${field}`] ? <small role="alert">{errors[`${section}.${field}`]}</small> : null}
    </label>
  )
}

function TextAreaField({ errors, field, label, rows = 4, section, settings, updateField }) {
  return (
    <label>
      <span>{label}</span>
      <textarea value={settings[section][field] ?? ''} rows={rows} onChange={(event) => updateField(section, field, event.target.value)} />
      {errors[`${section}.${field}`] ? <small role="alert">{errors[`${section}.${field}`]}</small> : null}
    </label>
  )
}

function BrandPreview({ settings }) {
  const fontFamily = getResortNameFontFamily(settings.general?.resort_name_font)

  return (
    <div className="settings-brand-preview" aria-label="Resort name preview">
      <span>Live Preview</span>
      <ResortBrandName
        as="strong"
        className="resort-brand-name settings-brand-preview-name"
        style={fontFamily ? { fontFamily } : undefined}
      >
        {settings.general?.resort_name?.trim() || 'DMD Resort'}
      </ResortBrandName>
    </div>
  )
}

function BrandingSection({
  errors,
  removeBrandingFavicon,
  removeBrandingLogo,
  settings,
  updateBrandingFavicon,
  updateBrandingLogo,
  updateBrandingHeroSlot,
  removeBrandingHeroSlot,
  updateField,
}) {
  const [logoPreview, setLogoPreview] = useState('')
  const [faviconPreview, setFaviconPreview] = useState('')
  const logoFile = settings.branding.logo_file
  const faviconFile = settings.branding.favicon_file

  useEffect(() => {
    if (!logoFile) {
      setLogoPreview('')
      return undefined
    }

    const objectUrl = URL.createObjectURL(logoFile)
    setLogoPreview(objectUrl)

    return () => URL.revokeObjectURL(objectUrl)
  }, [logoFile])

  useEffect(() => {
    if (!faviconFile) {
      setFaviconPreview('')
      return undefined
    }

    const objectUrl = URL.createObjectURL(faviconFile)
    setFaviconPreview(objectUrl)

    return () => URL.revokeObjectURL(objectUrl)
  }, [faviconFile])

  const currentLogoUrl = logoPreview || settings.branding.logo_url || ''
  const currentFaviconUrl = faviconPreview || settings.branding.favicon_url || ''

  return (
    <>
      <section className="settings-image-upload branding-upload" aria-label="Resort logo">
        <div>
          <span>Resort Logo</span>
          <p>Recommended: square PNG, JPG, JPEG, or WEBP. Maximum size: 2 MB.</p>
        </div>
        <label className="settings-image-preview branding-preview" htmlFor="branding-logo">
          {currentLogoUrl ? (
            <img src={resolveAssetUrl(currentLogoUrl)} alt="Resort logo preview" />
          ) : (
            <span>No logo selected</span>
          )}
        </label>
        <input
          id="branding-logo"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          onChange={(event) => updateBrandingLogo(event.target.files?.[0] ?? null)}
        />
        <div className="settings-image-actions">
          <label className="outline-button" htmlFor="branding-logo">
            {currentLogoUrl ? 'Replace Logo' : 'Upload Logo'}
          </label>
          {currentLogoUrl ? (
            <button type="button" onClick={removeBrandingLogo}>
              Remove Logo
            </button>
          ) : null}
        </div>
        {errors['branding.logo_file'] ? <small role="alert">{errors['branding.logo_file']}</small> : null}
      </section>

      <section className="settings-image-upload branding-upload" aria-label="Website favicon">
        <div>
          <span>Website Icon / Favicon</span>
          <p>Prefer a square PNG or ICO. Maximum size: 1 MB.</p>
        </div>
        <label className="settings-image-preview branding-preview branding-favicon-preview" htmlFor="branding-favicon">
          {currentFaviconUrl ? (
            <img src={resolveAssetUrl(currentFaviconUrl)} alt="Favicon preview" />
          ) : (
            <span>No favicon selected</span>
          )}
        </label>
        <input
          id="branding-favicon"
          type="file"
          accept="image/x-icon,image/png,image/jpeg,image/webp,.ico"
          onChange={(event) => updateBrandingFavicon(event.target.files?.[0] ?? null)}
        />
        <div className="settings-image-actions">
          <label className="outline-button" htmlFor="branding-favicon">
            {currentFaviconUrl ? 'Replace Icon' : 'Upload Icon'}
          </label>
          {currentFaviconUrl ? (
            <button type="button" onClick={removeBrandingFavicon}>
              Remove Icon
            </button>
          ) : null}
        </div>
        {errors['branding.favicon_file'] ? <small role="alert">{errors['branding.favicon_file']}</small> : null}
      </section>

      <section className="settings-section-group">
        <div>
          <h3>Homepage Hero Slideshow</h3>
          <p className="settings-note">Upload up to six images. Empty slots are ignored when the animation is enabled.</p>
        </div>
        <ToggleField field="hero_animation_enabled" label="Hero Animation" section="branding" settings={settings} updateField={updateField} />
        <div className="hero-image-slots">
          {Array.from({ length: 6 }, (_, index) => index + 1).map((slot) => (
            <HeroImageSlot
              key={slot}
              errors={errors}
              settings={settings}
              slot={slot}
              updateFile={updateBrandingHeroSlot}
              removeFile={removeBrandingHeroSlot}
            />
          ))}
        </div>
      </section>
    </>
  )
}

function HeroImageSlot({ errors, removeFile, settings, slot, updateFile }) {
  const [previewUrl, setPreviewUrl] = useState('')
  const [showRemoveConfirmation, setShowRemoveConfirmation] = useState(false)
  const key = `hero_image_${slot}`
  const file = settings.branding[`${key}_file`]
  const imageUrl = previewUrl || settings.branding[`${key}_url`] || (slot === 1 ? settings.branding.homepage_hero_image_url : '') || ''

  useEffect(() => {
    if (!file) {
      setPreviewUrl('')
      return undefined
    }

    const objectUrl = URL.createObjectURL(file)
    setPreviewUrl(objectUrl)
    return () => URL.revokeObjectURL(objectUrl)
  }, [file])

  return (
    <section className="settings-image-upload branding-upload hero-image-slot" aria-label={`Hero Image ${slot}`}>
      <div>
        <span>Hero Image {slot}</span>
        <p>JPG, PNG, or WebP up to 5 MB.</p>
      </div>
      <label className="settings-image-preview branding-preview branding-hero-preview" htmlFor={`${key}-file`}>
        {imageUrl ? <img src={resolveAssetUrl(imageUrl)} alt={`Hero Image ${slot} preview`} /> : <span>No image selected</span>}
      </label>
      <input
        id={`${key}-file`}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        onChange={(event) => updateFile(slot, event.target.files?.[0] ?? null)}
      />
      <div className="settings-image-actions">
        <label className="outline-button" htmlFor={`${key}-file`}>{imageUrl ? 'Replace Image' : 'Upload Image'}</label>
        {imageUrl ? <button type="button" onClick={() => setShowRemoveConfirmation(true)}>Remove Image</button> : null}
      </div>
      {errors[`branding.${key}_file`] ? <small role="alert">{errors[`branding.${key}_file`]}</small> : null}
      {showRemoveConfirmation ? (
        <div className="settings-confirm-backdrop" role="presentation">
          <section className="settings-confirm-modal" role="dialog" aria-modal="true" aria-labelledby={`${key}-remove-title`}>
            <h3 id={`${key}-remove-title`}>Remove this hero image?</h3>
            <p>This image will be removed from the homepage hero slideshow.</p>
            <div className="settings-confirm-actions">
              <button type="button" className="outline-button" onClick={() => setShowRemoveConfirmation(false)}>Cancel</button>
              <button type="button" className="solid-button" onClick={() => { setShowRemoveConfirmation(false); void removeFile(slot) }}>Remove Image</button>
            </div>
          </section>
        </div>
      ) : null}
    </section>
  )
}

function AboutImageField({ errors, removeAboutImage, settings, updateAboutImage }) {
  const [previewUrl, setPreviewUrl] = useState('')
  const file = settings.about.about_image_file

  useEffect(() => {
    if (!file) {
      setPreviewUrl('')
      return undefined
    }

    const objectUrl = URL.createObjectURL(file)
    setPreviewUrl(objectUrl)

    return () => URL.revokeObjectURL(objectUrl)
  }, [file])

  const imageUrl = previewUrl || settings.about.image_url || ''

  return (
    <section className="settings-image-upload" aria-label="About Us image">
      <div>
        <span>Optional About Us Image</span>
        <p>Upload a JPG, PNG, or WebP image up to 5 MB.</p>
      </div>
      <label className="settings-image-preview" htmlFor="about-image">
        {imageUrl ? (
          <img src={resolveAssetUrl(imageUrl)} alt="About Us preview" />
        ) : (
          <span>No image selected</span>
        )}
      </label>
      <input
        id="about-image"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        onChange={(event) => updateAboutImage(event.target.files?.[0] ?? null)}
      />
      <div className="settings-image-actions">
        <label className="outline-button" htmlFor="about-image">
          {imageUrl ? 'Replace Image' : 'Upload Image'}
        </label>
        {imageUrl ? (
          <button type="button" onClick={removeAboutImage}>
            Remove Image
          </button>
        ) : null}
      </div>
      {errors['about.about_image'] ? <small role="alert">{errors['about.about_image']}</small> : null}
    </section>
  )
}

function SelectField({ errors, field, label, options, section, settings, updateField }) {
  return (
    <label>
      <span>{label}</span>
      <select value={settings[section][field] ?? ''} onChange={(event) => updateField(section, field, event.target.value)}>
        {options.map((option) => {
          const value = Array.isArray(option) ? option[0] : option
          const text = Array.isArray(option) ? option[1] : option

          return (
            <option key={value} value={value}>
              {text}
            </option>
          )
        })}
      </select>
      {errors[`${section}.${field}`] ? <small role="alert">{errors[`${section}.${field}`]}</small> : null}
    </label>
  )
}

function ToggleField({ field, label, section, settings, updateField }) {
  return (
    <label className="admin-checkbox-field settings-toggle">
      <span>{label}</span>
      <input
        checked={Boolean(settings[section][field])}
        type="checkbox"
        onChange={(event) => updateField(section, field, event.target.checked)}
      />
      <strong>{settings[section][field] ? 'Enabled' : 'Disabled'}</strong>
    </label>
  )
}

function StatusItem({ label, value }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value ? 'Configured' : 'Not configured'}</strong>
    </div>
  )
}

function sectionTitle(section) {
  return {
    general: 'Resort information',
    branding: 'Branding and identity',
    about: 'About Us content',
    contact: 'Contact information',
    booking: 'Booking rules',
    attendance: 'Attendance defaults',
    notifications: 'Notification toggles',
    regional: 'Regional preferences',
    payments: 'Payment configuration status',
    devices: 'IoT and attendance devices',
  }[section]
}

function buildPayload(settings) {
  const payload = {
    general: settings.general,
    branding: {
      logo_path: settings.branding.logo_path,
      favicon_path: settings.branding.favicon_path,
      homepage_hero_image_path: settings.branding.homepage_hero_image_path,
      hero_animation_enabled: settings.branding.hero_animation_enabled,
      logo_file: settings.branding.logo_file,
      favicon_file: settings.branding.favicon_file,
      homepage_hero_image_file: settings.branding.homepage_hero_image_file,
      remove_logo: settings.branding.remove_logo,
      remove_favicon: settings.branding.remove_favicon,
      remove_homepage_hero_image: settings.branding.remove_homepage_hero_image,
    },
    about: {
      title: settings.about.title,
      short_introduction: settings.about.short_introduction,
      full_description: settings.about.full_description,
      mission: settings.about.mission,
      vision: settings.about.vision,
      image_path: settings.about.image_path,
      about_image_file: settings.about.about_image_file,
      remove_image: settings.about.remove_image,
    },
    contact: settings.contact,
    booking: settings.booking,
    attendance: settings.attendance,
    notifications: {
      booking_confirmation_email: settings.notifications.booking_confirmation_email,
      booking_cancellation_email: settings.notifications.booking_cancellation_email,
      announcement_notifications: settings.notifications.announcement_notifications,
      low_priority_operational_notifications: settings.notifications.low_priority_operational_notifications,
    },
    regional: settings.regional,
    payments: {
      paymongo_enabled: settings.payments.paymongo_enabled,
      paymongo_mode: settings.payments.paymongo_mode,
    },
    devices: settings.devices,
  }

  for (let slot = 1; slot <= 6; slot += 1) {
    const key = `hero_image_${slot}`
    payload.branding[`${key}_path`] = settings.branding[`${key}_path`]
    payload.branding[`${key}_file`] = settings.branding[`${key}_file`]
    payload.branding[`remove_${key}`] = settings.branding[`remove_${key}`]
  }

  if (
    payload.about.about_image_file ||
    payload.about.remove_image ||
    payload.branding.logo_file ||
    payload.branding.favicon_file ||
    payload.branding.homepage_hero_image_file ||
    payload.branding.remove_logo ||
    payload.branding.remove_favicon ||
    payload.branding.remove_homepage_hero_image ||
    Array.from({ length: 6 }, (_, index) => index + 1).some((slot) => payload.branding[`hero_image_${slot}_file`] || payload.branding[`remove_hero_image_${slot}`])
  ) {
    const formData = new FormData()
    const formPayload = {
      ...payload,
      branding: {
        ...payload.branding,
      },
      about: {
        ...payload.about,
      },
    }
    delete formPayload.branding.logo_file
    delete formPayload.branding.favicon_file
    delete formPayload.branding.homepage_hero_image_file
    delete formPayload.branding.remove_logo
    delete formPayload.branding.remove_favicon
    delete formPayload.branding.remove_homepage_hero_image
    for (let slot = 1; slot <= 6; slot += 1) {
      delete formPayload.branding[`hero_image_${slot}_file`]
      delete formPayload.branding[`remove_hero_image_${slot}`]
    }
    delete formPayload.about.about_image_file
    delete formPayload.about.remove_image

    formData.append('_method', 'PUT')
    appendFormData(formData, formPayload)

    if (payload.branding.logo_file) {
      formData.append('branding[logo_file]', payload.branding.logo_file)
    }

    if (payload.branding.favicon_file) {
      formData.append('branding[favicon_file]', payload.branding.favicon_file)
    }

    if (payload.branding.homepage_hero_image_file) {
      formData.append('branding[homepage_hero_image_file]', payload.branding.homepage_hero_image_file)
    }

    if (payload.branding.remove_logo) {
      formData.append('branding[remove_logo]', '1')
    }

    if (payload.branding.remove_favicon) {
      formData.append('branding[remove_favicon]', '1')
    }

    if (payload.branding.remove_homepage_hero_image) {
      formData.append('branding[remove_homepage_hero_image]', '1')
    }

    for (let slot = 1; slot <= 6; slot += 1) {
      const key = `hero_image_${slot}`
      if (payload.branding[`${key}_file`]) {
        formData.append(`branding[${key}_file]`, payload.branding[`${key}_file`])
      }
      if (payload.branding[`remove_${key}`]) {
        formData.append(`branding[remove_${key}]`, '1')
      }
    }

    if (payload.about.about_image_file) {
      formData.append('about[about_image]', payload.about.about_image_file)
    }

    if (payload.about.remove_image) {
      formData.append('about[remove_image]', '1')
    }

    return formData
  }

  delete payload.about.about_image_file
  delete payload.about.remove_image
  delete payload.branding.logo_file
  delete payload.branding.favicon_file
  delete payload.branding.homepage_hero_image_file
  delete payload.branding.remove_logo
  delete payload.branding.remove_favicon
  delete payload.branding.remove_homepage_hero_image
  for (let slot = 1; slot <= 6; slot += 1) {
    delete payload.branding[`hero_image_${slot}_file`]
    delete payload.branding[`remove_hero_image_${slot}`]
  }

  return payload
}

function appendFormData(formData, value, namespace = '') {
  Object.entries(value).forEach(([key, item]) => {
    if (item === undefined || item === null || item instanceof File) {
      return
    }

    const fieldName = namespace ? `${namespace}[${key}]` : key

    if (typeof item === 'object' && !Array.isArray(item)) {
      appendFormData(formData, item, fieldName)
      return
    }

    formData.append(fieldName, typeof item === 'boolean' ? (item ? '1' : '0') : String(item))
  })
}

export default AdminSettings
