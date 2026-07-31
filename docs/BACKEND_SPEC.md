# Backend work requested by the Consultant Panel

Each ticket says **what to change**, shows the **JSON before and after**, and
gives an **acceptance test** you can run with curl.

Nothing here breaks existing clients: every change is an *added* field or a
*new* endpoint.

| # | Ticket | Endpoint | Effort | Priority |
|---|---|---|---|---|
| T1 | Add review author + timestamp | `GET …/checklists/{cid}/progress` | S | **1 — blocking** |
| T2 | Document the review POST | `POST …/task-reviews` | S | **2** |
| T3 | Add completion fields to the day view | `GET …/shops/{id}/tasks` | M | **3** |
| T4 | Multi-shop material complaints | new endpoint | M | 4 |
| T5a | **`material` always present on the monthly P&L** | `GET …/{id}/pnl/monthly` | S | **1 — blocking** |
| T5b | Multi-shop monthly P&L | new endpoint | M | 4 |
| T6 | Owner validation of a review | new endpoint | M | 5 |
| T7 | Cache headers on read-only aggregates | 5 endpoints | S | 5 |
| T8 | **Three endpoints, three different revenues** | 3 existing endpoints | S | **2** |

**Start with T5a and T8.** They are the only two tickets on this list about
figures being *wrong*. Everything else makes the panel faster or richer; T5a and
T8 make it correct — T5a fixes the margin, T8 fixes the revenue that margin is a
percentage of.

**Why round trips matter.** The panel's HTTP client times out after **10 s per
call**. A screen needing N sequential calls can take N × 10 s and be cut off by
the gateway. Turning "N calls" into "1 call" is worth more to us than making any
single call faster.

---

## T1 — Review author and timestamp

**Endpoint:** `GET /consultant/shops/{shopId}/checklists/{checklistId}/progress?date=YYYY-MM-DD`

### What to change

Add three fields to each object in `tasks[]`.

### Today

```json
{
  "task_id": 1216,
  "status": "DONE",
  "review_id": 55,
  "review_is_accepted": 1,
  "review_rating": 4,
  "review_comment": "RAS"
}
```

### Wanted

```json
{
  "task_id": 1216,
  "status": "DONE",
  "review_id": 55,
  "review_is_accepted": 1,
  "review_rating": 4,
  "review_comment": "RAS",

  "review_by": 7,
  "review_by_name": "Sam V.",
  "reviewed_at": "2026-07-30 11:24:03"
}
```

| Field | Type | Null when |
|---|---|---|
| `review_by` | int | no review |
| `review_by_name` | string | no review |
| `reviewed_at` | datetime `Y-m-d H:i:s` | no review |

`review_by_name` should follow the same convention as the existing
`completed_by` field, which already returns a display name.

### Why

The panel has to show **who** checked a task — that is the point of supervising
consultants. Today it can only display "Reviewed", with no name. It currently
keeps a local journal to guess the author, which only works for reviews made
through the panel and requires a writable panel database.

### Acceptance

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/4/checklists/44/progress?date=2026-07-30" \
  | jq '.tasks[] | select(.review_id != null)
        | {task_id, review_id, review_by, review_by_name, reviewed_at}'
