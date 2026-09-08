# Legacy Database Cleanup

Date: 2026-08-21.

## Removed From Normal UI

- `season_ticket_holders.php` now redirects to `People & Users`.
- `season_ticket_holder.php` now redirects to the mapped person record.
- The Ticketing navigation no longer shows a legacy customers/holders section.
- Dashboard and catalogue links now point to `Season Passes` or `People & Users`.

## Physical Tables Not Dropped Yet

Do not drop these tables until their listed dependencies are migrated. They still have live foreign keys or compatibility URL requirements.

### `season_ticket_holders`

Current rows: 28.

Still referenced by:

- `identity_migration_map.old_holder_id`
- `feedback.holder_id` for old rows only; new feedback writes use `feedback.person_id`
- `hidden_team_teams.holder_id` for old rows only; new claims use `hidden_team_teams.person_id`
- `member_points_ledger.holder_id` for old rows only; new points writes use `member_points_ledger.person_id`
- `motm_votes.holder_id` for old rows only; new votes use `motm_votes.person_id`
- `venue_reviews.holder_id` for old rows only; new reviews use `venue_reviews.person_id`
- `season_ticket_free_signup_codes.used_by_holder_id` for old redemptions only; new redemptions use `used_by_person_id`
- old attendance/log/archive tables
- old match/season order archive tables

Cleanup required before dropping:

- remove the account/member compatibility dependency on old holder IDs;
- replace `identity_migration_map.old_holder_id` with non-FK archive metadata or remove it after audit.

### `season_ticket_orders`

Current rows: 16.

Still referenced by:

- `season_ticket_migration_map.legacy_order_id`
- `season_passes.legacy_season_ticket_order_id`
- old attendance/log archive tables
- migration/reconciliation helpers

Cleanup required before dropping:

- decide whether legacy season-ticket order numbers/tokens are still needed for audit;
- remove old attendance/log foreign keys or archive those rows outside the relational graph;
- remove migration reconciliation code that compares against the old table.

### `match_ticket_orders`

Current rows: 1.

Still referenced by:

- `match_ticket_migration_map.legacy_order_id`
- old `match_tickets.order_id` compatibility FK
- `match_ticket_order_items.order_id`
- `match_ticket_attendance.order_id`
- old `group_token` URL compatibility until all group tokens are copied into shared access-token metadata.

Cleanup required before dropping:

- copy legacy group tokens into shared compatibility columns;
- remove old `match_tickets.order_id` and `order_item_id` foreign keys;
- remove migration/reconciliation code that joins to the old order table.

### `match_ticket_order_items`

Current rows: 2.

Still referenced by old `match_tickets.order_item_id`.

Cleanup required before dropping:

- remove the old `match_tickets.order_item_id` foreign key/column dependency;
- rely solely on `entitlements.order_item_id`.

### Old Attendance And Scan Tables

Current rows:

- `match_ticket_attendance`: 0
- `season_ticket_attendance`: 1
- `season_ticket_scan_logs`: 7

Cleanup required before dropping:

- confirm the copied rows in `admissions` and `scan_logs`;
- remove archive/reconciliation readers;
- drop dependent foreign keys first.

## Current Safe Position

The legacy ticketing tables are no longer normal operational UI. Match-ticket and season-pass order creation now use the shared order/entitlement model, and member feature writes have been moved to `people.id`.

Physical drops should still wait because the old tables remain useful as the migration audit trail and still carry compatibility/history foreign keys. The remaining work is not architectural redesign; it is ordinary archive-retirement work:

1. Remove account/member compatibility reliance on `identity_migration_map.old_holder_id`.
2. Decide how long old ticket URL/token compatibility must remain.
3. Drop old attendance/order FKs only after confirming no admin report or old public link still needs them.
