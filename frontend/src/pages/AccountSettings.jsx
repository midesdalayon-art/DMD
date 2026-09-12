import { useEffect, useMemo, useState } from 'react'
import { Camera, Eye, EyeOff, LockKeyhole, UserRound } from 'lucide-react'
import { useSearchParams } from 'react-router-dom'
import CustomerAccountLayout from './CustomerAccountLayout'
import LoadingButton from '../components/LoadingButton'
import { useAuth } from '../hooks/useAuth'
import { updatePassword } from '../lib/api'
import { ToastMessage } from '../context/ToastContext'

function validateProfile(values) {
  const errors = {}
  const contactPattern = /^\+?[0-9\s().-]{7,20}$/
  if (!values.first_name.trim()) errors.first_name = 'First name is required.'
  if (!values.last_name.trim()) errors.last_name = 'Last name is required.'
  if (!values.contact_number.trim()) errors.contact_number = 'Contact number is required.'
  else if (!contactPattern.test(values.contact_number.trim())) errors.contact_number = 'Enter a valid contact number.'
  return errors
}

function validatePassword(values) {
  const errors = {}
  if (!values.current_password) errors.current_password = 'Current password is required.'
  if (!values.password) errors.password = 'New password is required.'
  else if (values.password.length < 8) errors.password = 'Password must be at least 8 characters.'
  if (!values.password_confirmation) errors.password_confirmation = 'Confirm your new password.'
  else if (values.password_confirmation !== values.password) errors.password_confirmation = 'Password confirmation does not match.'
  return errors
}

function extractServerErrors(error) {
  const responseErrors = error?.response?.data?.errors
  if (!responseErrors) return {}
  return Object.fromEntries(Object.entries(responseErrors).map(([field, messages]) => [field, Array.isArray(messages) ? messages[0] : messages]))
}

