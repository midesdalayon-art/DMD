import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  clearApiCaches,
  getCurrentUser,
  login as loginRequest,
  logout as logoutRequest,
  register as registerRequest,
  updateProfile as updateProfileRequest,
} from '../lib/api'
import { AuthContext } from './authContext'
import { logDevDiagnostic } from '../lib/devDiagnostics'
import { publishAuthChange, subscribeToAuthChanges } from '../lib/authSync'

let currentUserRequest = null

function getCurrentUserOnce(force = false) {
  if (force) {
    logDevDiagnostic('auth:user:start', { deduplicated: false, forced: true })
    return getCurrentUser()
  }

  if (!currentUserRequest) {
    logDevDiagnostic('auth:user:start', { deduplicated: false })
    currentUserRequest = getCurrentUser().finally(() => {
      logDevDiagnostic('auth:user:finish')
      currentUserRequest = null
    })
  } else {
    logDevDiagnostic('auth:user:deduplicated')
  }

  return currentUserRequest
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [isLoading, setIsLoading] = useState(true)
  const [isSigningOut, setIsSigningOut] = useState(false)
  const [authError, setAuthError] = useState(null)
  const authRequestVersion = useRef(0)
  const authSyncVersion = useRef(0)

  const refreshUser = useCallback(async ({ force = false } = {}) => {
    const requestVersion = ++authRequestVersion.current

    try {
      const currentUser = await getCurrentUserOnce(force)
      if (requestVersion !== authRequestVersion.current) {
        return null
      }

      logDevDiagnostic('auth:state', { state: 'authenticated', role: currentUser?.role ?? null })
      setUser(currentUser)
      return currentUser
    } catch (error) {
      if (requestVersion !== authRequestVersion.current) {
        return null
      }

      logDevDiagnostic('auth:user:error', {
        status: error?.response?.status ?? null,
        code: error?.code ?? null,
        aborted: error?.code === 'ERR_CANCELED' || error?.name === 'CanceledError' || error?.name === 'AbortError',
      })
      if (error?.response?.status !== 401) {
        setAuthError(error)
        throw error
      }

      setUser(null)
      setAuthError(null)
      setIsSigningOut(false)
      return null
    }
  }, [])

  useEffect(() => {
    let isMounted = true

    async function synchronizeAuth(message) {
      const syncVersion = ++authSyncVersion.current
      clearApiCaches()
      setAuthError(null)
      setUser(null)
      setIsSigningOut(message.type === 'logout')
      setIsLoading(true)

      try {
        await refreshUser({ force: true })
      } catch {
        // refreshUser records transient failures for ProtectedRoute.
      } finally {
        if (isMounted && syncVersion === authSyncVersion.current) {
          setIsSigningOut(false)
          setIsLoading(false)
        }
      }
    }

    async function loadUser() {
      logDevDiagnostic('auth:provider:load-start')
      try {
        await refreshUser()
      } catch {
        // Preserve the existing session state on transient failures. The
        // protected route will show a retry state instead of redirecting.
      } finally {
        logDevDiagnostic('auth:provider:load-finish')
        if (isMounted && authSyncVersion.current === 0) {
          setIsLoading(false)
        }
      }
    }

    const unsubscribe = subscribeToAuthChanges((message) => {
      void synchronizeAuth(message)
    })

    loadUser()

    return () => {
      isMounted = false
      unsubscribe()
      logDevDiagnostic('auth:provider:unmount')
    }
  }, [refreshUser])

  const signIn = useCallback(async (credentials) => {
    const authenticatedUser = await loginRequest(credentials)
    setUser(authenticatedUser)
    setAuthError(null)
    setIsSigningOut(false)
    clearApiCaches()
    publishAuthChange('login')

    return authenticatedUser
  }, [])

  const register = useCallback(async (accountDetails) => {
    const result = await registerRequest(accountDetails)
    setUser(null)
    setAuthError(null)
    setIsSigningOut(false)

    return result
  }, [])

  const signOut = useCallback(async () => {
    setIsSigningOut(true)

    try {
      await logoutRequest()
    } finally {
      setUser(null)
      setAuthError(null)
      clearApiCaches()
      publishAuthChange('logout')
    }
  }, [])

  const updateProfile = useCallback(async (profileDetails) => {
    const result = await updateProfileRequest(profileDetails)
    setUser(result.user)

    return result
  }, [])

  const value = useMemo(
    () => ({
      user,
      isAuthenticated: Boolean(user),
      authError,
      isLoading,
      isSigningOut,
      refreshUser,
      register,
      signIn,
      signOut,
      updateProfile,
    }),
    [authError, isLoading, isSigningOut, refreshUser, register, signIn, signOut, updateProfile, user],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
