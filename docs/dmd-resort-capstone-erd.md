# DMD Resort Capstone ERD

This simplified ERD focuses on the main resort business entities that are actually present in the current PostgreSQL schema.

Excluded from this academic view:
- framework tables
- audit/history details
- low-level technical tables

```mermaid
erDiagram
    USERS {
        bigint id PK
        string name
        string email UK
        string role
        string first_name
        string last_name
        string contact_number
        boolean is_active
    }

    ACCOMMODATIONS {
        bigint id PK
        string name
        string slug UK
        string type
        integer capacity
        numeric price_per_night
        string status
        string housekeeping_status
    }

    ACCOMMODATION_IMAGES {
        bigint id PK
        bigint accommodation_id FK
        string image_path
        boolean is_primary
        smallint sort_order
    }

    AMENITIES {
        bigint id PK
        string name UK
        string icon
    }

    RESERVATIONS {
        bigint id PK
        bigint user_id FK
        bigint accommodation_id FK
        date check_in
        date check_out
        integer guests
        numeric total_amount
        string status
        string booking_reference UK
        integer adults
        integer children
        integer infants
    }

    RESERVATION_PAYMENTS {
        bigint id PK
        bigint reservation_id FK
        string provider
        string payment_id UK
        string checkout_session_id UK
        bigint amount
        string currency
        string status
        timestamp paid_at
    }

    CHATBOT_CATEGORIES {
        bigint id PK
        string name
        string slug UK
        string icon
        integer sort_order
        boolean is_active
    }

    CHATBOT_RULES {
        bigint id PK
        bigint category_id FK
        string question
        text answer
        json keywords
        integer priority
        boolean is_active
        bigint created_by FK
        bigint updated_by FK
    }

    CHAT_CONVERSATIONS {
        bigint id PK
        uuid conversation_uuid UK
        string access_token UK
        bigint customer_id FK
        string guest_name
        string guest_email
        string status
        bigint assigned_to FK
        timestamp escalated_at
        timestamp resolved_at
        timestamp last_message_at
    }

    CHAT_MESSAGES {
        bigint id PK
        bigint conversation_id FK
        string sender_type
        bigint sender_user_id FK
        text message
        string message_type
        bigint chatbot_rule_id FK
        boolean is_read
    }

    INVENTORY_ASSETS {
        bigint id PK
        string name
        string category
        string asset_code UK
        integer quantity
        string condition
        string status
        string location_type
        bigint accommodation_id FK
    }

    HOUSEKEEPING_TASKS {
        bigint id PK
        bigint accommodation_id FK
        bigint assigned_to FK
        bigint created_by FK
        string task_type
        string priority
        string status
    }

    ATTENDANCE_RECORDS {
        bigint id PK
        bigint user_id FK
        date attendance_date
        string status
        string verification_method
        bigint corrected_by FK
    }

    ANNOUNCEMENTS {
        bigint id PK
        string title
        string type
        string audience
        string status
        bigint created_by FK
    }

    SYSTEM_SETTINGS {
        bigint id PK
        string key UK
        json value
    }

    AUDIT_LOGS {
        bigint id PK
        bigint user_id FK
        string action
        string module
        string description
        string entity_type
        bigint entity_id
    }

    USERS ||--o{ RESERVATIONS : makes
    ACCOMMODATIONS ||--o{ RESERVATIONS : booked_for
    RESERVATIONS ||--o{ RESERVATION_PAYMENTS : payment_history
    ACCOMMODATIONS ||--o{ ACCOMMODATION_IMAGES : has
    AMENITIES }o--o{ ACCOMMODATIONS : via_pivot
    ACCOMMODATIONS ||--o{ INVENTORY_ASSETS : contains
    ACCOMMODATIONS ||--o{ HOUSEKEEPING_TASKS : has
    USERS ||--o{ HOUSEKEEPING_TASKS : creates_or_handles
    USERS ||--o{ ATTENDANCE_RECORDS : records
    USERS ||--o{ ANNOUNCEMENTS : publishes
    USERS ||--o{ AUDIT_LOGS : performs
    CHATBOT_CATEGORIES ||--o{ CHATBOT_RULES : groups
    USERS ||--o{ CHATBOT_RULES : creates_or_updates
    USERS ||--o{ CHAT_CONVERSATIONS : customer_or_staff
    CHAT_CONVERSATIONS ||--o{ CHAT_MESSAGES : has
    USERS ||--o{ CHAT_MESSAGES : sender
    CHATBOT_RULES ||--o{ CHAT_MESSAGES : matched_rule
```

## Notes

- The current database includes the chatbot entities and they are part of the live schema.
- Reservation payment records are stored separately from reservations, so payment status is tracked through `reservation_payments`.
- Accommodation images are normalized into their own table, while the accommodation record still retains an optional legacy `image_path` column.
