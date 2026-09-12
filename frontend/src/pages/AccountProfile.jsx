import { useEffect, useState } from 'react'
import { LogOut, Shield, WalletCards } from 'lucide-react'
import { Link } from 'react-router-dom'
import CustomerAccountLayout from './CustomerAccountLayout'
import { useAuth } from '../hooks/useAuth'
import LoadingButton from '../components/LoadingButton'
import { useResortBranding } from '../hooks/useResortBranding'
import { ToastMessage } from '../context/ToastContext'

function validateProfile(values) {
  const errors = {}
  const contactPattern = /^\+?[0-9\s().-]{7,20}$/

  if (!values.first_name.trim()) {
    errors.first_name = 'First name is required.'
  }

  if (!values.last_name.trim()) {
    errors.last_name = 'Last name is required.'
  }

  if (!values.contact_number.trim()) {
    errors.contact_number = 'Contact number is required.'
  } else if (!contactPattern.test(values.contact_number.trim())) {
    errors.contact_number = 'Enter a valid contact number.'
  }

  return errors
}

function extractServerErrors(error) {
  const responseErrors = error?.response?.data?.errors

  if (!responseErrors) {
    return {}
  }

  return Object.fromEntries(
    Object.entries(responseErrors).map(([field, messages]) => [
      field,
      Array.isArray(messages) ? messages[0] : messages,
    ]),
  )
}

function AccountProfile() {
  const { updateProfile, user, signOut } = useAuth()
  const [values, setValues] = useState({
    first_name: '',
    last_name: '',
    contact_number: '',
  })
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const { resortName } = useResortBranding()

  useEffect(() => {
    setValues({
      first_name: user?.first_name ?? '',
      last_name: user?.last_name ?? '',
      contact_number: user?.contact_number ?? '',
    })
  }, [user])

  function updateField(event) {
    const { name, value } = event.target

    setValues((current) => ({
      ...current,
      [name]: value,
    }))

    setErrors((current) => ({
      ...current,
      [name]: '',
    }))
    setFormError('')
    setSuccessMessage('')
  }

  async function handleSubmit(event) {
    event.preventDefault()

    const nextErrors = validateProfile(values)
    setErrors(nextErrors)
    setFormError('')
    setSuccessMessage('')

    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setIsSubmitting(true)

    try {
      const result = await updateProfile({
        first_name: values.first_name.trim(),
        last_name: values.last_name.trim(),
        contact_number: values.contact_number.trim(),
      })
      setSuccessMessage(result.message ?? 'Profile updated.')
    } catch (error) {
      if (error?.response?.status === 422) {
        setErrors(extractServerErrors(error))
        setFormError('Please review the highlighted fields.')
      } else {
        setFormError('Unable to update your profile right now. Please try again.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <CustomerAccountLayout
      title="Profile"
      description={`Update the guest details ${resortName} can use for future reservations.`}
    >
      <section className="account-profile-mobile-actions" aria-label="Profile shortcuts">
        <Link className="account-action-card" to="/account/security">
          <span className="account-action-icon">
            <Shield size={16} aria-hidden="true" />
          </span>
          <strong>Security</strong>
        </Link>
        <Link className="account-action-card" to="/account/payments">
          <span className="account-action-icon">
            <WalletCards size={16} aria-hidden="true" />
          </span>
          <strong>Payments</strong>
        </Link>
        <button type="button" className="account-action-card account-signout-card" onClick={() => signOut()}>
          <span className="account-action-icon">
            <LogOut size={16} aria-hidden="true" />
          </span>
          <strong>Sign Out</strong>
        </button>
      </section>

      <form className="account-form" noValidate onSubmit={handleSubmit}>
        {formError ? (
          <div className="form-alert" role="alert">
            {formError}
          </div>
        ) : null}

        <ToastMessage message={successMessage} />

        <div className="form-grid">
          <label className="form-field" htmlFor="first_name">
            <span>First Name</span>
            <input
              id="first_name"
              name="first_name"
              type="text"
              autoComplete="given-name"
              value={values.first_name}
              onChange={updateField}
              aria-invalid={Boolean(errors.first_name)}
              aria-describedby={errors.first_name ? 'first-name-error' : undefined}
              disabled={isSubmitting}
              required
            />
            {errors.first_name ? (
              <small id="first-name-error" className="field-error">
                {errors.first_name}
              </small>
            ) : null}
          </label>

          <label className="form-field" htmlFor="last_name">
            <span>Last Name</span>
            <input
              id="last_name"
              name="last_name"
              type="text"
              autoComplete="family-name"
              value={values.last_name}
              onChange={updateField}
              aria-invalid={Boolean(errors.last_name)}
              aria-describedby={errors.last_name ? 'last-name-error' : undefined}
              disabled={isSubmitting}
              required
            />
            {errors.last_name ? (
              <small id="last-name-error" className="field-error">
                {errors.last_name}
              </small>
            ) : null}
          </label>
        </div>

        <label className="form-field" htmlFor="contact_number">
          <span>Contact Number</span>
          <input
            id="contact_number"
            name="contact_number"
            type="tel"
            autoComplete="tel"
            value={values.contact_number}
            onChange={updateField}
            aria-invalid={Boolean(errors.contact_number)}
            aria-describedby={errors.contact_number ? 'contact-number-error' : undefined}
            disabled={isSubmitting}
            required
          />
          {errors.contact_number ? (
            <small id="contact-number-error" className="field-error">
              {errors.contact_number}
            </small>
          ) : null}
        </label>

        <div className="locked-account-fields" aria-label="Protected account fields">
          <div>
            <span>Email Address</span>
            <strong>{user?.email}</strong>
          </div>
          <p>Email, password, and account role cannot be changed from this page.</p>
        </div>

        <LoadingButton
          className="solid-button account-submit"
          type="submit"
          isLoading={isSubmitting}
          loadingLabel="Saving..."
        >
          Save Changes
        </LoadingButton>
      </form>
    </CustomerAccountLayout>
  )
}

export default AccountProfile

