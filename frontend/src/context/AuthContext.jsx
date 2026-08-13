import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { api, setCsrfToken } from '../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)
  const [statusRevealed, setStatusRevealed] = useState(false)

  const refresh = useCallback(async () => {
    try {
      const data = await api('/auth/me')
      setUser(data.user)
      setCsrfToken(data.csrf_token)
      return data.user
    } catch {
      setUser(null)
      try {
        const data = await api('/auth/csrf')
        setCsrfToken(data.csrf_token)
      } catch {
        setCsrfToken(null)
      }
      return null
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    refresh()
  }, [refresh])

  const login = async (credentials) => {
    setStatusRevealed(false)
    const data = await api('/auth/login', { method: 'POST', body: credentials })
    setUser(data.user)
    setCsrfToken(data.csrf_token)
    return data.user
  }

  const requestOtp = async (details) => {
    return api('/auth/otp/request', { method: 'POST', body: details })
  }

  const verifyOtp = async (details) => {
    setStatusRevealed(false)
    const data = await api('/auth/otp/verify', { method: 'POST', body: details })
    setUser(data.user)
    setCsrfToken(data.csrf_token)
    return data.user
  }

  const register = async (details) => {
    setStatusRevealed(false)
    const data = await api('/auth/register', { method: 'POST', body: details })
    setUser(data.user)
    setCsrfToken(data.csrf_token)
    return data.user
  }

  const forgotRequest = async (details) => {
    return api('/auth/forgot-password/request', { method: 'POST', body: details })
  }

  const forgotSend = async (details) => {
    return api('/auth/forgot-password/send', { method: 'POST', body: details })
  }

  const forgotReset = async (details) => {
    return api('/auth/forgot-password/reset', { method: 'POST', body: details })
  }

  const logout = async () => {
    setStatusRevealed(false)
    try {
      await api('/auth/logout', { method: 'POST' })
    } catch (e) {
      console.error('Logout API call failed:', e)
    } finally {
      setUser(null)
      setCsrfToken(null)
    }
  }

  const value = useMemo(() => ({
    user, loading, login, requestOtp, verifyOtp, register, logout, refresh,
    forgotRequest, forgotSend, forgotReset, statusRevealed, setStatusRevealed
  }), [user, loading, refresh, statusRevealed])
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside AuthProvider')
  return context
}
