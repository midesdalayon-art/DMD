function SectionHeading({ eyebrow, title, children }) {
  return (
    <div className="section-heading">
      {eyebrow ? <p>{eyebrow}</p> : null}
      <h2>{title}</h2>
      {children ? <span>{children}</span> : null}
    </div>
  )
}

export default SectionHeading