function AccountSettings() {
  const { updateProfile, user } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const activeTab = searchParams.get('tab') === 'security' ? 'security' : 'profile'
  const [profileValues, setProfileValues] = useState({ first_name: '', last_name: '', contact_number: '' })
  const [profileErrors, setProfileErrors] = useState({})
  const [profileError, setProfileError] = useState('')
  const [profileSuccess, setProfileSuccess] = useState('')
  const [profileSubmitting, setProfileSubmitting] = useState(false)
  const [passwordValues, setPasswordValues] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [passwordErrors, setPasswordErrors] = useState({})
  const [passwordError, setPasswordError] = useState('')
  const [passwordSuccess, setPasswordSuccess] = useState('')
  const [passwordSubmitting, setPasswordSubmitting] = useState(false)
  const [visiblePasswords, setVisiblePasswords] = useState({})

  useEffect(() => {
    setProfileValues({ first_name: user?.first_name ?? '', last_name: user?.last_name ?? '', contact_number: user?.contact_number ?? '' })
  }, [user])

  const displayName = useMemo(() => [user?.first_name, user?.last_name].filter(Boolean).join(' ') || 'Guest', [user])
  const initials = useMemo(() => displayName.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase(), [displayName])

  function switchTab(tab) {
    setSearchParams(tab === 'profile' ? {} : { tab }, { replace: true })
  }

  function updateProfileField(event) {
    const { name, value } = event.target
    setProfileValues((current) => ({ ...current, [name]: value }))
    setProfileErrors((current) => ({ ...current, [name]: '' }))
    setProfileError('')
    setProfileSuccess('')
  }

  async function handleProfileSubmit(event) {
    event.preventDefault()
    const nextErrors = validateProfile(profileValues)
    setProfileErrors(nextErrors)
    setProfileError('')
    setProfileSuccess('')
    if (Object.keys(nextErrors).length > 0) return
    setProfileSubmitting(true)
    try {
      const result = await updateProfile({ first_name: profileValues.first_name.trim(), last_name: profileValues.last_name.trim(), contact_number: profileValues.contact_number.trim() })
      setProfileSuccess(result.message ?? 'Profile updated.')
    } catch (error) {
      if (error?.response?.status === 422) {
        setProfileErrors(extractServerErrors(error))
        setProfileError('Please review the highlighted fields.')
      } else setProfileError('Unable to update your profile right now. Please try again.')
    } finally {
      setProfileSubmitting(false)
    }
  }

  function updatePasswordField(event) {
    const { name, value } = event.target
    setPasswordValues((current) => ({ ...current, [name]: value }))
    setPasswordErrors((current) => ({ ...current, [name]: '' }))
    setPasswordError('')
    setPasswordSuccess('')
  }

  async function handlePasswordSubmit(event) {
    event.preventDefault()
    const nextErrors = validatePassword(passwordValues)
    setPasswordErrors(nextErrors)
    setPasswordError('')
    setPasswordSuccess('')
    if (Object.keys(nextErrors).length > 0) return
    setPasswordSubmitting(true)
    try {
      const result = await updatePassword(passwordValues)
      setPasswordSuccess(result.message ?? 'Password updated.')
      setPasswordValues({ current_password: '', password: '', password_confirmation: '' })
    } catch (error) {
      if (error?.response?.status === 422) {
        const serverErrors = extractServerErrors(error)
        setPasswordErrors(serverErrors)
        setPasswordError(serverErrors.current_password ?? 'Please review the highlighted fields.')
      } else setPasswordError('Unable to update your password right now. Please try again.')
    } finally {
      setPasswordSubmitting(false)
    }
  }

  function togglePassword(name) {
    setVisiblePasswords((current) => ({ ...current, [name]: !current[name] }))
  }

  return (
    <CustomerAccountLayout title="Settings">
      <div className="account-settings">
        <section className="settings-panel" role="tabpanel" aria-label={`${activeTab === 'profile' ? 'Profile' : 'Security'} settings`}>
          <div className="account-settings-tabs" role="tablist" aria-label="Account settings">
            <button id="account-settings-profile-tab" type="button" role="tab" aria-selected={activeTab === 'profile'} aria-controls="account-settings-panel" className={activeTab === 'profile' ? 'is-active' : ''} onClick={() => switchTab('profile')}>Profile</button>
            <button id="account-settings-security-tab" type="button" role="tab" aria-selected={activeTab === 'security'} aria-controls="account-settings-panel" className={activeTab === 'security' ? 'is-active' : ''} onClick={() => switchTab('security')}>Security</button>
          </div>

          <div id="account-settings-panel" className="settings-tab-content">
          {activeTab === 'profile' ? (
            <>
            <div className="settings-identity">
              <div className="settings-avatar" aria-hidden="true">{initials}</div>
              <div className="settings-identity-copy"><h2>{displayName}</h2><p>{user?.email}</p></div>
              <button className="settings-photo-button" type="button" disabled title="Profile photo upload is not available yet"><Camera size={15} aria-hidden="true" /> Change Photo</button>
            </div>
            <div className="settings-section-heading"><UserRound size={17} aria-hidden="true" /><div><h3>Personal Information</h3><p>Update the details used for your reservations.</p></div></div>
            <form className="settings-form" noValidate onSubmit={handleProfileSubmit}>
              {profileError ? <div className="form-alert" role="alert">{profileError}</div> : null}
              <ToastMessage message={profileSuccess} />
              <div className="form-grid">
                <SettingsField id="settings-first-name" name="first_name" label="First Name" value={profileValues.first_name} onChange={updateProfileField} error={profileErrors.first_name} disabled={profileSubmitting} autoComplete="given-name" />
                <SettingsField id="settings-last-name" name="last_name" label="Last Name" value={profileValues.last_name} onChange={updateProfileField} error={profileErrors.last_name} disabled={profileSubmitting} autoComplete="family-name" />
              </div>
              <SettingsField id="settings-contact-number" name="contact_number" label="Contact Number" value={profileValues.contact_number} onChange={updateProfileField} error={profileErrors.contact_number} disabled={profileSubmitting} autoComplete="tel" type="tel" />
              <div className="settings-readonly"><span>Email Address</span><strong>{user?.email}</strong><small>Email, password, and account role cannot be changed here.</small></div>
              <LoadingButton className="solid-button account-submit" type="submit" isLoading={profileSubmitting} loadingLabel="Saving...">Save Changes</LoadingButton>
            </form>
            </>
          ) : (
            <>
              <div className="settings-section-heading"><LockKeyhole size={17} aria-hidden="true" /><div><h3>Password</h3><p>Keep your guest account secure with a strong password.</p></div></div>
              <form className="settings-form" noValidate onSubmit={handlePasswordSubmit}>
                {passwordError ? <div className="form-alert" role="alert">{passwordError}</div> : null}
                <ToastMessage message={passwordSuccess} />
                <PasswordField id="settings-current-password" name="current_password" label="Current Password" value={passwordValues.current_password} onChange={updatePasswordField} error={passwordErrors.current_password} disabled={passwordSubmitting} visible={visiblePasswords.current_password} onToggle={() => togglePassword('current_password')} autoComplete="current-password" />
                <div className="form-grid">
                  <PasswordField id="settings-password" name="password" label="New Password" value={passwordValues.password} onChange={updatePasswordField} error={passwordErrors.password} disabled={passwordSubmitting} visible={visiblePasswords.password} onToggle={() => togglePassword('password')} autoComplete="new-password" />
                  <PasswordField id="settings-password-confirmation" name="password_confirmation" label="Confirm New Password" value={passwordValues.password_confirmation} onChange={updatePasswordField} error={passwordErrors.password_confirmation} disabled={passwordSubmitting} visible={visiblePasswords.password_confirmation} onToggle={() => togglePassword('password_confirmation')} autoComplete="new-password" />
                </div>
                <LoadingButton className="solid-button account-submit" type="submit" isLoading={passwordSubmitting} loadingLabel="Updating...">Update Password</LoadingButton>
              </form>
            </>
          )}
          </div>
        </section>
      </div>
    </CustomerAccountLayout>
  )
}

function SettingsField({ id, name, label, value, onChange, error, disabled, autoComplete, type = 'text' }) {
  return <label className="form-field" htmlFor={id}><span>{label}</span><input id={id} name={name} type={type} autoComplete={autoComplete} value={value} onChange={onChange} aria-invalid={Boolean(error)} disabled={disabled} required />{error ? <small className="field-error">{error}</small> : null}</label>
}

function PasswordField({ id, name, label, value, onChange, error, disabled, visible, onToggle, autoComplete }) {
  return <label className="form-field settings-password-field" htmlFor={id}><span>{label}</span><div className="settings-password-input"><input id={id} name={name} type={visible ? 'text' : 'password'} autoComplete={autoComplete} value={value} onChange={onChange} aria-invalid={Boolean(error)} disabled={disabled} required /><button type="button" onClick={onToggle} aria-label={(visible ? 'Hide ' : 'Show ') + label}><span className="sr-only">{visible ? 'Hide' : 'Show'} password</span>{visible ? <EyeOff size={16} aria-hidden="true" /> : <Eye size={16} aria-hidden="true" />}</button></div>{error ? <small className="field-error">{error}</small> : null}</label>
}

export default AccountSettings
