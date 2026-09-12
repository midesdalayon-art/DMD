import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

const apiBaseURL = import.meta.env.VITE_API_BASE_URL ?? '/api'
const apiRootURL = apiBaseURL.startsWith('/')
  ? window.location.origin
  : apiBaseURL.replace(/\/api\/?$/, '')

export const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY ?? 'dmd-resort-key',
  wsHost: import.meta.env.VITE_REVERB_HOST ?? '127.0.0.1',
  wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
  forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
  enabledTransports: ['ws', 'wss'],
  authEndpoint: `${apiRootURL}/broadcasting/auth`,
  auth: { withCredentials: true },
})