```

Every task with a `review_id` must return a non-null `review_by`,
`review_by_name` and `reviewed_at`.

---

## T2 — Document the review POST

**Endpoint:** `POST /consultant/shops/{shopId}/task-reviews`

### What to change

Nothing in the code — we need the **contract**.

### What we send today

The panel guesses, and sends duplicate field names to cover the possibilities:

```json
{
  "shop_id": 4,
  "checklist_id": 44,
  "task_id": 1216,
  "review_date": "2026-07-30",
  "completion_id": 169,
  "rating": 4,            "review_rating": 4,
  "comment": "RAS",       "review_comment": "RAS",
  "is_accepted": true,    "review_is_accepted": true
}
```

### What we need from you

1. the **required** fields and their types;
2. the **optional** fields;
3. the success response body;
4. the validation-error response body — ideally naming the offending fields;
5. whether a second POST on the same task **updates** the review or creates a
   second one.

### Why

We cannot clean up the duplicated payload until we know which names are real.

---

## T3 — Completion fields on the day view

**Endpoint:** `GET /consultant/shops/{shopId}/tasks?date=YYYY-MM-DD`

### What to change

Add these fields to each object in `tasks[]`. **They already exist** on the
`/progress` endpoint — this is about exposing them here too.

### Today

```json
{
  "task_id": 1216,
  "task_name": "Vérifier si le magasin est propre.",
  "checklist_name": "CO-01 — Ouverture",
  "status": "DONE",
  "completed_by": "Nathan C.",
  "completed_at": "2026-07-30 09:09:34",
  "note": "",
  "is_mandatory": 1,
  "requires_photo": 1,
  "execution_time": "06:30:00",
  "frequency": "DAILY",
  "priority": 5
}
```

### Wanted — add

```json
{
  "checklist_id": 44,
  "completion_id": 169,
  "attachment_id": 393,
  "attachment_filename": "task_photo_1785402563686.jpg",
  "review_id": 55,
  "review_rating": 4,
  "review_is_accepted": 1,
  "review_comment": "RAS"
}
```

plus `review_by`, `review_by_name`, `reviewed_at` once T1 is done.

### Why

Without them the panel cannot show the photo of a completed task, nor its
review, from this screen.

**Cost today:** for every page load the panel calls
`GET /consultant/shops/{id}/checklists`, then `/checklists/{cid}/progress` for
**each checklist of the day**, and joins on `task_id`. That is **2 extra round
trips minimum**, more if the shop has several checklists.

### Acceptance

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/4/tasks?date=2026-07-30" | jq '.tasks[0] | keys'
```

Must include `checklist_id`, `completion_id`, `attachment_id`,
`attachment_filename`, `review_id`.

---

## T4 — Material complaints for all shops

**Existing endpoint:** `GET /shops/{shopId}/material-complaints` — one shop.

### What to create

```
GET /consultant/shops/material-complaints
GET /consultant/shops/material-complaints?status=OPEN
GET /consultant/shops/material-complaints?from=2026-07-01&to=2026-07-31
```

All three query parameters are **optional**. Without them, return everything the
single-shop endpoint would return, for every shop the consultant may see.

| Parameter | Type | Meaning |
|---|---|---|
| `status` | string | filter, same values as today (`OPEN`, `CLOSED`, …) |
| `from` | date `Y-m-d` | `reported_at` ≥ this date |
| `to` | date `Y-m-d` | `reported_at` ≤ this date |

### Response

Same envelope as your other multi-shop endpoints (`/consultant/shops/monthly-sales`):

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

Two rules that matter to us:

1. **Every authorised shop appears**, even with an empty `complaints` array. A
   missing shop and a shop with zero complaints are different facts, and the
   panel has to tell them apart — one is "no problem", the other is "no data".
2. Each complaint object is **exactly** what the single-shop endpoint returns
   today. Don't reshape it; we already parse that form.

### Why

Two screens need every shop's complaints at once: the network report and the
Food-Cost lever.

Today the panel calls `GET /shops/{id}/material-complaints` once per shop. They
go out in parallel (`curl_multi`, in chunks of 32), so it is one wait for us —
but it is **25 concurrent requests hitting your side** for a single screen, and
25 authorisation checks, and 25 query round trips to your database.

One endpoint means one query, and it lets you filter server-side instead of
sending us everything to filter in PHP.

### Acceptance

```bash
# 1. Every authorised shop is present, including those with no complaint.
curl -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/material-complaints" | jq '.shops | length'
```

Must equal the number of shops the consultant may see — same
`consultant_shop_assignment` rule as your other endpoints.

```bash
# 2. The per-shop payload matches the single-shop endpoint, shop by shop.
diff \
  <(curl -s -H "Authorization: Bearer $TOKEN" "$API/shops/4/material-complaints" | jq -S .) \
  <(curl -s -H "Authorization: Bearer $TOKEN" "$API/consultant/shops/material-complaints" \
    | jq -S '.shops[] | select(.shop_id == 4) | .complaints')
```

