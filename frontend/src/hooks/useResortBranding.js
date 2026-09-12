import { useEffect, useSyncExternalStore } from 'react'
import { getPublicSettings } from '../lib/api'

const brandingEventName = 'dmdresort:branding-changed'

let cachedBranding = {
  logo_url: null,
  favicon_url: null,
  homepage_hero_image_url: null,
}

let cachedPublicSettings = {
  general: {
    resort_name: 'DMD Resort',
    resort_name_font: 'default',
    short_description: '',
  },
  contact: {},
  branding: cachedBranding,
}

let loadPromise = null
let hasLoaded = false
let loadError = null

const listeners = new Set()

function emitChange() {
  listeners.forEach((listener) => listener())
  window.dispatchEvent(new CustomEvent(brandingEventName, { detail: cachedBranding }))
}

function subscribe(listener) {
  listeners.add(listener)

  function handleCustomEvent() {
    listener()
  }

  window.addEventListener(brandingEventName, handleCustomEvent)

  return () => {
    listeners.delete(listener)
    window.removeEventListener(brandingEventName, handleCustomEvent)
  }
}

function getSnapshot() {
  return cachedPublicSettings
}

async function loadBranding(force = false) {
  if (hasLoaded && !force) {
    return cachedPublicSettings
  }

  if (!loadPromise) {
    loadPromise = getPublicSettings()
      .then((settings) => {
        cachedPublicSettings = {
          ...cachedPublicSettings,
          ...settings,
          general: {
            ...cachedPublicSettings.general,
            ...(settings?.general ?? {}),
          },
          contact: {
            ...cachedPublicSettings.contact,
            ...(settings?.contact ?? {}),
          },
          branding: {
            ...cachedBranding,
            ...(settings?.branding ?? {}),
          },
        }
        cachedBranding = cachedPublicSettings.branding
        hasLoaded = true
        loadError = null
        emitChange()
        return cachedPublicSettings
      })
      .catch((error) => {
        loadError = error
        cachedPublicSettings = { ...cachedPublicSettings }
        emitChange()
        return cachedPublicSettings
      })
      .finally(() => {
        loadPromise = null
      })
  }

  return loadPromise
}

export function setResortBranding(nextBranding, nextGeneral = null) {
  cachedBranding = {
    ...cachedBranding,
    ...(nextBranding ?? {}),
  }
  cachedPublicSettings = {
    ...cachedPublicSettings,
    ...(nextGeneral ? { general: { ...cachedPublicSettings.general, ...nextGeneral } } : {}),
    branding: cachedBranding,
  }
  hasLoaded = true
  loadError = null

  emitChange()
}

export function useResortBranding() {
  const branding = useSyncExternalStore(subscribe, getSnapshot, getSnapshot)

  useEffect(() => {
    void loadBranding()
  }, [])

  return {
    branding: branding.branding,
    publicSettings: branding,
    isLoading: !hasLoaded,
    loadError,
    resortName: branding.general?.resort_name ?? 'DMD Resort',
    resortNameFont: branding.general?.resort_name_font ?? 'default',
    shortDescription: branding.general?.short_description ?? '',
    refreshBranding: () => loadBranding(true),
  }
}

export function getResortBrandingSnapshot() {
  return cachedPublicSettings
}
