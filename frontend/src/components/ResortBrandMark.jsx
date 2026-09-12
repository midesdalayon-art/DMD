import { useResortBranding } from '../hooks/useResortBranding'
import { resolveAssetUrl } from '../lib/api'

function ResortBrandMark({ className = '', size = 'default', showText = false, textClassName = '' }) {
  const { branding } = useResortBranding()
  const logoUrl = resolveAssetUrl(branding?.logo_url)
  const imageClassName = [
    'resort-brand-image',
    size === 'small' ? 'is-small' : '',
    size === 'large' ? 'is-large' : '',
  ]
    .filter(Boolean)
    .join(' ')

  const markClassName = [
    'resort-brand-mark',
    size === 'small' ? 'is-small' : '',
    size === 'large' ? 'is-large' : '',
    className,
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <span className={markClassName}>
      {logoUrl ? (
        <img className={imageClassName} src={logoUrl} alt="DMD Resort logo" />
      ) : (
        <span className="resort-brand-fallback" aria-hidden="true">
          D
        </span>
      )}
      {showText ? <span className={textClassName}>DMD Resort</span> : null}
    </span>
  )
}

export default ResortBrandMark
