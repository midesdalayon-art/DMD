import { forwardRef } from 'react'

const LoadingButton = forwardRef(function LoadingButton({
  children,
  isLoading = false,
  loadingLabel,
  className = '',
  type = 'button',
  disabled = false,
  ...props
}, ref) {
  const classes = [className].filter(Boolean).join(' ')

  return (
    <button
      ref={ref}
      {...props}
      className={classes || undefined}
      type={type}
      disabled={disabled || isLoading}
      aria-busy={isLoading || undefined}
    >
      {isLoading ? (
        <span className="loading-button-content">
          <span className="loading-button-dots" aria-hidden="true">
            <i />
            <i />
            <i />
          </span>
          <span>{loadingLabel ?? 'Loading...'}</span>
        </span>
      ) : (
        children
      )}
    </button>
  )
})

export default LoadingButton
