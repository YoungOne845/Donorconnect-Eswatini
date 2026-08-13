export const NATIONAL_ID_LENGTH = 13
export const MINIMUM_DONOR_AGE = 16

export function normalizeNationalId(value) {
  return String(value || '').replace(/\D/g, '').slice(0, NATIONAL_ID_LENGTH)
}

export function parseNationalId(value, today = new Date()) {
  const nationalId = normalizeNationalId(value)
  if (nationalId.length !== NATIONAL_ID_LENGTH) {
    return { valid: false, nationalId }
  }

  const yearPart = Number(nationalId.slice(0, 2))
  const month = Number(nationalId.slice(2, 4))
  const day = Number(nationalId.slice(4, 6))
  const currentTwoDigitYear = today.getFullYear() % 100
  const fullYear = yearPart <= currentTwoDigitYear ? 2000 + yearPart : 1900 + yearPart
  const birthDate = new Date(fullYear, month - 1, day)

  const validDate = birthDate.getFullYear() === fullYear
    && birthDate.getMonth() === month - 1
    && birthDate.getDate() === day
    && birthDate <= startOfDay(today)

  if (!validDate) {
    return { valid: false, nationalId }
  }

  const eligibleOn = new Date(fullYear + MINIMUM_DONOR_AGE, month - 1, day)
  const age = calculateAge(birthDate, today)

  return {
    valid: true,
    nationalId,
    birthDate,
    birthDateIso: toIsoDate(birthDate),
    eligibleOn,
    age,
    isEligible: startOfDay(today) >= startOfDay(eligibleOn),
    requiresGuardianConsentNotice: age >= 16 && age < 18,
  }
}

export function calculateAge(birthDate, today = new Date()) {
  let age = today.getFullYear() - birthDate.getFullYear()
  const monthDifference = today.getMonth() - birthDate.getMonth()
  if (monthDifference < 0 || (monthDifference === 0 && today.getDate() < birthDate.getDate())) {
    age -= 1
  }
  return age
}

export function formatLongDate(date) {
  return new Intl.DateTimeFormat('en-SZ', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(date)
}

function startOfDay(date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

function toIsoDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
