# DMD Resort Database Structure

- Database: `dmdresort`
- DBMS: PostgreSQL
- Schema: `public`

This document describes the live PostgreSQL catalog inspected on 2026-09-05. Defaults and types below are database values, not inferred from Laravel models.

## accommodation_amenity

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| accommodation_id | bigint | NO | — | PRIMARY KEY (composite); FK → accommodations.id |
| amenity_id | bigint | NO | — | PRIMARY KEY (composite); FK → amenities.id |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## accommodation_images

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('accommodation_images_id_seq'::regclass)` | PRIMARY KEY |
| accommodation_id | bigint | NO | — | FK → accommodations.id |
| image_path | character varying(2048) | NO | — | |
| is_primary | boolean | NO | `false` | |
| sort_order | smallint | NO | `'0'::smallint` | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## accommodations

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('accommodations_id_seq'::regclass)` | PRIMARY KEY |
| name | character varying(255) | NO | — | |
| slug | character varying(255) | NO | — | UNIQUE (`accommodations_slug_unique`) |
| type | character varying(40) | NO | — | |
| capacity | integer | NO | — | |
| price_per_night | numeric(10,2) | NO | — | |
| description | text | NO | — | |
| status | character varying(40) | NO | `'available'::character varying` | |
| image_path | character varying(255) | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |
| housekeeping_status | character varying(40) | NO | `'clean'::character varying` | |

## amenities

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('amenities_id_seq'::regclass)` | PRIMARY KEY |
| name | character varying(120) | NO | — | UNIQUE (`amenities_name_unique`) |
| icon | character varying(80) | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## announcements

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('announcements_id_seq'::regclass)` | PRIMARY KEY |
| title | character varying(180) | NO | — | |
| content | text | NO | — | |
| type | character varying(40) | NO | — | |
| audience | character varying(40) | NO | — | |
| status | character varying(40) | NO | `'draft'::character varying` | |
| publish_at | timestamp without time zone | YES | — | |
| expires_at | timestamp without time zone | YES | — | |
| created_by | bigint | NO | — | FK → users.id |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## attendance_record_histories

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('attendance_record_histories_id_seq'::regclass)` | PRIMARY KEY |
| attendance_record_id | bigint | NO | — | FK → attendance_records.id |
| performed_by | bigint | YES | — | FK → users.id |
| action | character varying(60) | NO | — | |
| old_value | json | YES | — | |
| new_value | json | YES | — | |
| remarks | text | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## attendance_records

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('attendance_records_id_seq'::regclass)` | PRIMARY KEY |
| user_id | bigint | YES | — | FK → users.id |
| attendance_date | date | NO | — | |
| time_in | time without time zone | YES | — | |
| time_out | time without time zone | YES | — | |
| status | character varying(40) | NO | — | |
| verification_method | character varying(40) | NO | `'manual'::character varying` | |
| device_id | character varying(120) | YES | — | |
| remarks | text | YES | — | |
| corrected_by | bigint | YES | — | FK → users.id |
| corrected_at | timestamp without time zone | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |
| employee_id | bigint | YES | — | FK → employees.id |

Unique constraints: `attendance_employee_date_unique` (`employee_id`, `attendance_date`); `attendance_records_user_id_attendance_date_unique` (`user_id`, `attendance_date`).

## audit_logs

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('audit_logs_id_seq'::regclass)` | PRIMARY KEY |
| user_id | bigint | YES | — | FK → users.id |
| action | character varying(80) | NO | — | |
| module | character varying(80) | NO | — | |
| description | character varying(500) | NO | — | |
| entity_type | character varying(160) | YES | — | |
| entity_id | bigint | YES | — | |
| ip_address | character varying(64) | YES | — | |
| request_method | character varying(12) | YES | — | |
| metadata | json | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## cache

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| key | character varying(255) | NO | — | PRIMARY KEY |
| value | text | NO | — | |
| expiration | integer | NO | — | |

## cache_locks

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| key | character varying(255) | NO | — | PRIMARY KEY |
| owner | character varying(255) | NO | — | |
| expiration | integer | NO | — | |

## chat_conversations

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('chat_conversations_id_seq'::regclass)` | PRIMARY KEY |
| conversation_uuid | uuid | NO | — | UNIQUE (`chat_conversations_conversation_uuid_unique`) |
| access_token | character varying(80) | NO | — | UNIQUE (`chat_conversations_access_token_unique`) |
| customer_id | bigint | YES | — | FK → users.id |
| guest_name | character varying(255) | YES | — | |
| guest_email | character varying(255) | YES | — | |
| status | character varying(255) | NO | `'bot'::character varying` | |
| assigned_to | bigint | YES | — | FK → users.id |
| escalated_at | timestamp without time zone | YES | — | |
| resolved_at | timestamp without time zone | YES | — | |
| last_message_at | timestamp without time zone | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |
| context | json | YES | — | |

