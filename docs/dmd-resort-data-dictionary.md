# DMD Resort Data Dictionary

This dictionary covers the actual business tables currently present in the live PostgreSQL schema `dmdresort`.

Excluded from this dictionary:
- framework tables (`cache`, `jobs`, `sessions`, etc.)

---

## users

- **Purpose:** Stores resort users and staff accounts, including guest/customer accounts.
- **Primary key:** `id`
- **Foreign keys:** none
- **Important columns:** `name`, `email`, `email_verified_at`, `password`, `remember_token`, `role`, `first_name`, `last_name`, `contact_number`, `is_active`, `created_at`, `updated_at`
- **Relationships:**
  - 1:M to `reservations`
  - 1:M to `attendance_records`
  - 1:M to `housekeeping_tasks` via `created_by` / `assigned_to`
  - 1:M to `inventory_asset_histories` via `performed_by`
  - 1:M to `attendance_record_histories` via `performed_by`
  - 1:M to `housekeeping_task_histories` via `performed_by`
  - 1:M to `announcements`
  - 1:M to `audit_logs`

## accommodations

- **Purpose:** Stores the resort's rentable units such as rooms, cottages, and function hall.
- **Primary key:** `id`
- **Foreign keys:** none
- **Important columns:** `name`, `slug`, `type`, `capacity`, `price_per_night`, `description`, `status`, `image_path`, `housekeeping_status`, `created_at`, `updated_at`
- **Relationships:**
  - 1:M to `accommodation_images`
  - 1:M to `reservations`
  - 1:M to `inventory_assets`
  - 1:M to `housekeeping_tasks`
  - M:N with `amenities` through `accommodation_amenity`

## accommodation_images

- **Purpose:** Normalized gallery images for accommodations.
- **Primary key:** `id`
- **Foreign keys:** `accommodation_id` -> `accommodations.id`
- **Important columns:** `image_path`, `is_primary`, `sort_order`, `created_at`, `updated_at`
- **Relationships:**
  - Each image belongs to one accommodation

## amenities

- **Purpose:** Master list of accommodation amenities.
- **Primary key:** `id`
- **Foreign keys:** none
- **Important columns:** `name`, `icon`, `created_at`, `updated_at`
- **Relationships:**
  - M:N with `accommodations` through `accommodation_amenity`

## accommodation_amenity

- **Purpose:** Pivot table linking accommodations and amenities.
- **Primary key:** Composite (`accommodation_id`, `amenity_id`)
- **Foreign keys:** `accommodation_id` -> `accommodations.id`, `amenity_id` -> `amenities.id`
- **Important columns:** `created_at`, `updated_at`
- **Relationships:**
  - Resolves the many-to-many relationship between accommodations and amenities

## reservations

- **Purpose:** Guest booking records for accommodations.
- **Primary key:** `id`
- **Foreign keys:** `user_id` -> `users.id`, `accommodation_id` -> `accommodations.id`
- **Important columns:** `check_in`, `check_out`, `guests`, `total_amount`, `status`, `booking_reference`, `cancellation_reason`, `cancelled_at`, `adults`, `children`, `infants`, `created_at`, `updated_at`
- **Relationships:**
  - M:1 with `users`
  - M:1 with `accommodations`
  - 1:M with `reservation_payments` at database level

## reservation_payments

- **Purpose:** Stores payment attempts and verified payment data for reservations.
- **Primary key:** `id`
- **Foreign keys:** `reservation_id` -> `reservations.id`
- **Important columns:** `provider`, `provider_reference`, `checkout_session_id`, `checkout_url`, `payment_id`, `amount`, `currency`, `status`, `payment_method`, `paid_at`, `raw_reference`, `payload`, `created_at`, `updated_at`
- **Relationships:**
  - Each payment record belongs to one reservation
  - Database allows multiple payment records per reservation, though the model exposes the latest record

## paymongo_webhook_events

- **Purpose:** Idempotency and audit trail for PayMongo webhook events.
- **Primary key:** `id`
- **Foreign keys:** none
- **Important columns:** `event_id`, `event_type`, `resource_id`, `payload`, `processed_at`, `created_at`, `updated_at`
- **Relationships:**
  - Standalone technical/business-support table

## chatbot_categories

- **Purpose:** Groups chatbot rules into user-facing support categories.
- **Primary key:** `id`
- **Foreign keys:** none
- **Important columns:** `name`, `slug`, `icon`, `sort_order`, `is_active`, `created_at`, `updated_at`
- **Relationships:**
  - 1:M with `chatbot_rules`

## chatbot_rules

- **Purpose:** Deterministic FAQ/chatbot rules with keywords and priority.
- **Primary key:** `id`
- **Foreign keys:** `category_id` -> `chatbot_categories.id`, `created_by` -> `users.id` (nullable), `updated_by` -> `users.id` (nullable)
- **Important columns:** `question`, `answer`, `keywords`, `priority`, `is_active`, `created_at`, `updated_at`
- **Relationships:**
  - Many rules belong to one category
  - Each rule may be created/updated by one user
  - Each rule may be referenced by many chat messages

## chat_conversations

