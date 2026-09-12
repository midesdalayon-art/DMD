import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import LoadingButton from './LoadingButton'
import ResortBrandMark from './ResortBrandMark'
import ResortBrandName from './ResortBrandName'
import { redirectToGoogle } from '../lib/api'

const roleRedirects = {
  admin: '/admin/dashboard',
  manager: '/manager/dashboard',
  front_desk_staff: '/frontdesk/dashboard',
}

const registerInitialValues = {
  first_name: '',
  last_name: '',
  email: '',
  contact_number: '',
  password: '',
  password_confirmation: '',
}

const phoneCountries = [
  { code: 'PH', name: 'Philippines', dialCode: '63', nationalLengths: [10] },
  { code: 'US', name: 'United States', dialCode: '1', nationalLengths: [10] },
  { code: 'CA', name: 'Canada', dialCode: '1', nationalLengths: [10] },
  { code: 'GB', name: 'United Kingdom', dialCode: '44', nationalLengths: [10, 11] },
  { code: 'AU', name: 'Australia', dialCode: '61', nationalLengths: [9] },
  { code: 'SG', name: 'Singapore', dialCode: '65', nationalLengths: [8] },
  { code: 'MY', name: 'Malaysia', dialCode: '60', nationalLengths: [9, 10] },
  { code: 'JP', name: 'Japan', dialCode: '81', nationalLengths: [9, 10] },
  { code: 'KR', name: 'South Korea', dialCode: '82', nationalLengths: [9, 10] },
  { code: 'AE', name: 'United Arab Emirates', dialCode: '971', nationalLengths: [9] },
]

function validateSignIn(values) {
  const errors = {}

  if (!values.email.trim()) {
    errors.email = 'Email address is required.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email.trim())) {
    errors.email = 'Enter a valid email address.'
  }

  if (!values.password) {
    errors.password = 'Password is required.'
  }

  return errors
}

