import { useEffect, useRef, useState } from 'react'

// ─── Eligibility Rules Engine ──────────────────────────────────────────────
const DEFERRAL_RULES = [
  { question: 'Have you had a tattoo, piercing, or acupuncture in the last 6 months?', deferMonths: 6, key: 'tattoo' },
  { question: 'Have you been ill, had a fever, or taken antibiotics in the last 2 weeks?', deferMonths: 0.5, key: 'illness' },
  { question: 'Have you travelled outside the Southern Africa region in the last 12 months?', deferMonths: 3, key: 'travel' },
  { question: 'Have you had surgery or a blood transfusion in the last 12 months?', deferMonths: 12, key: 'surgery' },
  { question: 'Are you currently pregnant or have you given birth in the last 6 months?', deferMonths: 6, key: 'pregnancy' },
  { question: 'Are you currently taking any medication or have any chronic illness?', deferMonths: null, key: 'medication' },
]

// ─── Conversation Flow ─────────────────────────────────────────────────────
const FLOW = {
  GREETING: 'GREETING',
  MAIN_MENU: 'MAIN_MENU',
  SCREENING_AGE: 'SCREENING_AGE',
  SCREENING_WEIGHT: 'SCREENING_WEIGHT',
  SCREENING_LAST_DONATION: 'SCREENING_LAST_DONATION',
  SCREENING_DEFERRAL: 'SCREENING_DEFERRAL',
  SCREENING_RESULT: 'SCREENING_RESULT',
  FAQ: 'FAQ',
}

const BOT_DELAY_MS = 600

function getBotMessage(text, isTyping = false) {
  return { from: 'bot', text, isTyping, id: crypto.randomUUID() }
}
function getUserMessage(text) {
  return { from: 'user', text, id: crypto.randomUUID() }
}

function calcDeferralResult(sex, lastDonationMonthsAgo, deferrals) {
  // Standard deferral windows: 2 months males, 3 months females
  const minInterval = sex === 'female' ? 3 : 2
  const issues = []

  if (lastDonationMonthsAgo !== null && lastDonationMonthsAgo < minInterval) {
    const remaining = (minInterval - lastDonationMonthsAgo).toFixed(1)
    issues.push(`You last donated ${lastDonationMonthsAgo} month(s) ago. The minimum interval is ${minInterval} months for ${sex === 'female' ? 'females' : 'males'}. You will be eligible again in approximately ${remaining} more month(s).`)
  }

  for (const d of deferrals) {
    if (d.answer === true) {
      if (d.rule.deferMonths === null) {
        issues.push(`Due to your medication or chronic condition, a clinical assessment by ENBTS staff is required before donating.`)
      } else if (d.rule.deferMonths > 0) {
        issues.push(`Because of your response to "${d.rule.question}", you may need to defer for up to ${d.rule.deferMonths} month(s).`)
      }
    }
  }

  return issues
}

