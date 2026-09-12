import { CircleX, X } from 'lucide-react'

function PaymentCancelledModal({
  onClose,
  onContinue,
  continueLabel = 'Continue Booking',
  description = 'Your payment was not completed and you have not been charged.',
}) {
  return (
    <div className="payment-cancelled-backdrop" role="presentation">
      <section className="payment-cancelled-modal" role="dialog" aria-modal="true" aria-labelledby="payment-cancelled-title" aria-describedby="payment-cancelled-description">
        <button className="payment-cancelled-close" type="button" onClick={onClose} aria-label="Close payment cancelled notice">
          <X size={18} aria-hidden="true" />
        </button>
        <div className="payment-cancelled-icon" aria-hidden="true">
          <CircleX size={22} />
        </div>
        <p className="eyebrow">Payment cancelled</p>
        <h2 id="payment-cancelled-title">Payment was not completed</h2>
        <p id="payment-cancelled-description" className="payment-cancelled-copy">
          {description}
        </p>
        <button className="solid-button full-width" type="button" onClick={onContinue}>
          {continueLabel}
        </button>
      </section>
    </div>
  )
}

export default PaymentCancelledModal
