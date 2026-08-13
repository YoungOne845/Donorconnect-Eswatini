import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import { api } from '../api/client'
import { useAuth } from './AuthContext'

const NotificationContext = createContext(null)

const POLL_INTERVAL_MS = 45_000 // 45 seconds

export function NotificationProvider({ children }) {
  const { user } = useAuth()
  const [unreadCount, setUnreadCount] = useState(0)
  const [recentItems, setRecentItems] = useState([])
  const [dropdownOpen, setDropdownOpen] = useState(false)
  const intervalRef = useRef(null)

  const fetchCount = useCallback(async () => {
    if (!user) return
    try {
      const data = await api('/notifications/count')
      setUnreadCount(data.count ?? 0)
    } catch {
      // silently fail — user may have lost connection
    }
  }, [user])

  const fetchRecent = useCallback(async () => {
    if (!user) return
    try {
      const items = await api('/notifications/recent')
      setRecentItems(Array.isArray(items) ? items : [])
    } catch {
      setRecentItems([])
    }
  }, [user])

  const openDropdown = useCallback(async () => {
    setDropdownOpen(true)
    await fetchRecent()
  }, [fetchRecent])

  const closeDropdown = useCallback(() => {
    setDropdownOpen(false)
  }, [])

  const markRead = useCallback(async (id) => {
    try {
      await api(`/notifications/${id}/read`, { method: 'PATCH' })
      setRecentItems((prev) => prev.map((n) => n.id === id ? { ...n, is_read: 1 } : n))
      setUnreadCount((c) => Math.max(0, c - 1))
    } catch {
      // ignore
    }
  }, [])

  const markAllRead = useCallback(async () => {
    try {
      await api('/notifications/read-all', { method: 'PATCH' })
      setRecentItems((prev) => prev.map((n) => ({ ...n, is_read: 1 })))
      setUnreadCount(0)
    } catch {
      // ignore
    }
  }, [])

  // Initial fetch + start polling
  useEffect(() => {
    if (!user) return
    fetchCount()
    intervalRef.current = setInterval(fetchCount, POLL_INTERVAL_MS)
    return () => clearInterval(intervalRef.current)
  }, [user, fetchCount])

  return (
    <NotificationContext.Provider
      value={{ unreadCount, recentItems, dropdownOpen, openDropdown, closeDropdown, markRead, markAllRead, refresh: fetchCount }}
    >
      {children}
    </NotificationContext.Provider>
  )
}

export function useNotifications() {
  const ctx = useContext(NotificationContext)
  if (!ctx) throw new Error('useNotifications must be used inside NotificationProvider')
  return ctx
}
