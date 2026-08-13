import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import AppLayout from './components/AppLayout'
import Chatbot from './components/Chatbot'
import ProtectedRoute from './components/ProtectedRoute'
import { useAuth } from './context/AuthContext'

const LandingPage = lazy(() => import('./pages/LandingPage'))
const LoginPage = lazy(() => import('./pages/LoginPage'))
const RegisterPage = lazy(() => import('./pages/RegisterPage'))
const DashboardPage = lazy(() => import('./pages/DashboardPage'))
const DonorsPage = lazy(() => import('./pages/DonorsPage'))
const DonorDetailPage = lazy(() => import('./pages/DonorDetailPage'))
const RequestsPage = lazy(() => import('./pages/RequestsPage'))
const RequestDetailPage = lazy(() => import('./pages/RequestDetailPage'))
const CampaignsPage = lazy(() => import('./pages/CampaignsPage'))
const ReportsPage = lazy(() => import('./pages/ReportsPage'))
const NotificationsPage = lazy(() => import('./pages/NotificationsPage'))
const ProfilePage = lazy(() => import('./pages/ProfilePage'))
const ActivityPage = lazy(() => import('./pages/ActivityPage'))
const InstitutionsPage = lazy(() => import('./pages/InstitutionsPage'))
const UsersPage = lazy(() => import('./pages/UsersPage'))
const CampaignRequestsPage = lazy(() => import('./pages/CampaignRequestsPage'))
const DispatchesPage = lazy(() => import('./pages/DispatchesPage'))
const InventoryPage = lazy(() => import('./pages/InventoryPage'))
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'))
const AppointmentsPage = lazy(() => import('./pages/AppointmentsPage'))
const PatientLookupPage = lazy(() => import('./pages/PatientLookupPage'))
const UserManualPage  = lazy(() => import('./pages/UserManualPage'))
const UssdSimulatorPage = lazy(() => import('./pages/UssdSimulatorPage'))

const Guard = ({ children, roles }) => <ProtectedRoute roles={roles}>{children}</ProtectedRoute>
const Loader = () => <div className="screen-loader"><div className="blood-loader" /><p>Loading DonorConnect…</p></div>

export default function App() {
  const { user } = useAuth()
  const showDonorAssistant = user?.role === 'donor'

  return (
    <Suspense fallback={<Loader />}>
      <Routes>
        <Route path="/" element={<LandingPage />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />

        <Route path="/app" element={<Guard><AppLayout /></Guard>}>
          <Route index element={<Navigate to="dashboard" replace />} />
          <Route path="dashboard" element={<DashboardPage />} />
          <Route path="profile" element={<Guard roles={['donor']}><ProfilePage /></Guard>} />
          <Route path="activity" element={<Guard roles={['donor']}><ActivityPage /></Guard>} />
          <Route path="appointments" element={<Guard roles={['donor']}><AppointmentsPage /></Guard>} />
          <Route path="notifications" element={<NotificationsPage />} />
          <Route path="donors" element={<Guard roles={['staff', 'admin']}><DonorsPage /></Guard>} />
          <Route path="donors/:id" element={<Guard roles={['staff', 'admin']}><DonorDetailPage /></Guard>} />
          <Route path="requests" element={<Guard roles={['hospital', 'staff', 'admin']}><RequestsPage /></Guard>} />
          <Route path="requests/:id" element={<Guard roles={['hospital', 'staff', 'admin']}><RequestDetailPage /></Guard>} />
          <Route path="patient-lookup" element={<Guard roles={['hospital', 'staff', 'admin']}><PatientLookupPage /></Guard>} />
          <Route path="campaigns" element={<CampaignsPage />} />
          <Route path="reports" element={<Guard roles={['staff', 'admin']}><ReportsPage /></Guard>} />

          <Route path="inventory" element={<Guard roles={['staff', 'admin']}><InventoryPage /></Guard>} />
          <Route path="dispatches" element={<Guard roles={['hospital', 'staff', 'admin']}><DispatchesPage /></Guard>} />
          <Route path="campaign-requests" element={<Guard roles={['staff', 'admin']}><CampaignRequestsPage /></Guard>} />
          <Route path="institutions" element={<Guard roles={['admin']}><InstitutionsPage /></Guard>} />
          <Route path="users" element={<Guard roles={['admin']}><UsersPage /></Guard>} />
          <Route path="ussd-portal" element={<Guard roles={['staff', 'admin']}><UssdSimulatorPage /></Guard>} />
          <Route path="manual" element={<UserManualPage />} />
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Routes>
      {showDonorAssistant ? <Chatbot /> : null}
    </Suspense>
  )
}
