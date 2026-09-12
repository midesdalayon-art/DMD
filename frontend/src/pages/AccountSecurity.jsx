import { useState } from 'react'
import CustomerAccountLayout from './CustomerAccountLayout'
import LoadingButton from '../components/LoadingButton'
import { ToastMessage } from '../context/ToastContext'
import { updatePassword } from '../lib/api'

function validatePassword(values) {
  const errors = {}

  if (!values.current_password) {
    errors.current_password = 'Current password is required.'
  }

  if (!values.password) {
    errors.password = 'New password is required.'
  } else if (values.password.length < 8) {
    errors.password = 'Password must be at least 8 characters.'
  }

  if (!values.password_confirmation) {
    errors.password_confirmation = 'Confirm your new password.'
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

function AccountSecurity() {
  const [values, setValues] = useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  })
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

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

    const nextErrors = validatePassword(values)
    setErrors(nextErrors)
    setFormError('')
    setSuccessMessage('')

    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setIsSubmitting(true)

    try {
      const result = await updatePassword({
        current_password: values.current_password,
        password: values.password,
        password_confirmation: values.password_confirmation,
      })
      setSuccessMessage(result.message ?? 'Password updated.')
      setValues({
        current_password: '',
        password: '',
        password_confirmation: '',
      })
    } catch (error) {
      if (error?.response?.status === 422) {
        const serverErrors = extractServerErrors(error)
        setErrors(serverErrors)
        setFormError(serverErrors.current_password ?? 'Please review the highlighted fields.')
      } else {
        setFormError('Unable to update your password right now. Please try again.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <CustomerAccountLayout
      title="Security"
      description="Update your password to keep your guest account secure."
    >
      <form className="account-form" noValidate onSubmit={handleSubmit}>
        {formError ? (
          <div className="form-alert" role="alert">
            {formError}
          </div>
        ) : null}

        <ToastMessage message={successMessage} />

        <label className="form-field" htmlFor="current_password">
          <span>Current Password</span>
          <input
            id="current_password"
            name="current_password"
            type="password"
            autoComplete="current-password"
            value={values.current_password}
            onChange={updateField}
            aria-invalid={Boolean(errors.current_password)}
            aria-describedby={errors.current_password ? 'current-password-error' : undefined}
            disabled={isSubmitting}
            required
          />
          {errors.current_password ? (
            <small id="current-password-error" className="field-error">
              {errors.current_password}
            </small>
          ) : null}
        </label>

        <div className="form-grid">
          <label className="form-field" htmlFor="password">
            <span>New Password</span>
            <input
              id="password"
              name="password"
              type="password"
              autoComplete="new-password"
              value={values.password}
              onChange={updateField}
              aria-invalid={Boolean(errors.password)}
              aria-describedby={errors.password ? 'password-error' : undefined}
              disabled={isSubmitting}
              required
            />
            {errors.password ? (
              <small id="password-error" className="field-error">
                {errors.password}
              </small>
            ) : null}
          </label>

          <label className="form-field" htmlFor="password_confirmation">
            <span>Confirm Password</span>
            <input
              id="password_confirmation"
              name="password_confirmation"
              type="password"
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
            {errors.password_confirmation ? (
              <small id="password-confirmation-error" className="field-error">
                {errors.password_confirmation}
              </small>
            ) : null}
          </label>
        </div>

        <div className="security-note">
          <strong>Keep your account secure</strong>
          <p>Use a strong password and avoid sharing your guest account with others.</p>
        </div>

        <LoadingButton
          className="solid-button account-submit"
          type="submit"
          isLoading={isSubmitting}
          loadingLabel="Updating..."
        >
          Update Password
        </LoadingButton>
      </form>
    </CustomerAccountLayout>
  )
}

export default AccountSecurity

