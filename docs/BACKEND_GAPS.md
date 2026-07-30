# Consultant Panel — API gaps and requests

**To:** backend team · **From:** consultant panel · **Date:** 2026‑07‑30

Everything below was found by probing the live API from the panel. Each item
states what the panel does **today** to work around the gap, so you can judge
priority. Nothing here blocks the panel — it works — but each gap costs either
information we cannot display, or round trips we cannot avoid.

Round trips matter: the panel's HTTP client uses a **10 s timeout per call**, so
a screen that needs N sequential calls can take N × 10 s in the worst case and
be cut off by the gateway. "1 call for N shops" is the single most valuable
thing you can give us.

---

## 1. Task reviews — who reviewed, and when

**Endpoint:** `GET /consultant/shops/{id}/checklists/{checklistId}/progress`

Each task currently returns:

```
sort_order · task_id · name · description · execution_time · is_mandatory
requires_photo · frequency · day_of_week · status · completion_id
completed_at · scheduled_time · note · completed_by · attachment_id
attachment_filename · review_id · review_is_accepted · review_rating
review_comment · product_id · product_name · product_main_photo
```

A review therefore has a **rating, a compliance flag and a comment — but no
author and no timestamp**.

### Requested

| Field | Type | Meaning |
|---|---|---|
| `review_by` | int | id of the consultant who reviewed |
| `review_by_name` | string | display name, as `completed_by` already does |
| `reviewed_at` | datetime | when the review was recorded |

### Why

The panel must show *who* checked a task — that is the whole point of
supervising consultants. Today it can only display "Reviewed", with no name.

### Current workaround

The panel writes its own journal (`mac_task_review`, in the panel database)
recording the logged‑in consultant at the moment a review is saved. This only
covers reviews made **through the panel**; any review recorded elsewhere stays
anonymous. It also means the panel database must be writable — which it may not
be.

---

## 2. Task reviews — the POST contract is undocumented

**Endpoint:** `POST /consultant/shops/{id}/task-reviews`

We do not know which body it accepts. The panel currently sends:

```json
{
  "shop_id": 4,
  "checklist_id": 44,
  "task_id": 1216,
  "review_date": "2026-07-30",
  "completion_id": 169,
  "rating": 4,
  "review_rating": 4,
  "comment": "…",
  "review_comment": "…",
  "is_accepted": true,
  "review_is_accepted": true
}
```

The duplicated names are guesses, kept until the real contract is known.

### Requested

- the exact accepted body (required vs optional fields, types);
- the response shape on success and on validation failure;
- whether a second POST on the same task **updates** the review or creates a
  duplicate.

---

## 3. Owner validation — no place to store it

There is no notion of "the review itself has been approved by a supervisor".

### Requested (optional)

Either three more fields on the review — `owner_validated_at`,
`owner_validated_by`, `owner_validated_by_name` — or a dedicated endpoint such
as `POST /consultant/shops/{id}/task-reviews/{reviewId}/validate`.

### Current workaround

Stored in the panel database only. Same limitation as §1: invisible to any
other client.

---

## 4. Shop tasks — the day view needs 1 + N extra calls

**Endpoint:** `GET /consultant/shops/{id}/tasks?date=…`

Returns per task:

```
checklist_name · completed_at · completed_by · execution_time · frequency
is_mandatory · note · priority · requires_photo · status · task_id · task_name
```

Missing: `completion_id`, `attachment_id`, `attachment_filename`,
`checklist_id`, and the `review_*` fields — all of which **do** exist on the
`/progress` endpoint.

### Requested

Add those fields to `/tasks`. They are already computed for `/progress`.

### Why

Without them the panel cannot show the photo of a completed task, nor its
review, from this screen.

### Current workaround

For every page load the panel calls `GET /consultant/shops/{id}/checklists`,
then `/checklists/{cid}/progress` **for each checklist of the day** (in
parallel), and joins on `task_id`. That is 2 extra round trips minimum, more if
the shop has many checklists.

---

## 5. Material complaints — no multi‑shop endpoint

**Endpoint:** `GET /shops/{id}/material-complaints`

The network view needs the complaints of **every** shop.

### Requested

`GET /consultant/shops/material-complaints?status=…` returning all shops in one
response (same shape as the existing batch endpoints, e.g.
`{ "shops": [ { "shop_id": 1, "complaints": [ … ] } ] }`).

### Current workaround

The panel fires all shop URLs in one parallel `curl_multi` batch. It works, but
it is N concurrent requests on your side for one screen.

---

## 6. Batch endpoints delivered on 30/07 — status from the panel side

These are wired and working; listed so you know what the panel depends on.

| Ref | Endpoint | Used by |
|---|---|---|
| P0 | `GET /consultant/shops/{id}/pnl/daily` | profitability heat map |
| P1 | `GET /consultant/shops/monthly-sales` | Trends |
| P2 | `GET /consultant/shops/{id}/pnl/monthly` | Valuation |
| P3 | `GET /consultant/shops/sales-kpis` | Trends, reports, valuation |
| P4 | `GET /consultant/shops/{id}/margin-heatmap?split=weekly` | heat map |
| P6a | `GET /consultant/targets?year=&month=` | Trends |
| P6b | `GET /consultant/shops/{id}/targets/range` | single‑shop multi‑month |
| P7 | `GET /consultant/shops/category-sales` | report comparison |
| P8 | `GET /consultant/shops/pnl-summary` | valuation |

Two remarks:

- **P2 is per shop.** Valuation needs 12 months of P&L for every shop; the panel
  fires them in parallel, but a multi‑shop variant
  (`GET /consultant/shops/pnl/monthly?from=&to=`) would turn N requests into 1.
- **P8** was announced as "not yet a single SQL aggregation". If it becomes one,
  the panel will prefer it over the parallel fallback.

---

## 7. Server‑side caching

The panel caches GET responses per user (60 s to 30 min depending on the
endpoint) because none of these endpoints send cache headers.

### Requested

`Cache-Control` / `ETag` on the read‑only aggregates (P1, P3, P6a, P7, P8).
Historical months never change; today's data changes slowly. This would let the
panel — and any other client — skip whole requests instead of guessing TTLs.

---

## Priority, from the panel's point of view

1. **§1** — review author and timestamp. Blocks a feature we cannot fake
   reliably.
2. **§4** — completion and review fields on `/tasks`. Removes 2+ round trips per
   page load.
3. **§2** — document the POST contract. Costs you minutes, saves us guesswork.
4. **§5** and the P2 multi‑shop variant — round‑trip reduction.
5. **§7** and **§3** — nice to have.
