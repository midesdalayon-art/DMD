export const isDevDiagnosticsEnabled = import.meta.env.DEV

export function logDevDiagnostic(event, details = {}) {
  if (!isDevDiagnosticsEnabled) return

  console.debug(`[DMD diagnostics] ${event}`, {
    route: typeof window === 'undefined' ? 'unknown' : window.location.pathname,
    ...details,
  })
}
