function InlineLoader({ message = 'Loading...', className = '' }) {
  const classes = ['inline-loader', className].filter(Boolean).join(' ')

  return <div className={classes} role="status" aria-live="polite" aria-busy="true" aria-label={message} />
}

export default InlineLoader
