const DISPLAY_TIME_FORMAT = new Intl.DateTimeFormat('en-US', {
  hour: 'numeric',
  minute: '2-digit',
  hour12: true,
})

function parseTimeToMinutes(value, fallback = 0) {
  if (!value) {
    return fallback
  }

  const [hours, minutes] = String(value).split(':').map(Number)

  if (Number.isNaN(hours) || Number.isNaN(minutes)) {
    return fallback
  }

  return ((hours % 24) * 60 + (minutes % 60) + 24 * 60) % (24 * 60)
}

function minutesToTimeValue(totalMinutes) {
  const normalized = ((totalMinutes % (24 * 60)) + 24 * 60) % (24 * 60)
  const hours = Math.floor(normalized / 60)
  const minutes = normalized % 60

  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`
}

export function formatTimeDisplay(value, fallback = '—') {
  if (!value) {
    return fallback
  }

  const [hours, minutes] = String(value).split(':').map(Number)

  if (Number.isNaN(hours) || Number.isNaN(minutes)) {
    return fallback
  }

  const date = new Date(2000, 0, 1, hours, minutes)

  if (Number.isNaN(date.getTime())) {
    return fallback
  }

  return DISPLAY_TIME_FORMAT.format(date)
}

export function buildArrivalTimeOptions(bookingSettings = {}) {
  const startMinutes = parseTimeToMinutes(bookingSettings.house_rules_check_in_time, 14 * 60)
  let endMinutes = parseTimeToMinutes(bookingSettings.house_rules_quiet_hours_start, 22 * 60)

  if (endMinutes <= startMinutes) {
    endMinutes = Math.min(startMinutes + 8 * 60, 23 * 60 + 30)
  }

  const options = []

  for (let minutes = startMinutes; minutes <= endMinutes; minutes += 30) {
    options.push(minutesToTimeValue(minutes))
  }

  return options
}

export function formatReservationDateTime(dateValue, timeValue, timeFallback) {
  if (!dateValue) {
    return '—'
  }

  if (typeof dateValue === 'string' && dateValue.includes('T')) {
    const dateTime = new Date(dateValue)

    if (!Number.isNaN(dateTime.getTime())) {
      return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'Asia/Manila',
      }).format(dateTime)
    }
  }

  const date = new Date(`${dateValue}T00:00:00`)

  if (Number.isNaN(date.getTime())) {
    return dateValue
  }

  const dateLabel = new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(date)

  if (!timeValue) {
    return dateLabel
  }

  return `${dateLabel} • ${formatTimeDisplay(timeValue, timeFallback)}`
}

export function formatReservationStaySummary(reservation, bookingSettings = {}) {
  if (!reservation) {
    return null
  }

  const type = reservation.accommodation?.type ?? reservation.type
  const isFunctionHall = type === 'function_hall'
  const isExclusiveResort = type === 'exclusive_resort'
  const isRoom = type === 'room'
  const isCottage = type === 'cottage'
  const arrivalTime = reservation.expected_arrival_time ?? reservation.preferred_arrival_time
  const departureTime = reservation.expected_departure_time

  if (isFunctionHall || isExclusiveResort) {
    return {
      primaryLabel: 'Start Date',
      primaryValue: reservation.check_in,
      secondaryLabel: 'Reserved Through',
      secondaryValue: reservation.check_out,
      primaryTimeLabel: null,
      secondaryTimeLabel: null,
      primaryTimeValue: null,
      secondaryTimeValue: null,
      durationHours: Math.max(1, Number(reservation.stay_days ?? 1)) * 24,
      stayDays: Math.max(1, Number(reservation.stay_days ?? 1)),
    }
  }

  if (isRoom) {
    const stayDays = Math.max(1, Number(reservation.stay_days ?? 1))

    return {
      primaryLabel: 'Check-in',
      primaryValue: reservation.check_in_at ?? reservation.check_in,
      secondaryLabel: 'Check-out',
      secondaryValue: reservation.check_out_at ?? reservation.check_out,
      primaryTimeLabel: null,
      secondaryTimeLabel: null,
      primaryTimeValue: null,
      secondaryTimeValue: null,
      durationHours: reservation.duration_hours ?? stayDays * 24,
      stayDays,
    }
  }

  if (isCottage) {
    const periodType = reservation.cottage_period_type ?? 'day_use'
    const periodCount = Math.max(1, Number(reservation.cottage_period_count ?? 1))

    return {
      primaryLabel: 'Check-in',
      primaryValue: reservation.check_in,
      secondaryLabel: 'Check-out',
      secondaryValue: reservation.check_out,
      primaryTimeLabel: 'Rental type',
      secondaryTimeLabel: 'Periods',
      primaryTimeValue: periodType === 'overnight' ? 'Overnight' : 'Day Use',
      secondaryTimeValue: `${periodCount} period${periodCount === 1 ? '' : 's'}`,
      primaryTimeFallback: periodType === 'overnight' ? 'Overnight' : 'Day Use',
      secondaryTimeFallback: `${periodCount * 12} hours`,
      durationHours: periodCount * 12,
      cottagePeriodType: periodType,
      cottagePeriodCount: periodCount,
    }
  }

  return {
    primaryLabel: 'Check-in',
    primaryValue: reservation.check_in,
    secondaryLabel: 'Check-out',
    secondaryValue: reservation.check_out,
    primaryTimeLabel: 'Expected arrival',
    secondaryTimeLabel: 'Expected departure',
    primaryTimeValue: arrivalTime,
    secondaryTimeValue: departureTime,
    primaryTimeFallback: formatTimeDisplay(bookingSettings.house_rules_check_in_time, '2:00 PM'),
    secondaryTimeFallback: formatTimeDisplay(bookingSettings.house_rules_check_out_time, '12:00 PM'),
  }
}
