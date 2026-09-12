import { useEffect, useMemo, useState } from 'react'
import LoadingButton from '../components/LoadingButton'
import LoadingState from '../components/LoadingState'
import {
  createAdminChatbotCategory,
  createAdminChatbotRule,
  deleteAdminChatbotRule,
  getAdminChatbotCategories,
  getAdminChatbotRules,
  updateAdminChatbotRule,
} from '../lib/api'

const blankRule = {
  category_id: '',
  question: '',
  answer: '',
  keywords: 'booking, reservation',
  priority: 50,
  is_active: true,
}

function AdminChatbot() {
  const [categories, setCategories] = useState([])
  const [rules, setRules] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [saving, setSaving] = useState(false)
  const [ruleForm, setRuleForm] = useState(blankRule)
  const [categoryForm, setCategoryForm] = useState({ name: '', slug: '' })

  useEffect(() => {
    let mounted = true
    async function load() {
      setLoading(true)
      setLoadError('')
      try {
        const [categoryResult, ruleResult] = await Promise.allSettled([
          getAdminChatbotCategories(),
          getAdminChatbotRules(),
        ])
        const loadedCategories = categoryResult.status === 'fulfilled' ? categoryResult.value : []
        const loadedRules = ruleResult.status === 'fulfilled' ? ruleResult.value : []
        if (mounted) {
          if (categoryResult.status === 'rejected' || ruleResult.status === 'rejected') {
            setLoadError('Some chatbot data could not be loaded. Available data is shown below.')
          }
          setCategories(loadedCategories)
          setRules(loadedRules)
          if (!ruleForm.category_id && loadedCategories[0]) {
            setRuleForm((current) => ({ ...current, category_id: loadedCategories[0].id }))
          }
        }
      } finally {
        if (mounted) setLoading(false)
      }
    }
    load()
    return () => {
      mounted = false
    }
  }, [])

  const categoryOptions = useMemo(() => categories, [categories])

  async function handleCreateCategory(event) {
    event.preventDefault()
    setSaving(true)
    try {
      const category = await createAdminChatbotCategory({
        ...categoryForm,
        sort_order: categories.length + 1,
        is_active: true,
      })
      setCategories((current) => [...current, category])
      setCategoryForm({ name: '', slug: '' })
    } finally {
      setSaving(false)
    }
  }

  async function handleCreateRule(event) {
    event.preventDefault()
    setSaving(true)
    try {
      const rule = await createAdminChatbotRule({
        ...ruleForm,
        keywords: ruleForm.keywords.split(',').map((item) => item.trim()).filter(Boolean),
      })
      setRules((current) => [rule, ...current])
      setRuleForm(blankRule)
    } finally {
      setSaving(false)
    }
  }

  async function toggleRule(rule) {
    setSaving(true)
    try {
      const updated = await updateAdminChatbotRule(rule.id, {
        category_id: rule.category_id,
        question: rule.question,
        answer: rule.answer,
        keywords: rule.keywords ?? [],
        priority: rule.priority,
        is_active: !rule.is_active,
      })
      setRules((current) => current.map((item) => (item.id === updated.id ? updated : item)))
    } finally {
      setSaving(false)
    }
  }

  async function removeRule(id) {
    setSaving(true)
    try {
      await deleteAdminChatbotRule(id)
      setRules((current) => current.filter((rule) => rule.id !== id))
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <section className="admin-page-header">
        <div>
          <p className="eyebrow">Chatbot</p>
          <h2>Rule-based guest assistance</h2>
        </div>
      </section>

      {loading ? <LoadingState message="Loading chatbot rules..." /> : null}
      {loadError ? <div className="admin-inline-alert" role="status">{loadError}</div> : null}

      {!loading ? (
        <div className="chatbot-admin-grid">
          <article className="dashboard-card">
            <div className="dashboard-card-header">
              <h2>Categories</h2>
            </div>
            <form className="chatbot-form" onSubmit={handleCreateCategory}>
              <label>
                <span>Name</span>
                <input value={categoryForm.name} onChange={(event) => setCategoryForm((current) => ({ ...current, name: event.target.value }))} />
              </label>
              <label>
                <span>Slug</span>
                <input value={categoryForm.slug} onChange={(event) => setCategoryForm((current) => ({ ...current, slug: event.target.value }))} />
              </label>
              <LoadingButton className="solid-button" type="submit" isLoading={saving} loadingLabel="Saving...">
                Add Category
              </LoadingButton>
            </form>
            <div className="chatbot-list">
              {categoryOptions.map((category) => (
                <div key={category.id} className="chatbot-list-item">
                  <strong>{category.name}</strong>
                  <small>{category.slug}</small>
                </div>
              ))}
            </div>
          </article>

          <article className="dashboard-card">
            <div className="dashboard-card-header">
              <h2>Rules</h2>
            </div>
            <form className="chatbot-form" onSubmit={handleCreateRule}>
              <label>
                <span>Category</span>
                <select value={ruleForm.category_id} onChange={(event) => setRuleForm((current) => ({ ...current, category_id: event.target.value }))}>
                  {categories.map((category) => (
                    <option key={category.id} value={category.id}>{category.name}</option>
                  ))}
                </select>
              </label>
              <label>
                <span>Question</span>
                <input value={ruleForm.question} onChange={(event) => setRuleForm((current) => ({ ...current, question: event.target.value }))} />
              </label>
              <label>
                <span>Keywords</span>
                <input value={ruleForm.keywords} onChange={(event) => setRuleForm((current) => ({ ...current, keywords: event.target.value }))} />
              </label>
              <label>
                <span>Answer</span>
                <textarea rows="3" value={ruleForm.answer} onChange={(event) => setRuleForm((current) => ({ ...current, answer: event.target.value }))} />
              </label>
              <LoadingButton className="solid-button" type="submit" isLoading={saving} loadingLabel="Saving...">
                Add Rule
              </LoadingButton>
            </form>
            <div className="chatbot-rule-list">
              {rules.map((rule) => (
                <article key={rule.id} className="chatbot-rule-card">
                  <div>
                    <strong>{rule.question}</strong>
                    <small>{rule.category?.name}</small>
                    <p>{rule.answer}</p>
                  </div>
                  <div className="chatbot-rule-actions">
                    <button type="button" className="outline-button" onClick={() => toggleRule(rule)} disabled={saving}>
                      {rule.is_active ? 'Disable' : 'Enable'}
                    </button>
                    <button type="button" className="outline-button" onClick={() => removeRule(rule.id)} disabled={saving}>
                      Delete
                    </button>
                  </div>
                </article>
              ))}
            </div>
          </article>
        </div>
      ) : null}
    </>
  )
}

export default AdminChatbot