Must print nothing.

```bash
# 3. The filter narrows, and never widens.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/material-complaints?status=OPEN" \
  | jq '[.shops[].complaints[] | select(.status != "OPEN")] | length'
```

Must be `0`.

---

## T5 — Monthly P&L for all shops

**Existing endpoint:** `GET /consultant/shops/{shopId}/pnl/monthly?from=&to=` (P2) — one shop.

This ticket has **two parts**. The second one is the important one: the endpoint
that exists today does not always return the cost line we need, and without it
the valuation is wrong — not slow, *wrong*.

### Part A — the multi-shop endpoint

```
GET /consultant/shops/pnl/monthly?from=2025-08&to=2026-07
```

`from` and `to` are months (`YYYY-MM`), inclusive. Same envelope as
`/consultant/shops/monthly-sales`:

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

Same month object as the existing single-shop endpoint. As in T4: every
authorised shop appears, even with an empty `months` array.

### Part B — `material` must always be there

**This is the blocking part.**

The panel computes the net margin of a shop as:

```
net margin = (turnover − material − labour − overhead) ÷ turnover
```

It does **not** use your `result` field, because `result` does not always deduct
the material cost. When it doesn't, margins come out around 40 % — no bakery
earns that, and the whole valuation follows the error.

So every month object must carry the four lines, or say plainly that it can't:

| Field | Type | Required |
|---|---|---|
| `turnover` | number | **yes** |
| `material` | number | **yes** — the food cost of the month |
| `labour` | number | **yes** |
| `overhead` | number | **yes** — rent, royalties, energy, depreciation… |
| `result` | number | optional; we no longer rely on it |

`null` is acceptable and honest when a line genuinely doesn't exist for that
month — the panel shows it as missing and says so on screen. What we cannot work
with is a **silently absent** `material` next to a `result` that looks complete.

**What happens today without it.** `/pnl/daily` *does* return `material` — the
profitability heat map lives on it and its figures are right. So the panel falls
back to the daily endpoint and re-aggregates a whole month day by day, just to
recover one number the monthly endpoint should already have. That is an extra
round trip per affected shop, on every valuation.

### Two questions we need answered

1. **What exactly does `overhead` cover?** We treat it as rent + royalties +
   energy + depreciation. If some of those sit elsewhere, the margin is
   overstated and we need to know where they are.
2. **Is `result` meant to equal `turnover − material − labour − overhead`?** If
   yes, it's currently not, and that's a bug on your side worth fixing anyway.
   If no, tell us what it means and we'll stop mentioning it.

### Why (Part A)

The valuation screen needs 12 closed months of P&L for **every** shop. Today
that's one request per shop, fired in parallel — same cost profile as T4: one
wait for us, N concurrent requests for you.

Measured on the current panel, the valuation endpoint costs **11 network waits**
and the trends endpoint **12**. T5 removes the largest chunk of that.

This is the exact multi-shop pattern you already built for P1
(`/consultant/shops/monthly-sales`) — same shape, different payload.

### Acceptance

```bash
# 1. Every authorised shop is present.
curl -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/pnl/monthly?from=2025-08&to=2026-07" | jq '.shops | length'
```

```bash
# 2. THE ONE THAT MATTERS — no month may omit a cost line.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/pnl/monthly?from=2025-08&to=2026-07" \
  | jq '[.shops[].months[]
         | select(has("material") and has("labour") and has("overhead") | not)]
        | length'
```

Must be `0`. Present-and-`null` passes; absent does not.

```bash
# 3. The monthly endpoint agrees with the daily one, which we know is right.
#    Pick one shop and one closed month.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/4/pnl/daily?from=2026-06-01&to=2026-06-30" \
  | jq '[.days[].material] | add'

curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/pnl/monthly?from=2026-06&to=2026-06" \
  | jq '.shops[] | select(.shop_id == 4) | .months[0].material'
```

The two numbers must match. If they don't, the monthly aggregation is the one to
fix — the daily figures are what the heat map uses, and those have been checked
against your back-office.