export default function Chatbot() {
  const [isOpen, setIsOpen] = useState(false)
  const [messages, setMessages] = useState([])
  const [input, setInput] = useState('')
  const [flow, setFlow] = useState(FLOW.GREETING)
  const [screeningData, setScreeningData] = useState({
    sex: null,
    age: null,
    weight: null,
    lastDonationMonthsAgo: null,
    deferralIndex: 0,
    deferrals: [],
  })
  const [quickReplies, setQuickReplies] = useState([])
  const messagesEndRef = useRef(null)
  const inputRef = useRef(null)

  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }

  useEffect(() => {
    scrollToBottom()
  }, [messages])

  useEffect(() => {
    if (isOpen && messages.length === 0) {
      setTimeout(() => {
        pushBot('👋 Hello! I\'m the **DonorConnect AI Assistant**. I can check your blood donation eligibility or answer questions about our platform.')
        setTimeout(() => {
          showMainMenu()
        }, BOT_DELAY_MS * 1.5)
      }, 300)
    }
  }, [isOpen])

  const pushBot = (text) => {
    setMessages((prev) => [...prev, getBotMessage(text)])
  }

  const pushUser = (text) => {
    setMessages((prev) => [...prev, getUserMessage(text)])
  }

  const showMainMenu = () => {
    setFlow(FLOW.MAIN_MENU)
    setQuickReplies(['🩸 Check my eligibility', '❓ How to register', '📅 About appointments', '💉 Donation process', '🔐 OTP Login help'])
    pushBot('What would you like help with today?')
  }

  const startScreening = () => {
    setFlow(FLOW.SCREENING_AGE)
    setScreeningData({ sex: null, age: null, weight: null, lastDonationMonthsAgo: null, deferralIndex: 0, deferrals: [] })
    setQuickReplies([])
    pushBot('Great! I\'ll run a quick **AI eligibility pre-screen** — the same checklist our clinical staff use. This does not replace a medical assessment, but will tell you if you\'re likely eligible to donate today.\n\n**First: How old are you?** (Type your age in years)')
  }

  const handleScreeningAge = (text) => {
    const age = parseInt(text, 10)
    if (isNaN(age) || age < 1 || age > 120) {
      pushBot('Please enter a valid age (e.g. "22").')
      return
    }
    if (age < 16) {
      pushBot('❌ **Not yet eligible.** The minimum age to donate blood in Eswatini is **16 years**. Come back when you\'re old enough — your donation will save lives! 🙏')
      setQuickReplies(['🔙 Back to menu'])
      setFlow(FLOW.MAIN_MENU)
      return
    }
    if (age > 65) {
      pushBot('⚠️ Donors over 65 require special medical clearance. Please visit your nearest ENBTS clinic to be assessed in person. Thank you for your willingness to donate! ❤️')
      setQuickReplies(['🔙 Back to menu'])
      setFlow(FLOW.MAIN_MENU)
      return
    }
    setScreeningData((prev) => ({ ...prev, age }))
    setFlow(FLOW.SCREENING_WEIGHT)
    pushBot(`Age ${age} ✅ Good. **What is your weight in kilograms?** (The minimum is 50 kg)`)
  }

  const handleScreeningWeight = (text) => {
    const weight = parseFloat(text)
    if (isNaN(weight) || weight < 1) {
      pushBot('Please enter your weight in kilograms, e.g. "65".')
      return
    }
    if (weight < 50) {
      pushBot(`❌ **Not eligible at this time.** Your weight of ${weight} kg is below the minimum of **50 kg** required for safe blood donation. Focus on a healthy diet and check back when you've reached the minimum weight. 💪`)
      setQuickReplies(['🔙 Back to menu'])
      setFlow(FLOW.MAIN_MENU)
      return
    }
    setScreeningData((prev) => ({ ...prev, weight }))
    setFlow(FLOW.SCREENING_LAST_DONATION)
    setQuickReplies(['First time donor', '1 month ago', '2 months ago', '3+ months ago'])
    pushBot(`Weight ${weight} kg ✅ Good. **Have you donated blood before?** If yes, roughly when was your last donation?`)
  }

  const handleScreeningLastDonation = (text) => {
    let months = null
    const lower = text.toLowerCase()
    if (lower.includes('first') || lower.includes('never') || lower.includes('no')) {
      months = null
    } else {
      const match = lower.match(/(\d+(\.\d+)?)\s*(month|week|year)/)
      if (match) {
        const val = parseFloat(match[1])
        if (lower.includes('week')) months = val / 4
        else if (lower.includes('year')) months = val * 12
        else months = val
      } else if (lower.includes('1 month')) {
        months = 1
      } else if (lower.includes('2 month')) {
        months = 2
      } else if (lower.includes('3')) {
        months = 3
      } else {
        months = null
      }
    }
    setScreeningData((prev) => ({ ...prev, lastDonationMonthsAgo: months }))
    setFlow(FLOW.SCREENING_DEFERRAL)
    setQuickReplies(['Yes', 'No'])
    const nextQuestion = DEFERRAL_RULES[0].question
    pushBot(`Got it. Now I'll ask a few quick health questions. Please answer **Yes** or **No**.\n\n**Question 1 of ${DEFERRAL_RULES.length}:** ${nextQuestion}`)
  }

  const handleDeferralQuestion = (text, currentData) => {
    const lower = text.toLowerCase()
    const isYes = lower.includes('yes') || lower === 'y'
    const isNo = lower.includes('no') || lower === 'n'

    if (!isYes && !isNo) {
      pushBot('Please reply with **Yes** or **No**.')
      setQuickReplies(['Yes', 'No'])
      return
    }

    const currentIndex = currentData.deferralIndex
    const rule = DEFERRAL_RULES[currentIndex]
    const newDeferrals = [...currentData.deferrals, { rule, answer: isYes }]
    const nextIndex = currentIndex + 1

    if (nextIndex < DEFERRAL_RULES.length) {
      const nextRule = DEFERRAL_RULES[nextIndex]
      setScreeningData((prev) => ({ ...prev, deferrals: newDeferrals, deferralIndex: nextIndex }))
      setQuickReplies(['Yes', 'No'])
      pushBot(`**Question ${nextIndex + 1} of ${DEFERRAL_RULES.length}:** ${nextRule.question}`)
    } else {
      // All questions answered — compute result
      setScreeningData((prev) => ({ ...prev, deferrals: newDeferrals }))
      setFlow(FLOW.SCREENING_RESULT)
      setQuickReplies([])
      computeResult({ ...currentData, deferrals: newDeferrals })
    }
  }

  const computeResult = (data) => {
    const sex = data.sex
    const issues = calcDeferralResult(sex, data.lastDonationMonthsAgo, data.deferrals)

    setTimeout(() => {
      if (issues.length === 0) {
        pushBot('✅ **Pre-Screen Result: Likely Eligible!**\n\nBased on your answers, you appear to meet the standard criteria for blood donation. 🎉\n\n📋 **Next Steps:**\n• Book an appointment through your donor dashboard\n• Visit your nearest ENBTS clinic (Mbabane, Manzini, or Hlathikhulu)\n• A trained nurse will perform the final clinical assessment\n\nThank you for choosing to save lives! ❤️🩸')
      } else {
        const bulletPoints = issues.map((i) => `• ${i}`).join('\n')
        pushBot(`⚠️ **Pre-Screen Result: Action Required**\n\nBased on your answers, there are some factors that may affect your eligibility:\n\n${bulletPoints}\n\n📋 **Recommendation:** Visit your nearest ENBTS clinic for a full clinical assessment. Staff can verify your status in person and advise you on the right time to donate.\n\nYour willingness to donate matters enormously — thank you! ❤️`)
      }
      setQuickReplies(['🔙 Back to menu', '📅 Book appointment'])
      setFlow(FLOW.MAIN_MENU)
    }, BOT_DELAY_MS)
  }

  const handleFaq = (topic) => {
    const responses = {
      register: '**How to Register:**\nGo to the Register page and fill in your National ID, date of birth, and contact details. If ENBTS already registered you at a campaign, use OTP login instead.',
      appointment: '**Appointments:**\nYou can book an appointment directly from your Donor Dashboard under "Appointments". ENBTS staff will confirm the date and time.',
      donation: '**Donation Process:**\n1. Register & verify your profile\n2. Book an appointment\n3. Visit the clinic for a clinical check\n4. Donate (takes about 10 minutes)\n5. Rest and receive a light refreshment\n\nOne donation can save up to 3 lives! 🩸',
      otp: '**OTP Login:**\nSelect "OTP" on the login page, enter your National ID, then tap "Request Code". The code is sent via SMS.',
    }

    const lower = topic.toLowerCase()
    if (lower.includes('register')) return responses.register
    if (lower.includes('appointment') || lower.includes('book')) return responses.appointment
    if (lower.includes('donation') || lower.includes('process') || lower.includes('donat')) return responses.donation
    if (lower.includes('otp') || lower.includes('login') || lower.includes('password')) return responses.otp
    return "I'm here to help! You can ask about registration, OTP login, eligibility, appointments, or the donation process."
  }

  const handleSend = (textOverride) => {
    const text = (textOverride ?? input).trim()
    if (!text) return
    setInput('')
    setQuickReplies([])
    pushUser(text)

    const lower = text.toLowerCase()

    setTimeout(() => {
      if (flow === FLOW.MAIN_MENU || flow === FLOW.GREETING) {
        if (lower.includes('eligib') || lower.includes('check') || lower.includes('screen')) {
          // Ask sex before starting
          setFlow('SCREENING_SEX')
          setQuickReplies(['Male', 'Female'])
          pushBot('To accurately calculate your deferral window, I need to know your **sex**:')
          return
        }
        const faqReply = handleFaq(text)
        pushBot(faqReply)
        setQuickReplies(['🔙 Back to menu'])
        return
      }

      if (flow === 'SCREENING_SEX') {
        const isFemale = lower.includes('female') || lower === 'f'
        const isMale = lower.includes('male') || lower === 'm'
        if (!isFemale && !isMale) {
          pushBot('Please select **Male** or **Female**.')
          setQuickReplies(['Male', 'Female'])
          return
        }
        setScreeningData((prev) => ({ ...prev, sex: isFemale ? 'female' : 'male' }))
        startScreening()
        return
      }

      if (flow === FLOW.SCREENING_AGE) {
        handleScreeningAge(text)
        return
      }

      if (flow === FLOW.SCREENING_WEIGHT) {
        handleScreeningWeight(text)
        return
      }

      if (flow === FLOW.SCREENING_LAST_DONATION) {
        handleScreeningLastDonation(text)
        return
      }

      if (flow === FLOW.SCREENING_DEFERRAL) {
        handleDeferralQuestion(text, screeningData)
        return
      }

      if (lower.includes('menu') || lower.includes('back') || lower.includes('home')) {
        showMainMenu()
        return
      }

      if (lower.includes('book') || lower.includes('appointment')) {
        pushBot('You can book an appointment from your **Donor Dashboard** → Appointments tab. ENBTS staff will confirm your session.')
        setQuickReplies(['🔙 Back to menu'])
        return
      }

      const faqReply = handleFaq(text)
      pushBot(faqReply)
      setQuickReplies(['🔙 Back to menu'])
    }, BOT_DELAY_MS)
  }

  const renderText = (text) => {
    // Render **bold** markdown simply
    return text.split('\n').map((line, i) => {
      const parts = line.split(/(\*\*[^*]+\*\*)/)
      return (
        <span key={i}>
          {parts.map((part, j) =>
            part.startsWith('**') && part.endsWith('**') ? (
              <strong key={j}>{part.slice(2, -2)}</strong>
            ) : (
              <span key={j}>{part}</span>
            )
          )}
          {i < text.split('\n').length - 1 && <br />}
        </span>
      )
    })
  }

  return (
    <>
      <button
        type="button"
        id="chatbot-fab-btn"
        className="chatbot-fab"
        onClick={() => setIsOpen((v) => !v)}
        aria-label="Open DonorConnect AI Assistant"
        title="AI Pre-Screening Assistant"
      >
        {isOpen ? '✕' : '🤖'}
      </button>

      {isOpen && (
        <div className="chatbot-window" role="dialog" aria-label="DonorConnect AI Assistant">
          <div className="chatbot-header">
            <div className="chatbot-header-info">
              <span className="chatbot-avatar">🩸</span>
              <div>
                <strong>DonorConnect AI</strong>
                <span className="chatbot-status">Pre-Screening Engine • Online</span>
              </div>
            </div>
            <button type="button" onClick={() => setIsOpen(false)} aria-label="Close assistant" className="chatbot-close">✕</button>
          </div>

          <div className="chatbot-messages" id="chatbot-messages-list">
            {messages.map((msg) => (
              <div key={msg.id} className={`chatbot-row ${msg.from === 'user' ? 'from-user' : 'from-bot'}`}>
                {msg.from === 'bot' && <span className="chatbot-bot-icon">🤖</span>}
                <div className="chatbot-bubble">{msg.from === 'bot' ? renderText(msg.text) : msg.text}</div>
              </div>
            ))}
            {quickReplies.length > 0 && (
              <div className="chatbot-quick-replies">
                {quickReplies.map((r) => (
                  <button
                    key={r}
                    type="button"
                    className="chatbot-quick-reply-btn"
                    onClick={() => handleSend(r)}
                  >
                    {r}
                  </button>
                ))}
              </div>
            )}
            <div ref={messagesEndRef} />
          </div>

          <div className="chatbot-input-row">
            <input
              ref={inputRef}
              type="text"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleSend()}
              placeholder="Type a message or choose an option above…"
              id="chatbot-text-input"
            />
            <button type="button" className="button button-primary" onClick={() => handleSend()} id="chatbot-send-btn">
              Send
            </button>
          </div>
        </div>
      )}
    </>
  )
}