## chat_messages

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('chat_messages_id_seq'::regclass)` | PRIMARY KEY |
| conversation_id | bigint | NO | — | FK → chat_conversations.id |
| sender_type | character varying(255) | NO | — | |
| sender_user_id | bigint | YES | — | FK → users.id |
| message | text | NO | — | |
| message_type | character varying(255) | NO | `'text'::character varying` | |
| chatbot_rule_id | bigint | YES | — | FK → chatbot_rules.id |
| is_read | boolean | NO | `false` | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## chatbot_categories

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('chatbot_categories_id_seq'::regclass)` | PRIMARY KEY |
| name | character varying(255) | NO | — | |
| slug | character varying(255) | NO | — | UNIQUE (`chatbot_categories_slug_unique`) |
| icon | character varying(255) | YES | — | |
| sort_order | integer | NO | `0` | |
| is_active | boolean | NO | `true` | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## chatbot_rules

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('chatbot_rules_id_seq'::regclass)` | PRIMARY KEY |
| category_id | bigint | NO | — | FK → chatbot_categories.id |
| question | character varying(255) | NO | — | |
| answer | text | NO | — | |
| keywords | json | NO | — | |
| priority | integer | NO | `0` | |
| is_active | boolean | NO | `true` | |
| created_by | bigint | YES | — | FK → users.id |
| updated_by | bigint | YES | — | FK → users.id |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## employee_code_sequences

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('employee_code_sequences_id_seq'::regclass)` | PRIMARY KEY |
| prefix | character varying(12) | NO | — | UNIQUE (`employee_code_sequences_prefix_unique`) |
| last_number | integer | NO | `0` | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## employees

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('employees_id_seq'::regclass)` | PRIMARY KEY |
| employee_code | character varying(20) | NO | — | UNIQUE (`employees_employee_code_unique`) |
| user_id | bigint | YES | — | FK → users.id; UNIQUE (`employees_user_id_unique`) |
| first_name | character varying(80) | NO | — | |
| last_name | character varying(80) | NO | — | |
| position | character varying(120) | NO | — | |
| phone | character varying(30) | YES | — | |
| date_hired | date | YES | — | |
| status | character varying(30) | NO | `'active'::character varying` | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |
| fingerprint_id | integer | YES | — | UNIQUE (`employees_fingerprint_id_unique`) |

## failed_jobs

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('failed_jobs_id_seq'::regclass)` | PRIMARY KEY |
| uuid | character varying(255) | NO | — | UNIQUE (`failed_jobs_uuid_unique`) |
| connection | text | NO | — | |
| queue | text | NO | — | |
| payload | text | NO | — | |
| exception | text | NO | — | |
| failed_at | timestamp without time zone | NO | `CURRENT_TIMESTAMP` | |

## housekeeping_task_histories

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('housekeeping_task_histories_id_seq'::regclass)` | PRIMARY KEY |
| housekeeping_task_id | bigint | NO | — | FK → housekeeping_tasks.id |
| performed_by | bigint | YES | — | FK → users.id |
| action | character varying(60) | NO | — | |
| old_value | json | YES | — | |
| new_value | json | YES | — | |
| remarks | text | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## housekeeping_tasks

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('housekeeping_tasks_id_seq'::regclass)` | PRIMARY KEY |
| accommodation_id | bigint | NO | — | FK → accommodations.id |
| assigned_to | bigint | YES | — | FK → users.id |
| created_by | bigint | NO | — | FK → users.id |
| task_type | character varying(40) | NO | — | |
| priority | character varying(40) | NO | `'normal'::character varying` | |
| status | character varying(40) | NO | `'pending'::character varying` | |
| scheduled_at | timestamp without time zone | YES | — | |
| started_at | timestamp without time zone | YES | — | |
| completed_at | timestamp without time zone | YES | — | |
| remarks | text | YES | — | |
| maintenance_notes | text | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |
| assigned_staff_name | character varying(120) | YES | — | |

