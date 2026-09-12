import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import LoadingButton from '../components/LoadingButton'
import ResortBrandMark from '../components/ResortBrandMark'
import ResortBrandName from '../components/ResortBrandName'
import { ToastMessage } from '../context/ToastContext'
import { cancelPendingRegistration, resendEmailCode, verifyEmailCode } from '../lib/api'
import Home from './Home'

function extractError(error, fallback) {
  const messages = error?.response?.data?.errors
  const firstMessage = messages && Object.values(messages).flat()[0]

  return firstMessage || error?.response?.data?.message || fallback
}

function VerifyEmail() {
  const navigate = useNavigate()
  const location = useLocation()
  const [searchParams] = useSearchParams()
  const [email, setEmail] = useState(searchParams.get('email') ?? '')
  const [code, setCode] = useState('')
  const [formError, setFormError] = useState('')
  const [successMessage, setSuccessMessage] = useState(location.state?.verificationMessage ?? '')
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [isResending, setIsResending] = useState(false)
  const [isCancelling, setIsCancelling] = useState(false)
  const [cooldown, setCooldown] = useState(0)
  const [showCancelConfirmation, setShowCancelConfirmation] = useState(false)
  const cancellationTokenKey = `dmd-registration-cancel-token:${email.trim().toLowerCase()}`

  useEffect(() => {
    if (cooldown <= 0) {
      return undefined
    }

    const timer = window.setInterval(() => {
      setCooldown((current) => Math.max(0, current - 1))
    }, 1000)

    return () => window.clearInterval(timer)
  }, [cooldown])

  useEffect(() => {
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.body.style.overflow = previousOverflow
    }
  }, [])

  function handleCodeChange(event) {
    setCode(event.target.value.replace(/\D/g, '').slice(0, 6))
    setFormError('')
    setSuccessMessage('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFormError('')
    setSuccessMessage('')

    if (!email.trim() || code.length !== 6) {
      setFormError('Enter the email address and the 6-digit verification code.')
      return
    }

    setIsSubmitting(true)

    try {
      await verifyEmailCode({ email: email.trim(), code })
      window.sessionStorage.removeItem(cancellationTokenKey)
      navigate('/', {
        replace: true,
        state: { authMessage: 'Email verified successfully. You can now sign in.' },
      })
    } catch (error) {
      setFormError(extractError(error, 'We could not verify that code. Please try again.'))
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleResend() {
    setFormError('')
    setSuccessMessage('')

    if (!email.trim()) {
      setFormError('Enter the email address used to register.')
      return
    }

    setIsResending(true)

    try {
      const result = await resendEmailCode(email.trim())
      if (result.registration_cancel_token) {
        window.sessionStorage.setItem(cancellationTokenKey, result.registration_cancel_token)
      }
      setSuccessMessage(result.message ?? 'A new verification code has been sent.')
      setCooldown(60)
    } catch (error) {
      const retryAfter = Number(error?.response?.data?.retry_after ?? 0)
      if (retryAfter > 0) {
        setCooldown(retryAfter)
      }
      setFormError(extractError(error, 'We could not resend the code. Please try again.'))
    } finally {
      setIsResending(false)
    }
  }

  async function handleCancelRegistration() {
    setFormError('')
    setIsCancelling(true)

    try {
      const token = window.sessionStorage.getItem(cancellationTokenKey)
      await cancelPendingRegistration(email.trim(), token)
      window.sessionStorage.removeItem(cancellationTokenKey)
      navigate('/', { replace: true })
    } catch (error) {
      setFormError(extractError(error, 'We could not cancel this registration. Please try again.'))
      setShowCancelConfirmation(false)
    } finally {
      setIsCancelling(false)
    }
  }

  return (
    <>
      <Home />
      <div className="auth-modal-backdrop">
        <section className="auth-modal verify-email-modal" role="dialog" aria-modal="true" aria-labelledby="verify-email-title">
          <button
            className="auth-modal-close"
            type="button"
            aria-label="Back to home"
            onClick={() => setShowCancelConfirmation(true)}
            disabled={isSubmitting || isResending || isCancelling}
          >
            X
          </button>

          <div className="auth-modal-brand">
            <ResortBrandMark />
            <ResortBrandName as="strong" className="resort-brand-name" />
          </div>

          <div className="auth-modal-body">
            <div className="auth-modal-heading">
              <h2 id="verify-email-title">Verify your email</h2>
              <p className="auth-modal-subtitle">We sent a 6-digit code to your email. It expires in 10 minutes.</p>
            </div>

            {formError ? <div className="form-alert" role="alert">{formError}</div> : null}
            <ToastMessage message={successMessage} />

            <form className="login-form" noValidate onSubmit={handleSubmit}>
              <label className="form-field" htmlFor="verification-email">
                <span>Email Address</span>
                <input
                  id="verification-email"
                  type="email"
                  autoComplete="email"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  disabled={isSubmitting || isResending}
                  required
                />
              </label>

              <label className="form-field" htmlFor="verification-code">
                <span>6-digit verification code</span>
                <input
                  className="verification-code-input"
                  id="verification-code"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  pattern="[0-9]{6}"
                  maxLength={6}
                  value={code}
                  onChange={handleCodeChange}
                  disabled={isSubmitting || isResending}
                  required
                />
              </label>

              <LoadingButton
                className="solid-button full-width"
                type="submit"
                isLoading={isSubmitting}
                loadingLabel="Verifying..."
              >
                Verify Email
              </LoadingButton>

              <button
                className="outline-button full-width"
                type="button"
                onClick={handleResend}
                disabled={isSubmitting || isResending || cooldown > 0}
              >
                {isResending ? 'Sending...' : cooldown > 0 ? `Resend Code (${cooldown}s)` : 'Resend Code'}
              </button>
            </form>

            <p className="auth-switch">Already verified? <Link to="/" state={{ openAuthModal: 'signin' }}>Sign In</Link></p>
          </div>
        </section>
        {showCancelConfirmation ? (
          <div className="verify-cancel-backdrop" role="presentation">
            <section className="auth-modal verify-cancel-modal" role="dialog" aria-modal="true" aria-labelledby="cancel-registration-title">
              <div className="auth-modal-heading">
                <h2 id="cancel-registration-title">Cancel account creation?</h2>
                <p className="auth-modal-subtitle">
                  Your account has not been fully verified yet. If you leave now, your registration will be cancelled and your information will be discarded.
                </p>
              </div>

              <div className="verify-cancel-actions">
                <button
                  className="outline-button full-width"
                  type="button"
                  onClick={() => setShowCancelConfirmation(false)}
                  disabled={isCancelling}
                >
                  Continue Verification
                </button>
                <button
                  className="solid-button full-width"
                  type="button"
                  onClick={handleCancelRegistration}
                  disabled={isCancelling || !email.trim()}
                >
                  {isCancelling ? 'Cancelling...' : 'Cancel Registration'}
                </button>
              </div>
            </section>
          </div>
        ) : null}
      </div>
    </>
  )
}

export default VerifyEmail
