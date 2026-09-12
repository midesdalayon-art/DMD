function GuestStepper({
  label,
  value,
  min = 1,
  max,
  onChange,
  disabled = false,
  ariaDescribedBy,
  decreaseLabel,
  increaseLabel,
  hideLabel = false,
}) {
  const numericValue = Number(value || 0)
  const canDecrease = !disabled && numericValue > min
  const canIncrease = !disabled && (typeof max !== 'number' || numericValue < max)

  function sanitize(rawValue) {
    const text = String(rawValue).trim()

    if (text === '') {
      return ''
    }

    const digitsOnly = text.replace(/\D/g, '')

    if (!digitsOnly) {
      return ''
    }

    let nextValue = String(Number(digitsOnly))

    if (nextValue === 'NaN') {
      return ''
    }

    if (typeof max === 'number') {
      nextValue = String(Math.min(max, Number(nextValue)))
    }

    if (Number(nextValue) < min) {
      nextValue = String(min)
    }

    return nextValue
  }

  function handleInputChange(event) {
    onChange(sanitize(event.target.value))
  }

  function handleBlur() {
    if (value === '' || value === null || value === undefined) {
      onChange(String(min))
      return
    }

    onChange(sanitize(value) || String(min))
  }

  function decrement() {
    onChange(String(Math.max(min, numericValue - 1)))
  }

  function increment() {
    const nextValue = numericValue + 1
    onChange(String(typeof max === 'number' ? Math.min(max, nextValue) : nextValue))
  }

  const stepper = (
    <div className="guest-stepper" role="group" aria-label={label}>
      <button
        className="guest-stepper-button"
        type="button"
        onClick={decrement}
        disabled={!canDecrease}
        aria-label={decreaseLabel ?? `Decrease ${label.toLowerCase()}`}
      >
        -
      </button>
      <input
        className="guest-stepper-input"
        type="text"
        inputMode="numeric"
        pattern="[0-9]*"
        value={value}
        onChange={handleInputChange}
        onBlur={handleBlur}
        disabled={disabled}
        aria-describedby={ariaDescribedBy}
        aria-label={label}
      />
      <button
        className="guest-stepper-button"
        type="button"
        onClick={increment}
        disabled={!canIncrease}
        aria-label={increaseLabel ?? `Increase ${label.toLowerCase()}`}
      >
        +
      </button>
    </div>
  )

  if (hideLabel) {
    return stepper
  }

  return (
    <label className="form-field guest-stepper-field">
      <span>{label}</span>
      {stepper}
    </label>
  )
}

export default GuestStepper
