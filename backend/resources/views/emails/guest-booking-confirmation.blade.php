<!doctype html>
<html lang="en">
<body style="margin:0;background:#f5f1e8;color:#193d31;font-family:Arial,sans-serif;line-height:1.5;">
    <div style="max-width:620px;margin:0 auto;padding:28px 16px;">
        <div style="background:#ffffff;border:1px solid #e2e8e1;border-radius:14px;padding:28px;">
            <p style="margin:0 0 8px;color:#2f7557;font-size:12px;font-weight:700;letter-spacing:.12em;">DMD FAMILY RESORT</p>
            <h1 style="margin:0 0 18px;font-size:28px;color:#193d31;">Booking Confirmed</h1>
            <p>Hello {{ $booking['first_name'] ?: 'Guest' }},</p>
            <p>Your reservation has been confirmed.</p>

            <div style="margin:24px 0;padding:18px;background:#f8faf7;border-radius:10px;">
                <p style="margin:0 0 8px;"><strong>Booking Reference:</strong> {{ $booking['booking_reference'] }}</p>
                <p style="margin:0 0 8px;"><strong>Accommodation:</strong> {{ $booking['accommodation'] }}</p>
                <p style="margin:0 0 8px;"><strong>Check-in:</strong> {{ $booking['check_in'] }}</p>
                <p style="margin:0 0 8px;"><strong>Check-out:</strong> {{ $booking['check_out'] }}</p>
                <p style="margin:0;"><strong>Guests:</strong> {{ $booking['guests'] }}</p>
            </div>

            <h2 style="margin:0 0 10px;font-size:18px;color:#193d31;">Payment Summary</h2>
            <p style="margin:4px 0;"><strong>Total:</strong> {{ $booking['total'] }}</p>
            <p style="margin:4px 0;"><strong>Paid:</strong> {{ $booking['paid'] }}</p>
            <p style="margin:4px 0;"><strong>Remaining Balance:</strong> {{ $booking['balance'] }}</p>
            <p style="margin:4px 0 18px;"><strong>Payment Status:</strong> {{ $booking['payment_status'] }}</p>

            <div style="margin:24px 0;padding:18px;text-align:center;background:#f8faf7;border:1px solid #e2e8e1;border-radius:10px;">
                <h2 style="margin:0 0 8px;font-size:18px;color:#193d31;">Your Booking QR Code</h2>
                <p style="margin:0 0 14px;color:#66756d;font-size:13px;">Present this QR code to the Front Desk during check-in for booking verification.</p>
                <img src="{{ $message->embedData($booking['qr_image'], 'booking-qr.png', 'image/png') }}" alt="Booking verification QR code" width="260" height="260" style="display:block;width:260px;height:260px;margin:0 auto 14px;">
                <p style="margin:0;color:#66756d;font-size:12px;"><strong>Booking Reference:</strong><br><span style="color:#193d31;font-size:14px;font-weight:700;">{{ $booking['booking_reference'] }}</span></p>
            </div>

            <p>{{ $booking['payment_message'] }}</p>
            <p style="margin:24px 0;">
                <a href="{{ $bookingUrl }}" style="display:inline-block;background:#2f7557;color:#ffffff;text-decoration:none;border-radius:8px;padding:12px 18px;font-weight:700;">View Your Booking</a>
            </p>
            <p style="margin:0;color:#66756d;font-size:12px;">Keep this secure link private. It gives access to this booking.</p>
        </div>
    </div>
</body>
</html>