---

## T6 — Owner validation of a review

A supervisor ("Owner") approves the review a consultant made. There is no place
for this in the API today.

### Option A — preferred

Add to each reviewed task in `/progress`:

```json
{
  "owner_validated_at": "2026-07-30 14:02:00",
  "owner_validated_by": 99,
  "owner_validated_by_name": "Marc O."
}
```

and an endpoint to set them:

```
POST /consultant/shops/{shopId}/task-reviews/{reviewId}/validate
{ "validated": true }
```

Response:

```json
{ "success": true,
  "owner_validated_at": "2026-07-30 14:02:00",
  "owner_validated_by_name": "Marc O." }
```

`{"validated": false}` clears the three fields.

### Option B

Tell us it is out of scope, and the panel keeps storing it locally.

### Why

Same limitation as T1: stored in the panel database only, therefore invisible to
any other client, and lost if that database is unavailable.

---

## T7 — Cache headers on read-only aggregates

**Endpoints:** `/consultant/shops/monthly-sales`, `/consultant/shops/sales-kpis`,
`/consultant/targets`, `/consultant/shops/category-sales`,
`/consultant/shops/pnl-summary`

### What to change

Send `Cache-Control` and `ETag`:

```
Cache-Control: private, max-age=300
ETag: "abc123"
```

Suggested `max-age`: **86400** when the requested window lies entirely in the
past — a closed month never changes — and **300** for anything covering today.

### Why

None of these endpoints send cache headers, so the panel guesses TTLs (60 s to
30 min depending on the endpoint) and can be wrong in both directions: stale
data, or a request that was never needed. Proper headers let every client skip
whole requests instead of guessing.

---

## T8 — One shop, one month, three different revenues

**Endpoints concerned:**
`GET /consultant/shops/monthly-sales` (P1) · `GET /consultant/shops/sales-kpis` (P3) · `GET /consultant/shops/{id}/pnl/monthly` (P2)

Nothing to build here. This ticket asks you to **make three existing numbers
agree — or to tell us why they shouldn't.**

### The problem

Three endpoints report the revenue of a shop, and for the *same shop* over the
*same closed month* they do not return the same figure:

| Source | Field | What the panel uses it for |
|---|---|---|
| P1 `monthly-sales` | `ca` | the 12-month trend series |
| P3 `sales-kpis` | `ca` / `turnover` | reports, average basket, N vs N-1 |
| P2 `pnl/monthly` | `turnover` | valuation, net margin |

The panel cannot tell which one is right. It has no rule to pick a winner, so
today it **hard-codes a preference per screen** — which is a workaround, not an
answer, and it means two screens can legitimately show two different revenues
for the same month.

**One correction on our side, in fairness.** We reported a gap of roughly ×3
earlier. Part of that was our bug: the panel was summing per-shop KPIs over a
shop list that contained duplicates, while `monthly-sales` comes back keyed by
`shop_id` and so was immune. That duplication is fixed. Please don't chase the
×3 — run the comparison below and work from what it actually shows.

### What we're asking for

Either of these closes the ticket:

1. **They agree.** The three figures match for an identical window, to the cent.
2. **They legitimately differ**, and you document what each one counts. Then we
   pick the right source per screen instead of guessing.

If it's (2), these are the differences we'd expect to be told about:

| Question | Why it matters to us |
|---|---|
| Gross or net of VAT? | a 6 % / 21 % mix changes every margin we compute |
| Are discounts deducted? | changes the average basket, not just the total |
| Are cancelled or voided tickets excluded? | one endpoint may filter them, another not |
| Are B2B, delivery and wholesale channels included? | a shop with a B2B contract diverges from its neighbours for a real reason |
| Days with no register closing — skipped or counted as zero? | changes a monthly total, and the day count behind an average |

### What the panel does today

- **Revenue level** comes from `monthly-sales`, and from `pnl/monthly` on the
  valuation screen so that revenue and costs share one source. A margin built
  from two endpoints is arithmetic on two different definitions.
