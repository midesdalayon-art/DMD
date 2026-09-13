import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

const apiBaseURL = import.meta.env.VITE_API_BASE_URL ?? '/api'
const apiRootURL = apiBaseURL.startsWith('/')
  ? window.location.origin
  : apiBaseURL.replace(/\/api\/?$/, '')
const isDevelopment = import.meta.env.DEV
const configuredReverbHost = import.meta.env.VITE_REVERB_HOST || ''
const isLocalReverbHost = /^(localhost|127\.0\.0\.1|::1)(?::\d+)?$/i.test(configuredReverbHost)
const reverbHost = configuredReverbHost && (isDevelopment || !isLocalReverbHost)
  ? configuredReverbHost
  : (isDevelopment ? '127.0.0.1' : '')
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY || (isDevelopment ? 'dmd-resort-key' : '')

export const echo = reverbHost && reverbKey ? new Echo({
  broadcaster: 'reverb',
  key: reverbKey,
  wsHost: reverbHost,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
  forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: `${apiRootURL}/broadcasting/auth`,
  auth: { withCredentials: true },
}) : null