## inventory_asset_code_sequences

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('inventory_asset_code_sequences_id_seq'::regclass)` | PRIMARY KEY |
| prefix | character varying(12) | NO | — | UNIQUE (`inventory_asset_code_sequences_prefix_unique`) |
| last_number | integer | NO | `0` | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## inventory_asset_histories

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('inventory_asset_histories_id_seq'::regclass)` | PRIMARY KEY |
| inventory_asset_id | bigint | NO | — | FK → inventory_assets.id |
| performed_by | bigint | YES | — | FK → users.id |
| action | character varying(60) | NO | — | |
| old_value | json | YES | — | |
| new_value | json | YES | — | |
| remarks | text | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## inventory_asset_locations

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('inventory_asset_locations_id_seq'::regclass)` | PRIMARY KEY |
| inventory_asset_id | bigint | NO | — | FK → inventory_assets.id |
| location_signature | character varying(180) | NO | — | UNIQUE (`inventory_asset_locations_inventory_asset_id_location_signature`) |
| location_type | character varying(40) | NO | — | |
| accommodation_id | bigint | YES | — | FK → accommodations.id |
| location_name | character varying(160) | YES | — | |
| quantity | integer | NO | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

Unique constraint: `inventory_asset_locations_inventory_asset_id_location_signature` (`inventory_asset_id`, `location_signature`).

## inventory_assets

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('inventory_assets_id_seq'::regclass)` | PRIMARY KEY |
| name | character varying(160) | NO | — | |
| category | character varying(80) | NO | — | |
| asset_code | character varying(80) | NO | — | UNIQUE (`inventory_assets_asset_code_unique`) |
| quantity | integer | NO | `1` | |
| condition | character varying(40) | NO | — | |
| status | character varying(40) | NO | — | |
| location_type | character varying(40) | NO | — | |
| accommodation_id | bigint | YES | — | FK → accommodations.id |
| location_name | character varying(160) | YES | — | |
| acquisition_date | date | YES | — | |
| purchase_cost | numeric(12,2) | YES | — | |
| description | text | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## job_batches

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | character varying(255) | NO | — | PRIMARY KEY |
| name | character varying(255) | NO | — | |
| total_jobs | integer | NO | — | |
| pending_jobs | integer | NO | — | |
| failed_jobs | integer | NO | — | |
| failed_job_ids | text | NO | — | |
| options | text | YES | — | |
| cancelled_at | integer | YES | — | |
| created_at | integer | NO | — | |
| finished_at | integer | YES | — | |

## jobs

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('jobs_id_seq'::regclass)` | PRIMARY KEY |
| queue | character varying(255) | NO | — | |
| payload | text | NO | — | |
| attempts | smallint | NO | — | |
| reserved_at | integer | YES | — | |
| available_at | integer | NO | — | |
| created_at | integer | NO | — | |

## migrations

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | integer | NO | `nextval('migrations_id_seq'::regclass)` | PRIMARY KEY |
| migration | character varying(255) | NO | — | |
| batch | integer | NO | — | |

## password_reset_tokens

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| email | character varying(255) | NO | — | PRIMARY KEY |
| token | character varying(255) | NO | — | |
| created_at | timestamp without time zone | YES | — | |

## paymongo_webhook_events

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('paymongo_webhook_events_id_seq'::regclass)` | PRIMARY KEY |
| event_id | character varying(120) | NO | — | UNIQUE (`paymongo_webhook_events_event_id_unique`) |
| event_type | character varying(120) | NO | — | |
| resource_id | character varying(120) | YES | — | |
| payload | json | NO | — | |
| processed_at | timestamp without time zone | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## personal_access_tokens

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('personal_access_tokens_id_seq'::regclass)` | PRIMARY KEY |
| tokenable_type | character varying(255) | NO | — | |
| tokenable_id | bigint | NO | — | |
| name | text | NO | — | |
| token | character varying(64) | NO | — | UNIQUE (`personal_access_tokens_token_unique`) |
| abilities | text | YES | — | |
| last_used_at | timestamp without time zone | YES | — | |
| expires_at | timestamp without time zone | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## reservation_payments

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('reservation_payments_id_seq'::regclass)` | PRIMARY KEY |
| reservation_id | bigint | NO | — | FK → reservations.id |
| provider | character varying(40) | NO | — | |
| provider_reference | character varying(120) | YES | — | |
| checkout_session_id | character varying(120) | YES | — | UNIQUE (`reservation_payments_checkout_session_id_unique`) |
| checkout_url | text | YES | — | |
| payment_id | character varying(120) | YES | — | UNIQUE (`reservation_payments_payment_id_unique`) |
| amount | bigint | NO | — | |
| currency | character varying(3) | NO | `'PHP'::character varying` | |
| status | character varying(40) | NO | `'pending'::character varying` | |
| payment_method | character varying(40) | YES | — | |
| paid_at | timestamp without time zone | YES | — | |
| raw_reference | character varying(120) | YES | — | |
| payload | json | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## reservations

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('reservations_id_seq'::regclass)` | PRIMARY KEY |
| user_id | bigint | NO | — | FK → users.id |
| accommodation_id | bigint | NO | — | FK → accommodations.id |
| check_in | date | NO | — | |
| check_out | date | NO | — | |
| guests | integer | NO | — | |
| total_amount | numeric(10,2) | NO | — | |
| status | character varying(40) | NO | `'pending'::character varying` | |
| booking_reference | character varying(32) | NO | — | UNIQUE (`reservations_booking_reference_unique`) |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |
| cancellation_reason | text | YES | — | |
| cancelled_at | timestamp without time zone | YES | — | |
| adults | integer | NO | `1` | |
| children | integer | NO | `0` | |
| infants | integer | NO | `0` | |
| preferred_arrival_time | time without time zone | YES | — | |
| expected_arrival_time | time without time zone | YES | — | |
| expected_departure_time | time without time zone | YES | — | |
| check_in_at | timestamp with time zone | YES | — | |
| check_out_at | timestamp with time zone | YES | — | |
| stay_days | integer | YES | — | |
| cottage_period_type | character varying(20) | YES | — | |
| cottage_period_count | integer | YES | — | |

## sessions

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | character varying(255) | NO | — | PRIMARY KEY |
| user_id | bigint | YES | — | |
| ip_address | character varying(45) | YES | — | |
| user_agent | text | YES | — | |
| payload | text | NO | — | |
| last_activity | integer | NO | — | |

## system_settings

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('system_settings_id_seq'::regclass)` | PRIMARY KEY |
| key | character varying(255) | NO | — | UNIQUE (`system_settings_key_unique`) |
| value | json | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |

