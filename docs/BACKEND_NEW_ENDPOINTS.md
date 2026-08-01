# Endpoints to create

Four new endpoints. Nothing here changes an existing route or an existing
payload — every one is additive, so no client breaks.

This file is the **build list**: route, parameters, response, rules. The
reasoning, the cost figures and the curl acceptance tests live in
`BACKEND_SPEC.md`, which also covers the tickets that are *not* creations
(field additions, a contract to document, cache headers, two figures to
reconcile).

| # | Endpoint | Replaces | Effort |
|---|---|---|---|
| T4 | `GET /consultant/shops/material-complaints` | N calls to `/shops/{id}/material-complaints` | M |
| T5b | `GET /consultant/shops/pnl/monthly` | N calls to `/consultant/shops/{id}/pnl/monthly` | M |
| T6 | `POST /consultant/shops/{shopId}/task-reviews/{reviewId}/validate` | nothing — new capability | M |
| T9 | `GET /consultant/shops/{shopId}/checklists/progress` | ~50 calls per monthly report | M |

**Two rules apply to all three GET endpoints**, and they are the ones that
usually get missed:

- **Every requested entity appears**, even empty. A shop with no complaints and
  a shop missing from the response are different facts; a day with no checklist
  and a day we failed to fetch are different facts. The panel has to tell them
  apart, and an absent row reads as "no data", not as "nothing happened".
- **Object shapes are unchanged.** Reuse the exact objects the existing
  single-entity endpoints return. We already parse those forms, and the
  single-entity endpoints stay in use elsewhere.

---

## T4 — Material complaints, all shops

```
GET /consultant/shops/material-complaints
GET /consultant/shops/material-complaints?status=OPEN
GET /consultant/shops/material-complaints?from=2026-07-01&to=2026-07-31
```

All parameters optional. Without them: everything the single-shop endpoint would
return, for every shop the consultant may see.

| Parameter | Type | Meaning |
|---|---|---|
| `status` | string | same values as today (`OPEN`, `CLOSED`, …) |
| `from` | date `Y-m-d` | `reported_at` ≥ this date |
| `to` | date `Y-m-d` | `reported_at` ≤ this date |

```json
{
  "shops": [
    {
      "shop_id": 1,
      "complaints": [
        { "id": 10, "reported_at": "2026-07-28 08:12:00", "status": "OPEN" }
      ]
    },
    { "shop_id": 2, "complaints": [] }
  ]
}
```

- Envelope identical to `/consultant/shops/monthly-sales`.
- Complaint objects identical to `GET /shops/{shopId}/material-complaints`.
- A filter narrows the result; it must never widen it.

---

## T5b — Monthly P&L, all shops

```
GET /consultant/shops/pnl/monthly?from=2025-08&to=2026-07
```

`from` and `to` are months (`YYYY-MM`), inclusive.

```json
{
  "shops": [
    {
      "shop_id": 1,
      "months": [
        { "month": "2025-08", "turnover": 52000, "material": 18000,
          "labour": 15000, "overhead": 9000, "result": 10000 }
      ]
    },
    { "shop_id": 2, "months": [] }
  ]
}
```

| Field | Type | Required |
|---|---|---|
| `turnover` | number | **yes** |
| `material` | number | **yes** — the food cost of the month |
| `labour` | number | **yes** |
| `overhead` | number | **yes** — rent, royalties, energy, depreciation… |
| `result` | number | optional; the panel no longer relies on it |

`null` is acceptable when a line genuinely doesn't exist for that month — the
panel shows it as missing and says so on screen. A **silently absent** field is
not: the panel computes `net margin = (turnover − material − labour − overhead)
÷ turnover`, and a missing `material` pushes margins toward 40 %, which is
wrong rather than merely incomplete.

> This requirement also applies to the **existing** single-shop endpoint
> `GET /consultant/shops/{id}/pnl/monthly`, where it is ticket **T5a** and
> ranks ahead of everything else on the list. See `BACKEND_SPEC.md`.

---

## T6 — Owner validation of a review

```
POST /consultant/shops/{shopId}/task-reviews/{reviewId}/validate
{ "validated": true }
```

```json
{ "success": true,
  "owner_validated_at": "2026-07-30 14:02:00",
  "owner_validated_by_name": "Marc O." }
```

`{"validated": false}` clears the validation.

And three fields on each reviewed task in
`GET /consultant/shops/{shopId}/checklists/{checklistId}/progress`:

```json
{
  "owner_validated_at": "2026-07-30 14:02:00",
  "owner_validated_by": 99,
  "owner_validated_by_name": "Marc O."
}
```

Null when not validated. Without these, the validation exists only in the panel
database: invisible to every other client, and lost if that database is
unavailable.

If this is out of scope, say so — the panel keeps storing it locally, and we
stop asking.

---

## T9 — Checklist progress over a date range

```
GET /consultant/shops/{shopId}/checklists/progress?from=2026-05-01&to=2026-05-31
```

`from` and `to` are dates (`YYYY-MM-DD`), inclusive. Same payload as the two
existing per-day endpoints, grouped by day:

```json
{
  "days": [
    {
      "date": "2026-05-25",
      "checklists": [
        {
          "id": 10,
          "name": "Ouverture boutique",
          "tasks": [
            { "task_id": 1, "task_name": "Ouverture caisse", "status": "DONE",
              "is_mandatory": true, "attachment_id": 77,
              "review_is_accepted": 1, "review_rating": 5, "review_comment": "RAS" }
          ]
        }
      ]
    },
    { "date": "2026-05-26", "checklists": [] }
  ]
}
```

- Every requested day present, even with an empty `checklists` array.
- Task objects identical to `/checklists/{checklistId}/progress`.
- **A range cap is welcome** — 62 days, say. Publish the limit and the error
  returned above it and the panel will split the range itself. Silent
  truncation is the one behaviour we cannot work with: a month that comes back
  short reads as a month where nothing happened.

---

## What we do until these exist

Nothing is blocked; everything is slower or heavier:

| Missing | What the panel does instead |
|---|---|
| T4 | 25 concurrent requests for one screen — 25 auth checks, 25 DB round trips |
| T5b | one request per shop, in parallel; 11 network waits on the valuation screen |
| T6 | stores the validation in its own database, invisible to other clients |
| T9 | freezes every closed day into a local table, with the staleness and "is this day closed" logic that implies |

T9 is the only one whose arrival lets us **delete** code rather than simplify
it: the snapshot table and its freezing rules go away entirely.
