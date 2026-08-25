# People & Users Cleanup

Created: 2026-08-21.

## Plain-English Model

`People & Users` is the master list.

Every real person should have one `people` row:

- season-ticket supporter;
- committee member;
- coaching staff;
- volunteer;
- site user.

A person only becomes a site user when they also have a Hub `account`.

Season tickets are not people. A season ticket is a package/pass attached to a person:

```text
person
  -> order
  -> order item
  -> entitlement
  -> season pass
  -> credential
```

Positions are club titles attached to people. Examples: Manager, Assistant Manager, Social Media Admin, Committee Member.

## Current Data Mismatch Found

The live database currently has:

- `people`: 28
- `accounts`: 1
- `season_passes`: 16
- legacy `season_ticket_holders`: 28

The latest supplied season-ticket list has 17 pass rows:

- Colin Thomson
- Eddie Smyth
- Alan Donachy
- WJ Connelly
- Micheal Donald
- Brian Eaglesham Adult
- Brian Eaglesham Concession
- Brian Eaglesham Wee Vics
- Jamesie Conlon
- Patrick Tomelty
- Ronnie Cree
- Ricky Hogarth
- Alan Mulholland
- Derek Frye
- Jim Stirling
- Alistair
- Geoff Barrett

The live season-pass data currently has these two rows that are not in that supplied list:

- Colin Lundy Adult, 0.00
- Ian Windross Adult, 0.00

The supplied list has these rows not currently present as season passes:

- Jim Stirling Adult, 60.00
- Alistair Adult, 60.00
- Geoff Barrett Adult, 60.00

Do not automatically delete or replace these without confirmation because that would change live ticket records.

## Suggested Cleanup

1. Rename the UI so the master directory is `People & Users`.
2. Treat the old holder table as `Legacy Ticket Customers`.
3. Add or update one person row for every supporter, staff member, volunteer, and committee member.
4. Add season passes only for the 17 supplied season-ticket package rows.
5. Create login accounts only for people who should be able to sign in.
6. Assign positions to staff, volunteers, and committee.
7. Archive people who have left, such as Derek Gemmell, rather than deleting their history.
