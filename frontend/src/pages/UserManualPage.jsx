import { useState } from 'react'
import { useAuth } from '../context/AuthContext'
import {
  BookOpen, Heart, Activity, ShieldAlert, Users, Hospital,
  CalendarCheck, Award, Droplets, Bell, Settings, ArrowRight, BookOpenText
} from 'lucide-react'

export default function UserManualPage() {
  const { user } = useAuth()
  const [activeTab, setActiveTab] = useState(user?.role || 'donor')

  const roles = [
    { id: 'donor', label: 'Donor Guide', icon: Heart, color: 'text-red-500' },
    { id: 'hospital', label: 'Hospital Guide', icon: Hospital, color: 'text-blue-500' },
    { id: 'staff', label: 'Staff Guide', icon: Users, color: 'text-amber-500' },
    { id: 'admin', label: 'Admin Guide', icon: Settings, color: 'text-purple-500' }
  ]

  return (
    <div className="space-y-6">
      <header className="panel-header flex justify-between items-center">
        <div>
          <span className="eyebrow"><BookOpenText size={14} className="inline mr-1" /> Documentation</span>
          <h2>Platform User Manual</h2>
          <p className="text-muted-foreground mt-1">Learn how to navigate and utilise the features of your Eswatini National Donor Portal.</p>
        </div>
      </header>

      <div className="panel p-6 bg-slate-900/60 backdrop-blur rounded-lg border border-gray-800 shadow-xl">
        {activeTab === 'donor' && (
          <div className="space-y-8 animate-fadeIn">
            <div>
              <h3 className="text-xl font-semibold text-gray-100 flex items-center gap-2 border-b border-gray-800 pb-3">
                <Heart className="text-red-500" size={22} /> Donor Portal Instructions
              </h3>
              <p className="text-gray-400 mt-2">
                Welcome to the Donor Portal. As a verified donor, you are part of an active network that saves lives across Eswatini. Follow the guidelines below to maintain your profile and respond to urgent blood shortages.
              </p>
            </div>

            <div className="grid md:grid-cols-2 gap-6">
              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-red-500/10 rounded text-red-400">
                    <ShieldAlert size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Responding to Emergency Alerts</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  When blood stock levels at local hospitals drop to a critical level, the system auto-matches compatible donors. You will receive an SMS or system notification:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Log in to your portal and view active requests on the Dashboard.</li>
                  <li>Review details including hospital name, urgency level, and distance.</li>
                  <li>Click <strong>Accept</strong> to confirm your availability. Once you accept, you will be temporarily marked as unavailable for other requests to prevent donor fatigue.</li>
                  <li>If you cannot make it, click <strong>Decline</strong> so the system can notify other potential matches.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-red-500/10 rounded text-red-400">
                    <CalendarCheck size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Appointments & Campaigns</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  Instead of waiting for emergency alerts, you can proactively schedule donations or join recruitment drives:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Navigate to **Appointments** to select your preferred branch (Mbabane, Manzini, or Hlathikhulu) and choose a time slot.</li>
                  <li>Browse **Campaigns** to view scheduled donation drives in your region and register to participate.</li>
                  <li>Keep your availability status updated under **My Profile** to control whether you are active in the recruitment pool.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-red-500/10 rounded text-red-400">
                    <Award size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Rewards & Recognition</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  Every donation saves up to three lives. To thank you, DonorConnect features a milestone system:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>View your **Total Donations** and last donation date on your dashboard.</li>
                  <li>Unlock digital badges and milestone cards at **1, 5, 10, 20, and 50 donations**.</li>
                  <li>Show your digital donor card when visiting centers to speed up checkout.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-red-500/10 rounded text-red-400">
                    <Activity size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Personal Eligibility & Health</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  Unlike outdated platforms with rigid time limits, DonorConnect uses a dynamic, **person-based eligibility window** tailored by medical experts:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>During each donation, staff record metrics (weight, BP, hemoglobin levels) to determine when it is safe for you to return.</li>
                  <li>Your **Next Eligible Date** is visible on the profile. The system will automatically restore your status and notify you when you are eligible again.</li>
                </ul>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'hospital' && (
          <div className="space-y-8 animate-fadeIn">
            <div>
              <h3 className="text-xl font-semibold text-gray-100 flex items-center gap-2 border-b border-gray-800 pb-3">
                <Hospital className="text-blue-500" size={22} /> Hospital Portal Instructions
              </h3>
              <p className="text-gray-400 mt-2">
                Welcome to the Hospital Portal. Hospital desk users can request critical blood products, check patient details, and track dispatches from national blood banks in real time.
              </p>
            </div>

            <div className="grid md:grid-cols-2 gap-6">
              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-blue-500/10 rounded text-blue-400">
                    <Droplets size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Creating Blood Requests</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  When stock at your clinic falls low or you have a critical surgery:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Go to **Blood Requests** and click **New Request**.</li>
                  <li>Select the required blood type, number of units, and urgency level (**Critical, High, Medium, Low**).</li>
                  <li>Provide a clinical reference or diagnosis (e.g. postpartum hemorrhage, surgical backup) to help blood bank operators triage requests.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-blue-500/10 rounded text-blue-400">
                    <ArrowRight size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Tracking Dispatches</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  Once a blood bank operator (Mbabane, Manzini, or Hlathikhulu) assigns inventory to your request, you can monitor the status live:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>**Assigned**: Units have been allocated.</li>
                  <li>**Packed**: Coolers are prepared with cold chain monitoring.</li>
                  <li>**In Transit**: Driver is on the road.</li>
                  <li>**Delivered**: Received at your facility. Ensure your staff verify the cold chain labels and sign off upon receipt.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-blue-500/10 rounded text-blue-400">
                    <BookOpen size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Patient Lookup & Compatibility</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  Before assigning blood units, use the **Patient Lookup** tool:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Search patients by National ID to verify their record.</li>
                  <li>Check historical blood type verification and cross-match suitability.</li>
                  <li>Refer to the cross-match matrix built into the system to confirm compatibility before transfusion.</li>
                </ul>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'staff' && (
          <div className="space-y-8 animate-fadeIn">
            <div>
              <h3 className="text-xl font-semibold text-gray-100 flex items-center gap-2 border-b border-gray-800 pb-3">
                <Users className="text-amber-500" size={22} /> Staff Portal Instructions
              </h3>
              <p className="text-gray-400 mt-2">
                Welcome to the ENBTS Staff Portal. Blood bank operators and medical staff can register donors, conduct eligibility checkups, manage inventories, and process clinic dispatches.
              </p>
            </div>

            <div className="grid md:grid-cols-2 gap-6">
              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-amber-500/10 rounded text-amber-400">
                    <Users size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Donor Registration & Verification</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  When a donor visits the branch or mobile caravan:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Go to the **Donor Pool** and search for their account. If not found, click **Register Donor**.</li>
                  <li>Provide their details, verify their identity document, and confirm their blood type.</li>
                  <li>Set their status to **Verified** once their details are validated.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-amber-500/10 rounded text-amber-400">
                    <Activity size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Recording Donations & Custom Eligibility</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  Record a donation immediately after collection:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Select the donor profile and click **Record Donation**.</li>
                  <li>Enter donation date, units, region, town, and screening results.</li>
                  <li>**IMPORTANT**: Evaluate their health metrics (hemoglobin, blood pressure, weight, age) and enter a custom **Next Eligible Date**. There is no rigid gender rule; it is fully person-based.</li>
                  <li>Submitting the record updates the donor pool, increments their totals, and sets them as temporarily deferred.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-amber-500/10 rounded text-amber-400">
                    <ArrowRight size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Dispatch Fulfillment</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  Fulfill pending hospital requests assigned to your branch:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Go to **Blood Requests** or **Dispatches** to see what needs fulfillment.</li>
                  <li>Verify you have sufficient available stock.</li>
                  <li>Prepare the cold-chain coolers and update the dispatch status sequentially: **Accepted** &rarr; **Packed** &rarr; **In Transit** &rarr; **Delivered**.</li>
                  <li>Deductions from inventory occur automatically on delivery.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-amber-500/10 rounded text-amber-400">
                    <Bell size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Stock Alerts & Automated Requests</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  The platform continuously monitors blood stocks.
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>If stock falls below the threshold, admins and branch operators receive an instant system notification.</li>
                  <li>An automated emergency request (code starting with `AUTO-`) is created, matches are evaluated, and compatible donors are alerted automatically. No manual intervention is needed.</li>
                </ul>
              </div>
            </div>
          </div>
        )}

        {activeTab === 'admin' && (
          <div className="space-y-8 animate-fadeIn">
            <div>
              <h3 className="text-xl font-semibold text-gray-100 flex items-center gap-2 border-b border-gray-800 pb-3">
                <Settings className="text-purple-500" size={22} /> Admin Portal Instructions
              </h3>
              <p className="text-gray-400 mt-2">
                Welcome to the National Administrator Portal. Administrators hold full access over system metrics, user verification, branch audits, and campaigns.
              </p>
            </div>

            <div className="grid md:grid-cols-2 gap-6">
              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-purple-500/10 rounded text-purple-400">
                    <Settings size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Branch & User Management</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  Manage system access and structure:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Add/modify institutions (Hospitals, Blood Centers) under **Institutions**.</li>
                  <li>Create and suspend staff, hospital, or admin accounts under **User Accounts**.</li>
                  <li>Review the system **Audit Logs** for complete traceability of all medical edits, verification updates, and dispatches.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-purple-500/10 rounded text-purple-400">
                    <Award size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Campaign Approvals</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  Branch operators submit requests for public recruitment campaigns:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Navigate to **Campaign Requests** to view pending requests.</li>
                  <li>Review venue, date, capacity, and resource needs.</li>
                  <li>Click **Approve** to automatically publish it onto the Donor portal and map it as a scheduled campaign.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-purple-500/10 rounded text-purple-400">
                    <Bell size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">Admin Engagement Messages</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  To run custom recruitment messaging:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Go to the **Donor Pool** and open the Message form.</li>
                  <li>Select message type: **Impact Update, Campaign Reminder, General Notification**. (Birthday messages and retention reminders are automated by the server, so they are not manual).</li>
                  <li>Filter target audience by blood type, region, or minimum donations.</li>
                  <li>Optionally toggle SMS dispatch to push messages to donors' mobile phones.</li>
                </ul>
              </div>

              <div className="bg-slate-800/40 p-5 rounded-lg border border-gray-800 hover:border-gray-700/80 transition-all duration-300">
                <div className="flex items-center gap-3 mb-3">
                  <div className="p-2 bg-purple-500/10 rounded text-purple-400">
                    <BookOpen size={20} />
                  </div>
                  <h4 className="font-semibold text-gray-200">National Analytics & Reports</h4>
                </div>
                <p className="text-sm text-gray-400 leading-relaxed">
                  Track system performance and supply stats:
                </p>
                <ul className="text-xs text-gray-400 mt-3 list-disc pl-4 space-y-1.5">
                  <li>Go to **Reports** to view metrics.</li>
                  <li>Analyze request fulfillment rates, active donor growth, and wastage (expired units).</li>
                  <li>Export data in PDF or CSV formats for national health dashboard reports.</li>
                </ul>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