- **`sales-kpis` is no longer trusted for the level** — only for the **N / N-1
  ratio at equal days**, which is the one thing it can do that the monthly
  series cannot (truncating both years to the same day count). A ratio survives
  a wrong level because both sides come from the same endpoint.
- The gap between the two is **measured and shown on screen** above 2 %, rather
  than being quietly absorbed.

That's a lot of machinery to work around a question only you can answer.

### Acceptance

```bash
# Pick one shop and one CLOSED month — no partial month, no ambiguity.
SHOP=4; M=2026-06

# 1. The monthly series.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/monthly-sales?from=$M&to=$M" \
  | jq --argjson s $SHOP '.shops[] | select(.shop_id == $s) | .months[0].ca'
```

```bash
# 2. The sales KPIs, same window expressed as dates.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/sales-kpis?date_from=2026-06-01&date_to=2026-06-30" \
  | jq --argjson s $SHOP '.shops[] | select(.shop_id == $s) | (.ca // .turnover)'
```

```bash
# 3. The P&L.
curl -s -H "Authorization: Bearer $TOKEN" \
  "$API/consultant/shops/$SHOP/pnl/monthly?from=$M&to=$M" \
  | jq '.months[0].turnover'
```

The three numbers must be equal. If they aren't, the answer we need is **which
one is the revenue of that shop for that month**, and what the other two are
counting instead.

### Why

This is the second correctness ticket on this list, next to T5a. T5a makes the
margin right; T8 makes the number the margin is a percentage *of* right. A
correct margin on the wrong revenue is still a wrong valuation.

---

## What each ticket changes for the panel

| Ticket | What we can delete | What we gain |
|---|---|---|
| T1 | the local review journal | the real author, for every review |
| T2 | the duplicated field names | a payload we can trust |
| T3 | 2 round trips per page load | photo and review on the day view |
| T4 | 25 concurrent requests | 1 request |
| T5a | the fallback that re-reads a whole month day by day | a net margin we can trust |
| T5b | N parallel requests | 1 request |
| T6 | a local table | validation visible to all clients |
| T7 | our TTL guesses | fewer requests, fresher data |
| T8 | a hard-coded source preference per screen | one revenue figure, the same on every screen |

---

## Reference — endpoints the panel already uses

For context, so nothing here surprises you.

**Working batch endpoints delivered on 30/07:**

| Ref | Endpoint | Used by |
|---|---|---|
| P0 | `GET /consultant/shops/{id}/pnl/daily` | profitability heat map |
| P1 | `GET /consultant/shops/monthly-sales` | Trends |
| P2 | `GET /consultant/shops/{id}/pnl/monthly` | Valuation — see T5 |
| P3 | `GET /consultant/shops/sales-kpis` | Trends, reports, valuation |
| P4 | `GET /consultant/shops/{id}/margin-heatmap?split=weekly` | heat map |
| P6a | `GET /consultant/targets?year=&month=` | Trends |
| P6b | `GET /consultant/shops/{id}/targets/range` | single shop, many months |
| P7 | `GET /consultant/shops/category-sales` | report comparison |
| P8 | `GET /consultant/shops/pnl-summary` | valuation |

**Everything else the panel calls:**

```
/consultant/shops                              /consultant/metric-definitions
/consultant/shops/{id}/targets                 /consultant/note-types
/consultant/shops/{id}/targets/copy            /consultant/notes/{id}
/consultant/shops/{id}/notes                   /consultant/comments/{id}
/consultant/shops/{id}/employees/{id}/notes    /consultant/tasks
/consultant/shops/{id}/checklists              /consultant/tasks/completions/{id}
/consultant/shops/{id}/checklists/{cid}/progress
/consultant/shops/{id}/task-reviews            /consultant/network/tasks/ranking
/shops/{id}/employees                          /shops/{id}/material-complaints
/attachments/{id}/presigned-url
/cases/{id}  ·  /cases/{id}/status  ·  /cases/{id}/consultant
/cases/{id}/meeting  ·  /cases/{id}/meetings  ·  /cases/{id}/reply
/cases/{id}/eligible-consultants
```
