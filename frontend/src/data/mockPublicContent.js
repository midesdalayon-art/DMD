// Temporary public-site content.
// Replace this module with Laravel API data when real DMD Resort records exist.
export const categories = [
  { id: 'all', label: 'All' },
  { id: 'room', label: 'Rooms' },
  { id: 'cottage', label: 'Cottages' },
  { id: 'function_hall', label: 'Function Hall' },
]

export const accommodations = [
  {
    id: 'room-placeholder',
    category: 'room',
    name: 'Room placeholder',
    context: 'DMD Resort',
    guests: null,
    beds: null,
    price: null,
    status: 'Details to be added',
    summary:
      'Temporary listing used until real room details, pricing, and photos are available from the backend.',
    amenities: ['Amenity details to be added'],
    rules: ['House rules to be added'],
  },
  {
    id: 'cottage-placeholder',
    category: 'cottage',
    name: 'Cottage placeholder',
    context: 'DMD Resort',
    guests: null,
    beds: null,
    price: null,
    status: 'Details to be added',
    summary:
      'Temporary listing used to preview how cottage accommodations will appear on the guest website.',
    amenities: ['Amenity details to be added'],
    rules: ['House rules to be added'],
  },
  {
    id: 'function-hall-placeholder',
    category: 'function_hall',
    name: 'Function hall placeholder',
    context: 'DMD Resort',
    guests: null,
    beds: null,
    price: null,
    status: 'Event details to be added',
    summary:
      'Temporary function hall preview. Replace with actual hall information when available.',
    amenities: ['Facility details to be added'],
    rules: ['Booking policies to be added'],
  },
]

export const facilities = [
  {
    id: 'facility-placeholder-1',
    name: 'Facility placeholder',
    description: 'Replace with a verified DMD Resort facility name and details.',
  },
  {
    id: 'facility-placeholder-2',
    name: 'Guest amenity placeholder',
    description: 'Use this slot for a real amenity once the project data is available.',
  },
  {
    id: 'facility-placeholder-3',
    name: 'Resort feature placeholder',
    description: 'Placeholder content only. No real facility claim is being made.',
  },
]

export const aboutPlaceholder =
  'DMD Resort public information has not been added yet. This section is reserved for verified resort details, location context, and guest-facing highlights.'

export const contactPlaceholder =
  'Contact information is pending. Add verified phone, email, address, and social links here when available.'

