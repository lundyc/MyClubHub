# Ticketing Architecture

This is the post-migration ticketing model. Do not redesign the foundation again for normal feature work.

## Authoritative Model

Identity:

- `people`
- `accounts`
- `roles`, `permissions`, `account_roles`, `role_permissions`, `account_permissions`
- `person_positions`, `person_relationships`

Commerce:

- `orders`
- `order_items`
- `payments`
- `refunds`

Ticket rights:

- `entitlements`
- `season_passes`
- `match_tickets`
- `ticket_credentials`

Gate activity:

- `admissions`
- `scan_logs`

New features must use these tables. The retired legacy commerce and attendance tables are not authoritative.

## Match Tickets

New match-ticket checkout creates only:

- one shared `orders` row;
- shared `order_items` rows with package snapshots and fixture/package metadata;
- one `payments` row;
- one `entitlements` row per issued ticket;
- one `match_tickets` specialist row per entitlement;
- one `ticket_credentials` row per entitlement;
- one `order_access_tokens` row for public bearer access.

`match_tickets` remains the specialist table because it now maps cleanly to entitlement, fixture, package, label, and admits count. Its old token/manual/check-in columns are historical compatibility columns only. Credential state belongs to `ticket_credentials`; admission state belongs to `admissions`.

## Public Ticket Links

New public match-ticket order links use:

```text
ticket_order.php?access=<opaque token>
```

`order_access_tokens` maps the token to `orders.id` with purpose `public_ticket_order`. The token is the bearer secret; sequential order IDs are not sufficient public authentication.

Old links continue to work:

```text
ticket_order.php?group=<legacy group_token>
```

The legacy group token resolves through `match_ticket_orders` and `match_ticket_migration_map` to the shared order, then renders from shared order items, entitlements, match tickets, credentials, admissions, and scan logs.

Individual ticket URLs resolve via `ticket_credentials.token` or manual code. Old credential values were migrated into `ticket_credentials`.

## Season Passes

New season-pass purchases use:

```text
person/account
  -> orders
  -> order_items
  -> payments
  -> entitlements
  -> season_passes
  -> ticket_credentials
```

Admin cancel/refund/payment operations mutate shared season-pass orders only. Legacy `season_ticket_orders` helper mutations are retired and throw if called.

## Admissions

`recordAdmission()` is the shared scanner entry point. Successful admissions write `admissions`; every scan attempt writes `scan_logs`.

Attendance reporting must sum `admissions.quantity`. Legacy attendance and scan-log tables are historical only.

## Service Ownership

`lib/season_passes.php` owns season-pass creation, payment state, refund state, and credential retrieval.

`lib/match_tickets.php` owns match-ticket creation, public access tokens, legacy URL compatibility, email links, Stripe state, refund/cancel state, and match-ticket credential lookup.

`lib/admissions.php` owns credential lookup, scan logging, direct POS/complimentary admissions, and the generic admission service.

`lib/season_pass_rules.php` owns season-pass eligibility defaults and fixture overrides.

`lib/ticketing_reporting.php` owns fixture attendance and revenue summaries.

`lib/pos.php` owns POS sales and creates `pos_admission_links` for gate-admission sale items.

## Legacy Tables

Retained as read-only archive/migration evidence:

- `season_ticket_orders`
- `match_ticket_orders`
- `match_ticket_order_items`
- historical attendance tables
- historical scan-log tables

`season_ticket_holders` is compatibility-only until remaining member/profile/supporting modules stop needing old holder IDs.

Do not drop legacy production tables as part of ordinary feature work. Keeping them preserves audit history and old links while costing almost nothing.
