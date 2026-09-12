# Guest Mood Score

`reservations` has a one-to-zero-or-one relationship with `guest_feedback`.
Feedback is accepted only when the reservation status is `checked_out`.
Registered customers are authorized by `user_id`; accountless guests are
authorized by the existing permanent guest access token in
`X-Guest-Access-Token`. The database unique constraint on
`guest_feedback.reservation_id` enforces one response per reservation.

Ratings are stored as integers: 1 Awful, 2 Bad, 3 Okay, 4 Good, and 5
Amazing. Mood Score is calculated from the unrounded aggregate average as
`round((average rating / 5) * 100, 1)`; the displayed average is rounded to two
decimal places. Mood labels use
these centralized ranges: 1.00–1.49 Awful, 1.50–2.49 Bad, 2.50–3.49 Okay,
3.50–4.49 Good, and 4.50–5.00 Amazing.

Reports aggregate only feedback belonging to checked-out reservations and use
the feedback `submitted_at` date range. Comments are returned only to Admin and
Manager reports and are rendered as text in the frontend. Feedback is not part
of payment receipts and is immutable after submission.
