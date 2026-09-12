import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import LoadingButton from '../components/LoadingButton'
import ResortBrandMark from '../components/ResortBrandMark'
import ResortBrandName from '../components/ResortBrandName'
import { useResortBranding } from '../hooks/useResortBranding'
import { ToastMessage } from '../context/ToastContext'

const initialValues = {
  first_name: '',
  last_name: '',
  email: '',
  contact_number: '',
  password: '',
  password_confirmation: '',
}

function validateForm(values) {
  const errors = {}
  const contactPattern = /^\+?[0-9\s().-]{7,20}$/

  if (!values.first_name.trim()) {
    errors.first_name = 'First name is required.'
  }

  if (!values.last_name.trim()) {
    errors.last_name = 'Last name is required.'
  }

  if (!values.email.trim()) {
    errors.email = 'Email address is required.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email.trim())) {
    errors.email = 'Enter a valid email address.'
  }

  if (!values.contact_number.trim()) {
    errors.contact_number = 'Contact number is required.'
  } else if (!contactPattern.test(values.contact_number.trim())) {
    errors.contact_number = 'Enter a valid contact number.'
  }

  if (!values.password) {
    errors.password = 'Password is required.'
  } else if (values.password.length < 8) {
    errors.password = 'Password must be at least 8 characters.'
  } else if (!/[A-Za-z]/.test(values.password) || !/[0-9]/.test(values.password)) {
    errors.password = 'Password must include letters and numbers.'
  }

  if (!values.password_confirmation) {
    errors.password_confirmation = 'Confirm your password.'
  } else if (values.password_confirmation !== values.password) {
    errors.password_confirmation = 'Password confirmation does not match.'
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

function Register() {
  const navigate = useNavigate()
  const { register } = useAuth()
  const { resortName } = useResortBranding()
  const [values, setValues] = useState(initialValues)
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [visiblePasswords, setVisiblePasswords] = useState({
    password: false,
    password_confirmation: false,
  })

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

    const nextErrors = validateForm(values)
    setErrors(nextErrors)
    setFormError('')
    setSuccessMessage('')

    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setIsSubmitting(true)

    try {
      const result = await register({
        first_name: values.first_name.trim(),
        last_name: values.last_name.trim(),
        email: values.email.trim(),
        contact_number: values.contact_number.trim(),
        password: values.password,
        password_confirmation: values.password_confirmation,
      })

      const verificationEmail = result.email ?? result.user?.email
      if (result.registrationCancelToken && verificationEmail) {
        window.sessionStorage.setItem(`dmd-registration-cancel-token:${verificationEmail.trim().toLowerCase()}`, result.registrationCancelToken)
      }
      navigate(`/verify-email?email=${encodeURIComponent(verificationEmail)}`, {
        replace: true,
        state: { verificationMessage: result.message },
      })
    } catch (error) {
      if (error?.response?.status === 422) {
        const serverErrors = extractServerErrors(error)
        setErrors(serverErrors)
        setFormError(
          serverErrors.email === 'The email has already been taken.'
            ? 'An account already exists for this email address.'
            : 'Please review the highlighted fields.',
        )
      } else {
        setFormError('We could not create your account right now. Please try again.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  function togglePasswordVisibility(field) {
    setVisiblePasswords((current) => ({
      ...current,
      [field]: !current[field],
    }))
  }

  return (
    <section className="auth-page">
      <header className="auth-topbar">
        <Link className="brand" to="/">
          <ResortBrandMark />
          <ResortBrandName className="resort-brand-name" />
        </Link>
        <Link className="text-link auth-home-link" to="/">
          Back to Home
        </Link>
      </header>

      <div className="auth-shell register-shell">
        <div className="auth-visual" aria-label="DMD Resort photography placeholder">
          <div>
            <p className="eyebrow">Guest account</p>
            <h1>
              Start your <ResortBrandName as="span" className="resort-brand-name" /> stay
            </h1>
          </div>
          <span>Resort photography pending</span>
        </div>

        <div className="login-panel">
          <div className="login-heading">
            <p className="eyebrow">Register</p>
            <h1>Create your account</h1>
            <p>
              Register as a guest to manage future <ResortBrandName as="span" className="resort-brand-name" /> stays.
            </p>
          </div>

          {formError ? (
            <div className="form-alert" role="alert">
              {formError}
            </div>
          ) : null}

          <ToastMessage message={successMessage} />

          <form className="login-form" noValidate onSubmit={handleSubmit}>
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

            <label className="form-field" htmlFor="email">
              <span>Email Address</span>
              <input
                id="email"
                name="email"
                type="email"
                autoComplete="email"
                value={values.email}
                onChange={updateField}
                aria-invalid={Boolean(errors.email)}
                aria-describedby={errors.email ? 'email-error' : undefined}
                disabled={isSubmitting}
                required
              />
              {errors.email ? (
                <small id="email-error" className="field-error">
                  {errors.email}
                </small>
              ) : null}
            </label>

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

            <label className="form-field" htmlFor="password">
              <span>Password</span>
              <div className="password-control">
                <input
                  id="password"
                  name="password"
                  type={visiblePasswords.password ? 'text' : 'password'}
                  autoComplete="new-password"
                  value={values.password}
                  onChange={updateField}
                  aria-invalid={Boolean(errors.password)}
                  aria-describedby={errors.password ? 'password-error' : undefined}
                  disabled={isSubmitting}
                  required
                />
                <button
                  type="button"
                  className="password-toggle"
                  onClick={() => togglePasswordVisibility('password')}
                  aria-label={visiblePasswords.password ? 'Hide password' : 'Show password'}
                  disabled={isSubmitting}
                >
                  {visiblePasswords.password ? 'Hide' : 'Show'}
                </button>
              </div>
              {errors.password ? (
                <small id="password-error" className="field-error">
                  {errors.password}
                </small>
              ) : null}
            </label>

            <label className="form-field" htmlFor="password_confirmation">
              <span>Confirm Password</span>
              <div className="password-control">
                <input
                  id="password_confirmation"
                  name="password_confirmation"
                  type={visiblePasswords.password_confirmation ? 'text' : 'password'}
                  autoComplete="new-password"
                  value={values.password_confirmation}
                  onChange={updateField}
                  aria-invalid={Boolean(errors.password_confirmation)}
                  aria-describedby={
                    errors.password_confirmation ? 'password-confirmation-error' : undefined
                  }
                  disabled={isSubmitting}
                  required
                />
                <button
                  type="button"
                  className="password-toggle"
                  onClick={() => togglePasswordVisibility('password_confirmation')}
                  aria-label={
                    visiblePasswords.password_confirmation ? 'Hide password' : 'Show password'
                  }
                  disabled={isSubmitting}
                >
                  {visiblePasswords.password_confirmation ? 'Hide' : 'Show'}
                </button>
              </div>
              {errors.password_confirmation ? (
                <small id="password-confirmation-error" className="field-error">
                  {errors.password_confirmation}
                </small>
              ) : null}
            </label>

            <LoadingButton
              className="solid-button full-width"
              type="submit"
              isLoading={isSubmitting}
              loadingLabel="Creating account..."
            >
              Create Account
            </LoadingButton>
          </form>

          <p className="auth-switch">
            Already have an account? <Link to="/login">Sign In</Link>
          </p>
        </div>
      </div>
    </section>
  )
}

export default Register
