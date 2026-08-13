import {
  Activity, Bell, BookOpen, Building2, CalendarCheck, CalendarDays, ChevronLeft, ChevronRight,
  Droplets, FileBarChart, HeartHandshake, LayoutDashboard, LogOut,
  Menu, PackageCheck, Search, ShieldCheck, Smartphone, Truck, Users, X,
} from 'lucide-react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { useEffect, useMemo, useState } from 'react'
import { useAuth } from '../context/AuthContext'
import { NotificationProvider } from '../context/NotificationContext'
import NotificationBell from './NotificationBell'

const linksByRole = {
  donor: [
    ['Dashboard',    '/app/dashboard',     LayoutDashboard],
    ['My profile',   '/app/profile',       ShieldCheck],
    ['Appointments', '/app/appointments',  CalendarCheck],
    ['Campaigns',    '/app/campaigns',     CalendarDays],
    ['Notifications','/app/notifications', Bell],
    ['My activity',  '/app/activity',      Activity],
    ['User manual',  '/app/manual',        BookOpen],
  ],

  hospital: [
    ['Dashboard', '/app/dashboard', LayoutDashboard],
    ['Patient lookup', '/app/patient-lookup', Search],
    ['Blood requests', '/app/requests', Droplets],
    ['Dispatches', '/app/dispatches', Truck],
    ['Notifications', '/app/notifications', Bell],
    ['User manual', '/app/manual', BookOpen],
  ],
  staff: [
    ['Dashboard', '/app/dashboard', LayoutDashboard],
    ['Patient lookup', '/app/patient-lookup', Search],
    ['Donor pool', '/app/donors', Users],
    ['Campaigns', '/app/campaigns', CalendarDays],
    ['Blood requests', '/app/requests', Droplets],
    ['Inventory', '/app/inventory', PackageCheck],
    ['Dispatches', '/app/dispatches', Truck],
    ['Campaign requests', '/app/campaign-requests', ShieldCheck],
    ['Reports', '/app/reports', FileBarChart],
    ['USSD portal', '/app/ussd-portal', Smartphone],
    ['User manual', '/app/manual', BookOpen],
    ['Notifications', '/app/notifications', Bell],
  ],
  admin: [
    ['Dashboard', '/app/dashboard', LayoutDashboard],
    ['Patient lookup', '/app/patient-lookup', Search],
    ['Donor pool', '/app/donors', Users],
    ['Campaigns', '/app/campaigns', CalendarDays],
    ['Blood requests', '/app/requests', Droplets],
    ['Inventory', '/app/inventory', PackageCheck],
    ['Dispatches', '/app/dispatches', Truck],
    ['Campaign requests', '/app/campaign-requests', ShieldCheck],
    ['Reports', '/app/reports', FileBarChart],
    ['Institutions', '/app/institutions', Building2],
    ['User accounts', '/app/users', ShieldCheck],
    ['USSD portal', '/app/ussd-portal', Smartphone],
    ['User manual', '/app/manual', BookOpen],
    ['Notifications', '/app/notifications', Bell],
  ],
}

export default function AppLayout() {
  const { user, logout } = useAuth()
  const location = useLocation()
  const [mobileOpen, setMobileOpen] = useState(false)
  const [collapsed, setCollapsed] = useState(false)
  const links = useMemo(() => linksByRole[user.role] || [], [user.role])

  useEffect(() => setMobileOpen(false), [location.pathname])

  return (
    <NotificationProvider>
      <div className={`app-shell ${collapsed ? 'sidebar-collapsed' : ''}`}>
        <aside className={`sidebar ${mobileOpen ? 'sidebar-mobile-open' : ''}`}>
          <div className="brand-row">
            <img src="/donor-mark.svg" alt="" />
            <div className="brand-copy"><strong>DonorConnect</strong><span>Grow. Retain. Mobilise.</span></div>
            <button className="mobile-close icon-button" onClick={() => setMobileOpen(false)}><X size={20} /></button>
          </div>

          <nav className="sidebar-nav">
            {links.map(([label, to, Icon]) => (
              <NavLink key={to} to={to} className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}>
                <Icon size={20} /><span>{label}</span>
              </NavLink>
            ))}
          </nav>

          <div className="sidebar-footer">
            <div className="sidebar-user">
              <div className="avatar">{user.full_name?.split(' ').map((part) => part[0]).slice(0, 2).join('')}</div>
              <div><strong>{user.full_name}</strong><span>{user.role}</span></div>
            </div>
            <button className="nav-link logout-link" onClick={logout}><LogOut size={20} /><span>Sign out</span></button>
          </div>

          <button className="collapse-button" onClick={() => setCollapsed((value) => !value)} aria-label="Toggle sidebar">
            {collapsed ? <ChevronRight size={18} /> : <ChevronLeft size={18} />}
          </button>
        </aside>

        <main className="main-area">
          <header className="topbar">
            <button className="mobile-menu icon-button" onClick={() => setMobileOpen(true)}><Menu size={21} /></button>
            <div>
              <p className="eyebrow"><HeartHandshake size={15} /> National donor lifecycle platform</p>
              <h1>{links.find(([, to]) => location.pathname.startsWith(to))?.[0] || 'DonorConnect'}</h1>
            </div>
            <div className="topbar-right">
              <NotificationBell />
              <div className="topbar-role"><span className="pulse-dot" />{user.role} portal</div>
            </div>
          </header>
          <div className="page-container"><Outlet /></div>
        </main>
      </div>
    </NotificationProvider>
  )
}