- **Purpose:** Tracks public or logged-in guest support conversations.
- **Primary key:** `id`
- **Foreign keys:** `customer_id` -> `users.id` (nullable), `assigned_to` -> `users.id` (nullable)
- **Important columns:** `conversation_uuid`, `access_token`, `guest_name`, `guest_email`, `status`, `escalated_at`, `resolved_at`, `last_message_at`, `created_at`, `updated_at`
- **Relationships:**
  - Each conversation may belong to one customer
  - Each conversation may be assigned to one staff user
  - Each conversation has many messages

## chat_messages

- **Purpose:** Message log for chatbot and human support conversations.
- **Primary key:** `id`
- **Foreign keys:** `conversation_id` -> `chat_conversations.id`, `sender_user_id` -> `users.id` (nullable), `chatbot_rule_id` -> `chatbot_rules.id` (nullable)
- **Important columns:** `sender_type`, `message`, `message_type`, `is_read`, `created_at`, `updated_at`
- **Relationships:**
  - Each message belongs to one conversation
  - Each message may be associated with one sender user
  - Each bot message may be linked to one matched chatbot rule

## inventory_assets

- **Purpose:** Inventory items and equipment used across the resort.
- **Primary key:** `id`
- **Foreign keys:** `accommodation_id` -> `accommodations.id` (nullable)
- **Important columns:** `name`, `category`, `asset_code`, `quantity`, `condition`, `status`, `location_type`, `location_name`, `acquisition_date`, `purchase_cost`, `description`, `created_at`, `updated_at`
- **Relationships:**
  - Many assets may belong to one accommodation
  - Each asset has many `inventory_asset_histories`

## inventory_asset_histories

- **Purpose:** Change log for inventory assets.
- **Primary key:** `id`
- **Foreign keys:** `inventory_asset_id` -> `inventory_assets.id`, `performed_by` -> `users.id` (nullable)
- **Important columns:** `action`, `old_value`, `new_value`, `remarks`, `created_at`, `updated_at`
- **Relationships:**
  - Each history row belongs to one inventory asset
  - Each history row may be associated with a user who performed the action

## housekeeping_tasks

- **Purpose:** Cleaning, inspection, preparation, and maintenance tasks for accommodations.
- **Primary key:** `id`
- **Foreign keys:** `accommodation_id` -> `accommodations.id`, `assigned_to` -> `users.id` (nullable), `created_by` -> `users.id`
- **Important columns:** `task_type`, `priority`, `status`, `scheduled_at`, `started_at`, `completed_at`, `remarks`, `maintenance_notes`, `created_at`, `updated_at`
- **Relationships:**
  - Each task belongs to one accommodation
  - Each task can be assigned to one user
  - Each task can have many history records

## housekeeping_task_histories

- **Purpose:** Audit trail for housekeeping task changes.
- **Primary key:** `id`
- **Foreign keys:** `housekeeping_task_id` -> `housekeeping_tasks.id`, `performed_by` -> `users.id` (nullable)
- **Important columns:** `action`, `old_value`, `new_value`, `remarks`, `created_at`, `updated_at`
- **Relationships:**
  - Each history row belongs to one housekeeping task
  - Each history row may be associated with a user who performed the action

## attendance_records

- **Purpose:** Staff attendance tracking.
- **Primary key:** `id`
- **Foreign keys:** `user_id` -> `users.id`, `corrected_by` -> `users.id` (nullable)
- **Important columns:** `attendance_date`, `time_in`, `time_out`, `status`, `verification_method`, `device_id`, `remarks`, `corrected_at`, `created_at`, `updated_at`
- **Relationships:**
  - Each attendance record belongs to one staff user
  - Each record can have many correction/history entries
  - Unique constraint on (`user_id`, `attendance_date`)

## attendance_record_histories

- **Purpose:** Audit trail for attendance changes and corrections.
- **Primary key:** `id`
- **Foreign keys:** `attendance_record_id` -> `attendance_records.id`, `performed_by` -> `users.id` (nullable)
- **Important columns:** `action`, `old_value`, `new_value`, `remarks`, `created_at`, `updated_at`
- **Relationships:**
  - Each history row belongs to one attendance record

## announcements

- **Purpose:** Resort notices, advisories, promotions, and staff communications.
- **Primary key:** `id`
- **Foreign keys:** `created_by` -> `users.id`
- **Important columns:** `title`, `content`, `type`, `audience`, `status`, `publish_at`, `expires_at`, `created_at`, `updated_at`
- **Relationships:**
  - Each announcement is created by one user

## audit_logs

- **Purpose:** Generic audit trail for important system actions.
- **Primary key:** `id`
- **Foreign keys:** `user_id` -> `users.id` (nullable)
- **Important columns:** `action`, `module`, `description`, `entity_type`, `entity_id`, `ip_address`, `request_method`, `metadata`, `created_at`, `updated_at`
- **Relationships:**
  - Each log may belong to one actor user
  - `entity_type` / `entity_id` are polymorphic reference fields and do not have a database foreign key

## system_settings

- **Purpose:** Centralized key/value storage for resort configuration.
- **Primary key:** `id`
- **Foreign keys:** none
- **Important columns:** `key`, `value`, `created_at`, `updated_at`
- **Relationships:**
  - Standalone configuration table
  - Stores public website, booking, branding, and other system settings in JSON

---

## Current migration discrepancy

No migration discrepancy remains for the chatbot tables. They are now present in the live PostgreSQL schema and included in the ERD and data dictionary.