function validateRegister(values, countryCode = 'PH') {
  const errors = {}
  const namePattern = /^\p{L}+(?:[ '\u2019-]\p{L}+)*$/u
  const country = phoneCountries.find((item) => item.code === countryCode) ?? phoneCountries[0]

  if (!values.first_name.trim()) {
    errors.first_name = 'First name is required.'
  } else if (!namePattern.test(values.first_name.trim())) {
    errors.first_name = 'Use letters, spaces, hyphens, or apostrophes only.'
  }

  if (!values.last_name.trim()) {
    errors.last_name = 'Last name is required.'
  } else if (!namePattern.test(values.last_name.trim())) {
    errors.last_name = 'Use letters, spaces, hyphens, or apostrophes only.'
  }

  if (!values.email.trim()) {
    errors.email = 'Email address is required.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email.trim())) {
    errors.email = 'Enter a valid email address.'
  }

  if (!values.contact_number.trim()) {
    errors.contact_number = 'Contact number is required.'
  } else if (!country.nationalLengths.includes(values.contact_number.trim().length)) {
    errors.contact_number = `Enter a valid ${country.name} phone number.`
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
    errors.password_confirmation = 'Passwords do not match.'
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

function AuthModal({ initialMode = 'signin', onClose, title = null, subtitle = null, returnFocusRef = null, redirectLocation = null }) {
  const [mode, setMode] = useState(initialMode)
  const [signInValues, setSignInValues] = useState({
    email: '',
    password: '',
    remember: false,
  })
  const [registerValues, setRegisterValues] = useState(registerInitialValues)
  const [phoneCountry, setPhoneCountry] = useState('PH')
  const [errors, setErrors] = useState({})
  const [formError, setFormError] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [visiblePasswords, setVisiblePasswords] = useState({
    signIn: false,
    password: false,
    password_confirmation: false,
  })
  const modalRef = useRef(null)
  const navigate = useNavigate()
  const { register, signIn } = useAuth()

  const titleId = useMemo(() => `auth-modal-title-${mode}`, [mode])
  const isSignIn = mode === 'signin'

  useEffect(() => {
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    window.setTimeout(() => {
      const firstInput = modalRef.current?.querySelector('input, button, a')
      firstInput?.focus()
    }, 0)

    return () => {
      document.body.style.overflow = previousOverflow
      window.setTimeout(() => {
        returnFocusRef?.current?.focus?.()
      }, 0)
    }
  }, [returnFocusRef])

  useEffect(() => {
    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        onClose()
        return
      }

      if (event.key !== 'Tab') {
        return
      }

      const focusableElements = modalRef.current?.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
      )
      const focusable = Array.from(focusableElements ?? [])

      if (focusable.length === 0) {
        return
      }

      const first = focusable[0]
      const last = focusable[focusable.length - 1]

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [onClose])

  function switchMode(nextMode) {
    setMode(nextMode)
    setErrors({})
    setFormError('')
  }

  function updateSignInField(event) {
    const { checked, name, type, value } = event.target

    setSignInValues((current) => ({
      ...current,
      [name]: type === 'checkbox' ? checked : value,
    }))
    setErrors((current) => ({ ...current, [name]: '' }))
    setFormError('')
  }

  function updateRegisterField(event) {
    const { name } = event.target
    const value = name === 'contact_number'
      ? event.target.value.replace(/\D/g, '').replace(/^0+/, '').slice(0, 11)
      : event.target.value
    const nextValues = { ...registerValues, [name]: value }

    setRegisterValues(nextValues)
    setErrors((current) => {
      const next = { ...current, [name]: '' }
      if ((name === 'password' || name === 'password_confirmation') &&
          (current.password_confirmation || name === 'password_confirmation')) {
        next.password_confirmation = validateRegister(nextValues).password_confirmation ?? ''
      }
      return next
    })
    setFormError('')
  }

  function validateRegisterField(name) {
    const fieldErrors = validateRegister(registerValues, phoneCountry)
    setErrors((current) => ({ ...current, [name]: fieldErrors[name] ?? '' }))
  }

  async function handleSignInSubmit(event) {
    event.preventDefault()

    const nextErrors = validateSignIn(signInValues)
    setErrors(nextErrors)
    setFormError('')

    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setIsSubmitting(true)

    try {
      const user = await signIn({
        email: signInValues.email.trim(),
        password: signInValues.password,
        remember: signInValues.remember,
      })
      onClose()

      if (roleRedirects[user?.role]) {
        navigate(user.redirect_to ?? roleRedirects[user.role], { replace: true })
      } else if (redirectLocation?.pathname && redirectLocation.pathname !== '/login') {
        navigate(
          `${redirectLocation.pathname}${redirectLocation.search ?? ''}${redirectLocation.hash ?? ''}`,
          { replace: true },
        )
      }
    } catch (error) {
      if (error?.response?.status === 422) {
        setFormError(error?.response?.data?.errors?.email?.[0] ?? 'Invalid email or password.')
      } else {
        setFormError('We could not sign you in right now. Please try again.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleRegisterSubmit(event) {
    event.preventDefault()

    const nextErrors = validateRegister(registerValues, phoneCountry)
    setErrors(nextErrors)
    setFormError('')

    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setIsSubmitting(true)

    try {
      const result = await register({
        first_name: registerValues.first_name.trim(),
        last_name: registerValues.last_name.trim(),
        email: registerValues.email.trim(),
        contact_number: `+${phoneCountries.find((item) => item.code === phoneCountry).dialCode}${registerValues.contact_number.trim()}`,
        password: registerValues.password,
        password_confirmation: registerValues.password_confirmation,
      })
      onClose()
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
    <div
      className="auth-modal-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          onClose()
        }
      }}
    >
      <section
        aria-labelledby={titleId}
        aria-modal="true"
        className={`auth-modal ${isSignIn ? 'is-signin' : 'is-signup'}`}
        ref={modalRef}
        role="dialog"
      >
        <button className="auth-modal-close" type="button" aria-label="Close" onClick={onClose}>
          X
        </button>

        <div className="auth-modal-brand">
          <ResortBrandMark />
          <ResortBrandName as="strong" className="resort-brand-name" />
        </div>

        <div className="auth-modal-body">
          {isSignIn ? (
            <>
              <div className="auth-modal-heading">
                <h2 id={titleId}>{title ?? 'Sign in to your account'}</h2>
                {subtitle ? <p className="auth-modal-subtitle">{subtitle}</p> : null}
              </div>

              {formError ? <div className="form-alert" role="alert">{formError}</div> : null}

              <form className="login-form" noValidate onSubmit={handleSignInSubmit}>
                <label className="form-field" htmlFor="modal-email">
                  <span>Email</span>
                  <input
                    autoComplete="email"
                    disabled={isSubmitting}
                    id="modal-email"
                    name="email"
                    required
                    type="email"
                    value={signInValues.email}
                    aria-describedby={errors.email ? 'modal-email-error' : undefined}
                    aria-invalid={Boolean(errors.email)}
                    onChange={updateSignInField}
                  />
                  {errors.email ? <small id="modal-email-error" className="field-error">{errors.email}</small> : null}
                </label>

                <label className="form-field" htmlFor="modal-password">
                  <span>Password</span>
                  <div className="password-control">
                    <input
                      autoComplete="current-password"
                      disabled={isSubmitting}
                      id="modal-password"
                      name="password"
                      required
                      type={visiblePasswords.signIn ? 'text' : 'password'}
                      value={signInValues.password}
                      aria-describedby={errors.password ? 'modal-password-error' : undefined}
                      aria-invalid={Boolean(errors.password)}
                      onChange={updateSignInField}
                    />
                    <button
                      aria-label={visiblePasswords.signIn ? 'Hide password' : 'Show password'}
                      className="password-toggle"
                      disabled={isSubmitting}
                      type="button"
                      onClick={() => togglePasswordVisibility('signIn')}
                    >
                      {visiblePasswords.signIn ? 'Hide' : 'Show'}
                    </button>
                  </div>
                  {errors.password ? <small id="modal-password-error" className="field-error">{errors.password}</small> : null}
                </label>

                <div className="form-row">
                  <label className="checkbox-field">
                    <input
                      checked={signInValues.remember}
                      disabled={isSubmitting}
                      name="remember"
                      type="checkbox"
                      onChange={updateSignInField}
                    />
                    <span>Remember me</span>
                  </label>
                  <Link className="text-link inline-link" to="/forgot-password" onClick={onClose}>
                    Forgot password?
                  </Link>
                </div>

                <LoadingButton
                  className="solid-button full-width"
                  disabled={false}
                  isLoading={isSubmitting}
                  loadingLabel="Signing in..."
                  type="submit"
                >
                  Sign In
                </LoadingButton>

                <div className="auth-divider" aria-hidden="true"><span>or</span></div>
                <GoogleAuthButton label="Sign in with Google" />
              </form>

              <p className="auth-switch">
                New to DMD Resort?{' '}
                <button type="button" onClick={() => switchMode('signup')}>
                  Create an account
                </button>
              </p>
            </>
          ) : (
            <>
              <div className="auth-modal-heading">
                <h2 id={titleId}>{title ?? 'Create your account'}</h2>
                <p className="auth-modal-subtitle">{subtitle ?? 'Join us and start your resort experience.'}</p>
              </div>

              {formError ? <div className="form-alert" role="alert">{formError}</div> : null}

              <form className="login-form" noValidate onSubmit={handleRegisterSubmit}>
                <div className="form-grid">
                  <ModalField
                    autoComplete="given-name"
                    disabled={isSubmitting}
                    error={errors.first_name}
                    label="First name"
                    name="first_name"
                    value={registerValues.first_name}
                    onChange={updateRegisterField}
                    onBlur={() => validateRegisterField('first_name')}
                  />
                  <ModalField
                    autoComplete="family-name"
                    disabled={isSubmitting}
                    error={errors.last_name}
                    label="Last name"
                    name="last_name"
                    value={registerValues.last_name}
                    onChange={updateRegisterField}
                    onBlur={() => validateRegisterField('last_name')}
                  />
                </div>

                <ModalField
                  autoComplete="email"
                  disabled={isSubmitting}
                  error={errors.email}
                  label="Email"
                  name="email"
                  type="email"
                  value={registerValues.email}
                  onChange={updateRegisterField}
                  onBlur={() => validateRegisterField('email')}
                />
                <div className="form-grid auth-contact-grid">
                  <label className="form-field" htmlFor="modal-country-code">
                    <span>Country code</span>
                    <select
                      aria-label="Country code"
                      id="modal-country-code"
                      className="phone-country-select"
                      value={phoneCountry}
                      onChange={(event) => {
                        setPhoneCountry(event.target.value)
                        setErrors((current) => ({ ...current, contact_number: '' }))
                      }}
                    >
                      {phoneCountries.map((country) => (
                        <option key={country.code} value={country.code}>
                          {country.name} (+{country.dialCode})
                        </option>
                      ))}
                    </select>
                  </label>
                  <ModalField
                    autoComplete="tel"
                    disabled={isSubmitting}
                    error={errors.contact_number}
                    label="Contact number"
                    maxLength={11}
                    name="contact_number"
                    type="tel"
                    value={registerValues.contact_number}
                    onChange={updateRegisterField}
                    onBlur={() => validateRegisterField('contact_number')}
                  />
                </div>

                <div className="form-grid auth-password-grid">
                  <PasswordField
                    disabled={isSubmitting}
                    error={errors.password}
                    isVisible={visiblePasswords.password}
                    label="Password"
                    name="password"
                    value={registerValues.password}
                    onChange={updateRegisterField}
                    onBlur={() => validateRegisterField('password')}
                    onToggle={() => togglePasswordVisibility('password')}
                  />
                  <PasswordField
                    disabled={isSubmitting}
                    error={errors.password_confirmation}
                    isVisible={visiblePasswords.password_confirmation}
                    label="Confirm password"
                    name="password_confirmation"
                    value={registerValues.password_confirmation}
                    onChange={updateRegisterField}
                    onBlur={() => validateRegisterField('password_confirmation')}
                    onToggle={() => togglePasswordVisibility('password_confirmation')}
                  />
                </div>

                <LoadingButton
                  className="solid-button full-width"
                  disabled={false}
                  isLoading={isSubmitting}
                  loadingLabel="Creating account..."
                  type="submit"
                >
                  Create Account
                </LoadingButton>
              </form>

              <div className="auth-divider" aria-hidden="true"><span>or</span></div>
              <GoogleAuthButton label="Sign in to Google" />

              <p className="auth-switch">
                Already have an account?{' '}
                <button type="button" onClick={() => switchMode('signin')}>
                  Sign In
                </button>
              </p>
            </>
          )}
        </div>
      </section>
    </div>
  )
}

function GoogleAuthButton({ label = 'Continue with Google' }) {
  return (
    <button className="google-auth-button" type="button" onClick={redirectToGoogle}>
      <GoogleIcon />
      {label}
    </button>
  )
}

function GoogleIcon() {
  return (
    <svg className="google-auth-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path fill="#4285F4" d="M21.35 12.27c0-.79-.07-1.55-.23-2.27H12v4.3h5.24a4.48 4.48 0 0 1-1.94 2.94v2.45h3.14c1.84-1.69 2.91-4.18 2.91-7.42Z" />
      <path fill="#34A853" d="M12 21.7c2.63 0 4.84-.87 6.45-2.36l-3.14-2.45c-.87.58-1.98.92-3.31.92-2.54 0-4.69-1.72-5.46-4.03H3.3v2.53A9.74 9.74 0 0 0 12 21.7Z" />
      <path fill="#FBBC05" d="M6.54 13.78A5.86 5.86 0 0 1 6.23 12c0-.62.11-1.22.31-1.78V7.69H3.3A9.75 9.75 0 0 0 2.25 12c0 1.57.38 3.05 1.05 4.31l3.24-2.53Z" />
      <path fill="#EA4335" d="M12 6.19c1.43 0 2.71.49 3.72 1.45l2.79-2.79C16.84 3.27 14.63 2.3 12 2.3a9.74 9.74 0 0 0-8.7 5.39l3.24 2.53C7.31 7.91 9.46 6.19 12 6.19Z" />
    </svg>
  )
}

function ModalField({
  autoComplete,
  disabled,
  error,
  label,
  maxLength,
  prefix,
  name,
  onChange,
  type = 'text',
  value,
  onBlur,
}) {
  const id = `modal-${name}`

  return (
    <label className="form-field" htmlFor={id}>
      <span>{label}</span>
      <div className={prefix ? 'phone-input-group' : undefined}>
        {prefix}
      <input
        autoComplete={autoComplete}
        disabled={disabled}
        id={id}
        name={name}
        maxLength={maxLength}
        required
        type={type}
        value={value}
        aria-invalid={Boolean(error)}
        onChange={onChange}
        onBlur={onBlur}
      />
      </div>
    </label>
  )
}

function PasswordField({ disabled, error, isVisible, label, name, onChange, onToggle, onBlur, value }) {
  const id = `modal-${name}`

  return (
    <label className="form-field" htmlFor={id}>
      <span>{label}</span>
      <div className="password-control">
        <input
          autoComplete="new-password"
          disabled={disabled}
          id={id}
          name={name}
          required
          type={isVisible ? 'text' : 'password'}
          value={value}
          aria-invalid={Boolean(error)}
          onChange={onChange}
          onBlur={onBlur}
        />
        <button
          aria-label={isVisible ? 'Hide password' : 'Show password'}
          className="password-toggle"
          disabled={disabled}
          type="button"
          onClick={onToggle}
        >
          {isVisible ? 'Hide' : 'Show'}
        </button>
      </div>
    </label>
  )
}

export default AuthModal
