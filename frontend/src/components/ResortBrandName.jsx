import { useResortBranding } from '../hooks/useResortBranding'

const resortNameFontMap = {
  default: null,
  'Times New Roman': '"Times New Roman", Times, serif',
  Georgia: 'Georgia, "Times New Roman", Times, serif',
  Arial: 'Arial, Helvetica, sans-serif',
  Verdana: 'Verdana, Geneva, sans-serif',
  'Trebuchet MS': '"Trebuchet MS", "Lucida Sans Unicode", "Lucida Grande", sans-serif',
  'Courier New': '"Courier New", Courier, monospace',
  Garamond: 'Garamond, "Times New Roman", serif',
}

export function getResortNameFontFamily(fontKey) {
  return resortNameFontMap[fontKey] ?? null
}

function ResortBrandName({ as: Tag = 'span', className = '', style = {}, children }) {
  const { resortName, resortNameFont } = useResortBranding()
  const fontFamily = getResortNameFontFamily(resortNameFont)

  return (
    <Tag
      className={className}
      style={{
        ...(fontFamily ? { fontFamily } : {}),
        ...style,
      }}
    >
      {children ?? resortName}
    </Tag>
  )
}

export default ResortBrandName
