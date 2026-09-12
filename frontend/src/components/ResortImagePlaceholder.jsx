function ResortImagePlaceholder({ label, variant = 'room' }) {
  return (
    <div className={`resort-placeholder ${variant}`} role="img" aria-label={label}>
      <span>{label}</span>
    </div>
  )
}

export default ResortImagePlaceholder
