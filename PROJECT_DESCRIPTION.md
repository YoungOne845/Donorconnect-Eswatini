# DonorConnect: Automated Voluntary Donor Mobilization System for Eswatini

DonorConnect is a comprehensive, production-ready digital health platform designed to transform Eswatini's national voluntary blood donation infrastructure. By aligning technology with voluntary incentives, it addresses the critical national deficit, decentralizes recruitment to rural communities, and automates emergency replenishment logistics.

---

## 🩸 The Problem Fit

1. **The Deficit**: Eswatini consistently falls short of international public health recommendations for blood collections, leaving regional health hubs in Hhohho, Manzini, Lubombo, and Shiselweni highly vulnerable during emergency surges.
2. **The Attrition Crisis**: Voluntary donation is highly inefficient due to poor retention: over **70% of first-time donors never return to donate again** because Eswatini has had zero systematic engagement, tracking, or incentives.
3. **The Health Equity Gap**: Outlying and rural communities face severe delays during emergency shortages. Communication is centralized, and citizens lack direct channels to check stock, verify donation eligibility, or find mobile collection drives.
4. **Emergency Panic**: In critical shortages, families and clinics resort to desperate, unverified social media appeals, creating panic and logistical bottlenecks at bedside.

*DonorConnect directly aligns with the MTN Y'ello Care 2026 mandate: **Expand Equitable Health for Every Community** by decentralizing mobilization and establishing a reliable, structured national donor reserve.*

---

## 🛠️ The Tech-Savvy Architecture

DonorConnect is built as a responsive web application powered by a modern, secure full-stack architecture:

*   **Frontend**: React 18 with modern, responsive components, built on vanilla CSS for speed, styling flexibility, and precise layout rendering.
*   **Backend**: PHP 8 session-managed REST API with client-side CSRF validation, rate limiting, and strict account lockouts to meet clinical security standards.
*   **Database**: MySQL/MariaDB relational database with normalized structures tracking donor profiles, blood inventory levels, campaign registries, and dispatches.

### Three Mandated Digital Elements

To bridge Eswatini's digital divide and maximize prototype integrity, three advanced components are fully operational:

1. **AI Diagnostic Pre-Screening Chatbot**  
   An interactive pre-screening engine that automates primary eligibility assessment. Before donors travel to clinics, the AI guides them through travel, medical history, weight, and calculated donation window intervals (e.g., 2 months for males, 3 months for females). This reduces clinic-side queues and preserves health worker capacity.
2. **Low-Bandwidth Simulated USSD Loop (`*268#`)**  
   Offline accessibility is crucial for health equity. Using a simulated USSD dial code menu (`*268#`), rural donors with basic feature phones can register, check their next eligible date, toggle availability, and respond to emergency blood shortages—entirely offline, without mobile data costs.
3. **Real-Time Supply & Response Telemetry**  
   An automated dashboard tracking regional blood bank stock levels. When inventory for any blood type drops to or below the critical threshold (5 units), the backend automatically triggers an emergency request, ranks compatible donors using our matching algorithm, and dispatches mobilization alerts. Telemetry captures donor response velocity to model replenishment times.

### Client-Side Bedside Hashing

To guarantee patient-donor privacy under clinical standards, the system implements **SHA-256 client-side cryptographic hashing**. Raw National ID numbers are never sent or stored in plaintext. They are hashed locally on the client browser. The backend only matches search hashes for duplicate detection and identity lookup, protecting sensitive information.

---

## 🏆 Retention: Family Blood Insurance Cover

DonorConnect transforms passive voluntary donors into active community assets using a structured, tier-based incentive program:

*   **Bronze Tier**: Unlocked upon verification. Secures immediate priority emergency blood access for the donor.
*   **Silver Tier**: Unlocked after **3 successful donations**. Extends priority coverage to the donor's immediate family (up to 5 members).
*   **Gold Tier**: Unlocked after **6 successful donations**. Covers up to 10 family members and triggers high-priority bedside flags for accident victims.

### Inactivity Demotion Logic
To prevent system abuse and maintain an active donor pool, tiers are dynamic. **Donors must maintain active donor status.** If a donor goes past their eligible date without donating for 12 months, they are flagged as "At Risk." If they remain inactive, their tier is systematically demoted (e.g., Gold to Silver, or Silver to Bronze), encouraging continuous voluntary contribution.

---

## 💼 Business Model & Local Job Creation

Our execution strategy ensures long-term financial self-sufficiency and drives community employment:

### Financial Sustainability (Revenue Model)
1. **B2G SaaS Licensing**: A annual subscription model licensed to the Ministry of Health and Eswatini National Blood Service (ENBTS) to run national logistics and reporting.
2. **B2B Clinical API Fees**: Private clinics and hospitals pay transaction-based lookup fees to access the bedside donor verification registry.
3. **MTN & CSR Sponsorships**: Corporate partners sponsor campaign rewards (e.g., airtime tokens) in exchange for community engagement metrics.

### Youth Employment Pathways
*   **Technical Operations**: Database operators and remote systems maintainers hired directly from local ICT graduates.
*   **Regional Campaign Leads**: Local youth trained and employed to manage community outreach campaigns, USSD workshops, and regional mobilizations.
*   **Logistical Coordinators**: Youth operators managing mobile collection scheduling and driver routing telemetry.

---

## 📊 E10,000 Milestone Pilot Budget

The immediate E10,000 prize is rigorously mapped to a 30-day proof-of-concept validation cycle:

| Budget Item | Allocation (SZL) | Technical Focus | 30-Day Milestone |
| :--- | :--- | :--- | :--- |
| **SMS & USSD Gateway Integration** | E3,000 | Bulk SMS credits purchase and sandbox gateway webhook configurations. | Validate offline USSD loop responses under 15 seconds. |
| **Local Cloud Host & Security** | E3,500 | Provision secure local cloud virtual instances and run pre-penetration security audits. | Live production API endpoint active with SHA-256 hashing operational. |
| **Onboarding & Training Kits** | E2,500 | Travel to Mbabane and Manzini clinics to install nodes and print quick-reference user cards. | Live database sync of initial inventory levels across 2 pilot clinics. |
| **Youth Usability Focus Groups** | E1,000 | Workshops with 30 local youth to test USSD navigation speed. | Usability feedback loop integrated to optimize mobile response rates. |
| **Total** | **E10,000** | **30-Day Prototype Loop Validation** | **Pilot nodes ready to launch.** |

---

## ❓ Q&A Strategic Playbook

### Q1: How does the AI chatbot ensure diagnostic accuracy?
> "The AI pre-screening chatbot operates as a strict rules engine based on Eswatini clinical guidelines. It checks travel, medication, age, weight, and donation windows. It does not replace clinical diagnosis; it acts as a primary filter to save health worker hours and prevent ineligible donors from making unnecessary journeys."

### Q2: How does the USSD loop work offline without mobile data?
> "Our backend exposes a REST API. When a user dials `*268#`, the MTN network gateway routes the inputs as JSON HTTP POST packets to our PHP server. The server processes the request (e.g., checking eligibility or updating availability) and sends back a lightweight text menu. This operates entirely on GSM signaling, requiring zero mobile data."

### Q3: What telemetry is captured and how is privacy protected?
> "We measure blood inventory levels at regional banks and donor response times (the duration between an alert being sent and accepted). To ensure privacy, telemetry logs track aggregate response velocities and are entirely decoupled from personal identity parameters, which are cryptographically protected using SHA-256 client-side hashing."
