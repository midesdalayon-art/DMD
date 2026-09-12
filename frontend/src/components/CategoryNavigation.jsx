function CategoryNavigation({ activeCategory, categories, onChange }) {
  return (
    <div className="category-row" aria-label="Accommodation categories">
      {categories.map((category) => (
        <button
          key={category.id}
          className={`category-pill ${
            activeCategory === category.id ? 'is-active' : ''
          }`}
          type="button"
          onClick={() => onChange(category.id)}
        >
          {category.label}
        </button>
      ))}
    </div>
  )
}

export default CategoryNavigation
