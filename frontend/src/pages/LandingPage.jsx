import { ArrowRight, BarChart3, BellRing, CalendarHeart, CheckCircle2, Droplets, HeartHandshake, MapPin, ShieldCheck, Users } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

const lifecycle = [
  ['Recruit', 'Schools, universities, churches, workplaces and community campaigns grow the pool.'],
  ['Verify', 'Identity, blood type and eligibility are managed by authorised blood-service staff.'],
  ['Engage', 'Campaigns, reminders, milestones and impact updates keep donors connected.'],
  ['Retain', 'Donation history and next-eligible dates turn first-time donors into repeat donors.'],
  ['Mobilise', 'When blood is needed, verified and eligible donors are ranked and notified.'],
]

export default function LandingPage() {
  const { user } = useAuth()
  return (
    <div className="landing-page">
      <header className="landing-nav">
        <Link to="/" className="landing-brand"><img src="/donor-mark.svg" alt="" /><span>DonorConnect</span></Link>
        <nav>
          <a href="#mission">Mission</a>
          <a href="#lifecycle">Lifecycle</a>
          <a href="#capabilities">Capabilities</a>
        </nav>
        <div className="landing-actions">
          {user ? <Link className="button button-primary" to="/app/dashboard">Open portal <ArrowRight size={17} /></Link> : (
            <><Link className="button button-ghost" to="/login">Sign in</Link><Link className="button button-primary" to="/register">Join the donor pool <ArrowRight size={17} /></Link></>
          )}
        </div>
      </header>

      <main>
        <section className="hero-section" id="mission">
          <div className="hero-copy">
            <span className="hero-kicker"><span className="pulse-dot" /> Built for Eswatini's donor future</span>
            <h1>Donor management that begins <em>before</em> an emergency.</h1>
            <p>DonorConnect recruits, verifies, engages and retains blood donors—building a larger, reliable and active donor pool that can be mobilised when hospitals need support.</p>
            <div className="hero-actions"><Link to="/register" className="button button-primary button-large">Become a donor <HeartHandshake size={19} /></Link><Link to="/login" className="button button-secondary button-large">Access your portal</Link></div>
            <div className="hero-trust"><span><ShieldCheck size={18} /> Identity protection</span><span><CheckCircle2 size={18} /> Eligibility tracking</span><span><BarChart3 size={18} /> Real growth analytics</span></div>
          </div>
          <div className="hero-visual" aria-hidden="true">
            <div className="hero-orbit orbit-one"><Users size={24} /></div>
            <div className="hero-orbit orbit-two"><BellRing size={22} /></div>
            <div className="hero-orbit orbit-three"><MapPin size={22} /></div>
            <div className="hero-drop"><Droplets size={82} /><strong>Active donor pool</strong><span>Recruit • Retain • Mobilise</span></div>
            <div className="floating-card card-growth"><span>Donor lifecycle</span><strong>Always connected</strong><small>From registration to repeat donation</small></div>
            <div className="floating-card card-ready"><span>Operational readiness</span><strong>Verified + eligible</strong><small>Matching uses trusted donor records</small></div>
          </div>
        </section>

        <section className="impact-strip">
          <div><Users /><span><strong>Recruitment</strong> across institutions and communities</span></div>
          <div><CalendarHeart /><span><strong>Retention</strong> through eligibility and campaign engagement</span></div>
          <div><Droplets /><span><strong>Mobilisation</strong> from a healthier active donor pool</span></div>
        </section>

        <section className="lifecycle-section" id="lifecycle">
          <div className="section-heading"><span>The operating model</span><h2>One connected donor journey</h2><p>Emergency coordination works better because the donor pool has already been built, maintained and understood.</p></div>
          <div className="lifecycle-grid">
            {lifecycle.map(([title, description], index) => <article key={title}><span className="step-number">0{index + 1}</span><h3>{title}</h3><p>{description}</p></article>)}
          </div>
        </section>

        <section className="capabilities-section" id="capabilities">
          <div className="section-heading light"><span>More than an alert system</span><h2>A national donor operations layer</h2></div>
          <div className="capability-grid">
            {[
              [Users, 'Donor pool management', 'Profiles, national identity protection, blood type, availability and verification.'],
              [ShieldCheck, 'Eligibility and deferrals', 'Assessments, donation recovery windows, temporary deferrals and next-eligible dates.'],
              [CalendarHeart, 'Campaign growth engine', 'Recruitment drives, invitations, participation and conversion into actual donations.'],
              [BellRing, 'Engagement and retention', 'Welcome messages, campaign reminders, thank-you updates and milestone recognition.'],
              [Droplets, 'Hospital requests', 'Structured blood requests, compatible donor ranking, notifications and response monitoring.'],
              [BarChart3, 'Decision-grade reporting', 'Growth, verification, eligibility, repeat-donor and recruitment-channel performance.'],
            ].map(([Icon, title, description]) => <article key={title}><Icon size={26} /><h3>{title}</h3><p>{description}</p></article>)}
          </div>
        </section>
      </main>

      <footer className="landing-footer"><div className="landing-brand"><img src="/donor-mark.svg" alt="" /><span>DonorConnect</span></div><p>Grow the pool. Keep donors engaged. Be ready when blood is needed.</p></footer>
    </div>
  )
}
