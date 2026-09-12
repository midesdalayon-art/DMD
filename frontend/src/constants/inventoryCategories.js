export const INVENTORY_CATEGORY_OPTIONS = [
  { value: 'Furniture', label: 'Furniture' },
  { value: 'Appliances', label: 'Appliances' },
  { value: 'Electronics', label: 'Electronics' },
  { value: 'Kitchen Equipment', label: 'Kitchen Equipment' },
  { value: 'Bathroom Equipment', label: 'Bathroom Equipment' },
  { value: 'Bedding & Linens', label: 'Bedding & Linens' },
  { value: 'Cleaning Equipment', label: 'Cleaning Equipment' },
  { value: 'Pool Equipment', label: 'Pool Equipment' },
  { value: 'Outdoor Furniture', label: 'Outdoor Furniture' },
  { value: 'Office Equipment', label: 'Office Equipment' },
  { value: 'Tools & Maintenance', label: 'Tools & Maintenance' },
  { value: 'Safety Equipment', label: 'Safety Equipment' },
]

export const INVENTORY_CATEGORY_OTHER_VALUE = 'Other'

export function isPredefinedInventoryCategory(category) {
  return INVENTORY_CATEGORY_OPTIONS.some((option) => option.value === category)
}

export function resolveInventoryCategoryLabel(category) {
  if (!category) {
    return ''
  }

  const matchedCategory = INVENTORY_CATEGORY_OPTIONS.find((option) => option.value === category)
  return matchedCategory?.label ?? category
}

export function splitInventoryCategories(categories) {
  const uniqueCategories = [...new Set((categories ?? []).filter(Boolean))].sort((left, right) => left.localeCompare(right))
  const predefinedValues = new Set(INVENTORY_CATEGORY_OPTIONS.map((option) => option.value))

  return {
    predefined: INVENTORY_CATEGORY_OPTIONS,
    custom: uniqueCategories.filter((category) => !predefinedValues.has(category)),
  }
}