## users

| Column | Type | Nullable | Default | Key / Reference |
| --- | --- | --- | --- | --- |
| id | bigint | NO | `nextval('users_id_seq'::regclass)` | PRIMARY KEY |
| name | character varying(255) | NO | — | |
| email | character varying(255) | NO | — | UNIQUE (`users_email_unique`) |
| email_verified_at | timestamp without time zone | YES | — | |
| password | character varying(255) | NO | — | |
| remember_token | character varying(100) | YES | — | |
| created_at | timestamp without time zone | YES | — | |
| updated_at | timestamp without time zone | YES | — | |
| role | character varying(40) | NO | `'guest'::character varying` | |
| first_name | character varying(80) | YES | — | |
| last_name | character varying(80) | YES | — | |
| contact_number | character varying(30) | YES | — | |
| is_active | boolean | NO | `true` | |
| email_verification_code_hash | character varying(255) | YES | — | |
| email_verification_expires_at | timestamp without time zone | YES | — | |
| email_verification_sent_at | timestamp without time zone | YES | — | |
| google_id | character varying(255) | YES | — | UNIQUE (`users_google_id_unique`) |

## Table Relationships

| Referenced column | Relationship | Foreign-key column |
| --- | --- | --- |
| accommodations.id | → | accommodation_images.accommodation_id |
| accommodations.id | → | accommodation_amenity.accommodation_id |
| amenities.id | → | accommodation_amenity.amenity_id |
| users.id | → | announcements.created_by |
| attendance_records.id | → | attendance_record_histories.attendance_record_id |
| users.id | → | attendance_record_histories.performed_by |
| users.id | → | attendance_records.user_id |
| users.id | → | attendance_records.corrected_by |
| employees.id | → | attendance_records.employee_id |
| users.id | → | audit_logs.user_id |
| users.id | → | chat_conversations.customer_id |
| users.id | → | chat_conversations.assigned_to |
| chat_conversations.id | → | chat_messages.conversation_id |
| users.id | → | chat_messages.sender_user_id |
| chatbot_rules.id | → | chat_messages.chatbot_rule_id |
| chatbot_categories.id | → | chatbot_rules.category_id |
| users.id | → | chatbot_rules.created_by |
| users.id | → | chatbot_rules.updated_by |
| users.id | → | employees.user_id |
| housekeeping_tasks.id | → | housekeeping_task_histories.housekeeping_task_id |
| users.id | → | housekeeping_task_histories.performed_by |
| accommodations.id | → | housekeeping_tasks.accommodation_id |
| users.id | → | housekeeping_tasks.assigned_to |
| users.id | → | housekeeping_tasks.created_by |
| inventory_assets.id | → | inventory_asset_histories.inventory_asset_id |
| users.id | → | inventory_asset_histories.performed_by |
| inventory_assets.id | → | inventory_asset_locations.inventory_asset_id |
| accommodations.id | → | inventory_asset_locations.accommodation_id |
| accommodations.id | → | inventory_assets.accommodation_id |
| reservations.id | → | reservation_payments.reservation_id |
| users.id | → | reservations.user_id |
| accommodations.id | → | reservations.accommodation_id |

## Database Summary

- Total tables: **34**
- Total columns: **318**
- Tables without primary keys: **0**
- Tables containing foreign keys: **17**
