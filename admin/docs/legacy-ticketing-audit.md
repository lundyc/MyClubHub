# Legacy Ticketing Dependency Audit

Phase 5.5 audit date: 2026-08-21.

## Summary

New ticketing activity no longer requires legacy commerce writes. New development must use `people`, `accounts`, `orders`, `order_items`, `payments`, `refunds`, `entitlements`, `season_passes`, `match_tickets`, `ticket_credentials`, `admissions`, and `scan_logs`.

Legacy tables remain in the database as read-only history and migration audit evidence. Do not drop them during normal feature work.

## season_ticket_orders

Operational writes: 0

Schema/migration writes: 2 token/manual-code backfills inside `ensureSeasonTicketSchema()` only.

Operational reads: legacy verification and compatibility lookups only.

Status: FROZEN.

The public/admin season-pass flow now creates and mutates shared orders through `createSeasonPassOrder()`, `cancelSeasonPassOrder()`, `refundSeasonPassOrder()`, and `markSeasonPassOrderPaid()`. The legacy helpers `saveSeasonTicketOrder()`, `deleteSeasonTicketOrder()`, `cancelSeasonTicketOrder()`, `attachSeasonTicketOrderStripeSession()`, and `markSeasonTicketOrderPaid()` now throw retirement exceptions.

## match_ticket_orders

Operational writes: 0

Operational reads: 0 for authority.

Compatibility reads: old `group_token` URLs resolve through `match_ticket_migration_map` to shared `orders`.

Status: FROZEN.

New match-ticket checkout does not insert or update this table.

## match_ticket_order_items

Operational writes: 0

Operational reads: 0 for authority.

Compatibility reads: migration/reconciliation only.

Status: FROZEN.

New package snapshots live in `order_items.metadata_json` and `order_items.description_snapshot`.

## match_tickets

Operational writes: 1 authoritative specialist-table insert for new match-ticket entitlements.

Compatibility writes: migration-only `entitlement_id` backfill for existing legacy rows.

Status: AUTHORITATIVE SPECIALIST TABLE WITH READ-ONLY LEGACY COLUMNS.

`match_tickets.entitlement_id`, `fixture_id`, `package_id`, `ticket_label`, and `admits_count` describe the match-ticket entitlement. `ticket_token`, `manual_code`, `checked_in_at`, and `checked_in_by_user_id` are historical compatibility columns and are not authoritative. Credentials live in `ticket_credentials`; admission state lives in `admissions`.

## season_ticket_holders

Operational writes: compatibility mirrors remain.

Remaining reasons:

- account/session compatibility mirrors in `account_auth.php` and `lib/accounts.php`;
- profile, position, relationship, and sponsorship mirrors in `lib/people.php`, `lib/member_sponsorship.php`, and `lib/season_tickets.php`;
- unsubscribe and renewal reminder fields still live on the legacy holder record.

Status: COMPATIBILITY ONLY, NOT AUTHORITATIVE.

This is the only legacy table with remaining normal writes. These writes are not required for new ticket commerce, credentials, admissions, or scan logs. They should be removed gradually as member/profile/supporting modules stop requiring old holder IDs.

## Attendance And Scan Logs

`season_ticket_attendance`: Operational writes: 0. Status: FROZEN.

`match_ticket_attendance`: Operational writes: 0. Status: FROZEN.

`season_ticket_scan_logs`: Operational writes: 0. Status: FROZEN.

`scan_logs` and `admissions` are authoritative for all new scan attempts and successful admissions.

## Current Static Write Counts

Normal-runtime legacy commerce writes:

- `season_ticket_orders`: 0
- `match_ticket_orders`: 0
- `match_ticket_order_items`: 0
- legacy attendance tables: 0
- legacy scan-log tables: 0

Remaining non-zero static matches are schema/migration backfills, holder compatibility mirrors, and the authoritative `match_tickets` specialist insert.
