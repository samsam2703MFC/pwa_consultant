# Dev handoff — everything still to build

One file, two audiences.

- **Part 0 — Infra / DBA.** Half a page, no API work. Ships with the current
  package. This is what makes the performance heatmap exist.
- **Parts 1 to 3 — Backend API.** Twelve tickets: two column migrations, six
  existing endpoints to enrich, **seven endpoints that do not exist yet**.

Everything below is *added* — a new column, a new field, a new URL. **No
existing client breaks.**

Full reasoning, before/after JSON and per-ticket acceptance tests:
`BACKEND_SPEC.md`. Short version of parts 1–3 only: `BACKEND_BUILD.md`. This
file supersedes both as the *to-do list*.

> **Nothing on this list has been confirmed shipped at the time of writing.**
> Every ticket carries a one-line curl that answers "is it done?" in three
> seconds — faster than a meeting.

---

# Part 0 — Infra / DBA

**No API work in this part.** It is entirely about the panel's own database
(`atelierby_db`) and it unblocks one thing: measuring how slow the panel
actually is, per screen and per hour.

## 0.1 · Why this exists

Everything said so far about the panel's speed has been an *estimate*. The panel
now records, for every screen displayed:

| Column | What it says |
|---|---|
| `server_ms` | total time inside PHP |
| `api_ms` | of which, time spent **waiting on your API** |
| `api_calls` | calls that actually went out on the network |
| `api_cached` | responses served from cache, no network |
| `client_ms` | the time **really experienced**, reported back by the browser |

`server_ms − api_ms` is the panel's own cost. `api_ms` and `api_calls` are
yours. That split is the point: it ends the argument about whose latency it is.

## 0.2 · What to run

The panel creates its own tables on first use (`CREATE TABLE IF NOT EXISTS`).
**If the application's MySQL account has the `CREATE` privilege, there is
nothing to do** — skip to §0.4.

If it does not, run these files from the repository, in any order:

```
database/mac_consultant_param.sql       -- configuration (key/value)
database/mac_consultant_perf.sql        -- NEW: render timings (the heatmap)
database/mac_network_day.sql
database/mac_checklist_day_snapshot.sql
database/mac_task_review.sql
database/mac_report_share.sql
database/mac_shop_monthly_pnl.sql
database/mac_kpi_threshold.sql
database/mac_agenda_tables.sql
```

The new one, in full:

```sql
CREATE TABLE IF NOT EXISTS mac_consultant_perf (
    id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    rid          CHAR(16)         NOT NULL,   -- correlates server row ↔ browser beacon
    ts           DATETIME         NOT NULL,
    snap_date    DATE             NOT NULL,
    hour_of_day  TINYINT UNSIGNED NOT NULL,   -- 0 – 23
    day_of_week  TINYINT UNSIGNED NOT NULL,   -- 1 = Monday … 7 = Sunday
    user_key     VARCHAR(40)      NOT NULL,   -- stable id from the JWT. No name, no e-mail
    route        VARCHAR(160)     NOT NULL,   -- normalised: /shops/{id}, never /shops/42
    method       VARCHAR(8)       NOT NULL,
    server_ms    MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    api_ms       MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    api_calls    SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    api_cached   SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    api_failed   SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    client_ms    MEDIUMINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rid (rid),
    KEY idx_date_hour (snap_date, hour_of_day),
    KEY idx_route_date (route, snap_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Required grants on `atelierby_db`: `SELECT, INSERT, UPDATE, DELETE`. `CREATE` is
optional but recommended — without it, every future table needs a DBA ticket.

## 0.3 · Three things you do **not** have to do

1. **No scheduled job.** Retention purges itself (`perf_retention_days`,
   default 90 days), a few rows at a time, from ordinary traffic.
2. **No log shipping, no agent, no external service.** One table, one screen.
3. **No API change.** Nothing in Part 0 touches your endpoints.

## 0.4 · How to verify, in one call

```bash
curl -s "$PANEL/system/db-setup" | jq '.ok, .tables.mac_consultant_perf'
```

`true` and `{"exists": true, "rows": N}` means done. Anything else names the
missing privilege in the `hint` field.

## 0.5 · Volume, and privacy

Roughly one row per screen opened, plus one per prefetch call at login (~20 to
90 depending on the number of shops). A few hundred rows a day, single-digit MB
a year. `perf_sample_pct` lowers it if that ever matters.

`user_key` is the stable identifier taken from the JWT — the same key the cache
uses. **No name, no e-mail, no IP, no URL parameters.** The route is normalised
before storage, so `/shops/42` is written `/shops/{id}`.

## 0.6 · Where to read it

| | |
|---|---|
| `GET /system/perf` | heatmap, screen × hour, plus a per-screen table |
| `GET /system/perf/data` | the same figures as JSON — `?days=` or `?from=&to=` |
| `POST /system/perf/beacon` | internal; the page reports its own experienced time |

The heatmap separates two failures that do **not** have the same fix: a screen
slow at 07:00 and fast at 11:00 is a **cache** problem; a screen slow at every
hour is a **query** problem — and that second one is what Parts 1–3 are about.

---

# Part 1 — Backend database migrations

Only two tickets touch a table. **T2, T3, T4, T5a, T5b, T7, T8, T9, T11 and T12
are query, payload or header work — no migration.**

Column and table names are suggestions. Only the *meaning* and the
*nullability* matter, because those are what the panel reads.

## 1.1 · Task reviews — author, timestamp, countersignature  (T1, T6)

The table storing a consultant's review of a completed task holds the verdict
(`rating`, `is_accepted`, `comment`) but **not its author**. The panel keeps its
own journal to fill that gap — a journal that only knows about reviews posted
*through the panel*, which is exactly why it has to go.

| Column | Type | Null | Why |
|---|---|---|---|
| `reviewed_by` | `BIGINT UNSIGNED` | no | the consultant who judged — **T1** |
| `reviewed_at` | `DATETIME` | no | when — **T1** |
| `owner_validated_at` | `DATETIME` | yes, until validated | the Owner countersigns — **T6** |
| `owner_id` | `BIGINT UNSIGNED` | yes | who countersigned — **T6** |

```sql
ALTER TABLE task_review
  ADD COLUMN reviewed_by        BIGINT UNSIGNED NOT NULL,
  ADD COLUMN reviewed_at        DATETIME        NOT NULL,
  ADD COLUMN owner_validated_at DATETIME        NULL,
  ADD COLUMN owner_id           BIGINT UNSIGNED NULL,
  ADD KEY idx_reviewed_by (reviewed_by, reviewed_at);
```

**Backfill — a decision, not a detail.** Existing rows have no author. Either
point `reviewed_by` at a service account with `reviewed_at = created_at`, or make
both columns nullable. Tell us which: *"no author"* and *"author unknown"* are
not the same thing to a screen that prints **Vérifié par …**.

## 1.2 · Products — the PDM flag and the sector  (T10)

| Column | Type | Null | Why |
|---|---|---|---|
| `is_pdm` | `TINYINT(1)` | **no**, default `0` | a product is PDM or it is not. Never null, never an absent key — otherwise "not PDM" and "unknown" collapse into one value |
| `sector_id` | `BIGINT UNSIGNED` | see below | boulangerie, traiteur, … |

```sql
CREATE TABLE product_sector (
  id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120)    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE product
  ADD COLUMN is_pdm    TINYINT(1)      NOT NULL DEFAULT 0,
  ADD COLUMN sector_id BIGINT UNSIGNED NULL,
  ADD KEY idx_sector (sector_id),
  ADD CONSTRAINT fk_product_sector FOREIGN KEY (sector_id) REFERENCES product_sector (id);
```

**The decision we need from you:** the product form must *require* a sector from
now on, but the existing catalogue has none.

- **Backfill** → `sector_id` becomes `NOT NULL` and every payload carries a
  sector. Cleanest.
- **Leave them null** → say so, and we display *« secteur non renseigné »*.

Either is fine. What must not happen is those products counting as **zero** in a
breakdown — which is exactly what happens if nobody decides.

**Still open on our side:** T10 asks for `is_pdm` without saying what the panel
should *do* with it. Group a breakdown? Filter a lever? Flag it on the product
list? Until that is answered, we will carry the field and display nothing.

---

# Part 2 — Existing endpoints to enrich

| Endpoint | Add | Ticket |
|---|---|---|
| `GET /consultant/shops/{id}/checklists/{cid}/progress` | `review_by`, `review_by_name`, `reviewed_at` on each task | **T1** |
| `GET /consultant/shops/{id}/tasks` | `completion_id`, `attachment_id`, `review_rating`, `review_is_accepted`, `review_comment` on each task | **T3** |
| `GET /consultant/shops/{id}/pnl/monthly` | **`material` on every month**, `0` when there is nothing — never an absent line | **T5a — blocking** |
| `POST /consultant/shops/{id}/task-reviews` | nothing to add: **write the contract down** | **T2** |
| 5 read-only aggregates | `Cache-Control` + `ETag` | **T7** |
| `…/monthly-sales`, `…/sales-kpis`, `…/pnl/monthly` | nothing to add: make the three revenues **agree**, or document which is authoritative | **T8** |

Once §1.2 ships, `GET /consultant/shops/{id}/product-category-groups` — and any
payload carrying a product — must also carry `is_pdm`, `sector_id`,
`sector_name` (**T10**).

## 2.1 · T5a — `material` must always be present  *(blocking)*

A month with no material line is currently **omitted**, not zeroed. The panel
cannot tell "no material cost" from "the cost is missing", so it either shows a
margin that is wrong or re-reads the whole month day by day to check.

A wrong margin, shown as fact, is the worst defect on this list. It is one line
of SQL: `COALESCE(material, 0)`, and the row always present.

## 2.2 · T8 — one shop, one month, three different revenues  *(blocking)*

`monthly-sales`, `sales-kpis` and `pnl/monthly` return three different figures
for the same shop and the same closed month. Every margin the panel computes is
a percentage of one of them.

Either of these closes the ticket:

1. **They agree**, to the cent, for an identical window.
2. **They legitimately differ** — then document what each one counts:

| Question | Why it matters |
|---|---|
| Gross or net of VAT? | a 6 % / 21 % mix changes every margin |
| Are discounts deducted? | changes the average basket, not just the total |
| Are cancelled or voided tickets excluded? | one endpoint may filter, another not |
| Are B2B / delivery / wholesale included? | a shop with a B2B contract diverges for a real reason |
| Days with no register closing — skipped, or counted as zero? | changes a monthly total *and* the day count behind every average |

Related, and still unanswered: we asked for the **ticket count** behind each
source (`/api-debug?ca=1` output). A ×3 divergence with matching ticket counts is
a VAT or channel problem; with different counts it is a filtering problem. The
two do not have the same fix.

## 2.3 · T7 — cache headers

On `/consultant/shops/monthly-sales`, `/consultant/shops/sales-kpis`,
`/consultant/targets`, `/consultant/shops/category-sales`,
`/consultant/shops/pnl-summary`:

```
Cache-Control: private, max-age=300
ETag: "abc123"
```

Suggested `max-age`: **86400** when the requested window lies entirely in the
past — a closed month never changes — and **300** for anything covering today.

Today the panel guesses its own TTLs per endpoint. Those guesses are now
*measured* (§0.6, the `Cache` column): if a screen shows 90 % cache and is still
slow, the TTL is not the problem. Your headers replace our guesses.

## 2.4 · T2 — write the review POST contract down

We currently send every field **twice** to cover both spellings — `rating` *and*
`review_rating`, `comment` *and* `review_comment`, `is_accepted` *and*
`review_is_accepted`. One documented field list deletes the duplicates.

Also needed: the exact error shape on refusal (422 vs 400, and where the message
lives), because the panel now queues reviews offline and replays them. A refusal
must stay visible to the consultant; a transport failure must be retried. Those
two must be told apart from the response alone.

---

# Part 3 — Endpoints that do not exist yet

**Seven URLs.** In priority order.

## 3.1 · T11 — every task of the network, for one day  ⚠ *most expensive screen*

```
GET /consultant/network/tasks?date=2026-07-30
GET /consultant/network/tasks?date=2026-07-30&shop_ids=2,4,7
```

Replaces `1 + N + N + M` calls — **31 requests for 5 shops, 181 for 30** — with
one. This is the screen a consultant opens every morning, and the one the
heatmap shows red at every hour.

```json
{
  "date": "2026-07-30",
  "shops": [
    {
      "shop_id": 4,
      "shop_name": "Atelier by – Halle",
      "shop_city": "Halle",
      "completion_rate": 62,
      "checklists": [
        {
          "id": 43,
          "name": "CO-01 — Ouverture",
          "tasks": [
            {
              "task_id": 1216,
              "task_name": "Vérifier si le magasin est propre.",
              "status": "DONE",
              "is_mandatory": 1,
              "requires_photo": 1,
              "completed_by": "Nathan C.",
              "completed_at": "2026-07-30 04:40:00",
              "note": null,
              "completion_id": 169,
              "attachment_id": 393,
              "review_id": 55,
              "review_rating": 5,
              "review_is_accepted": 1,
              "review_comment": null
            }
          ]
        }
      ]
    }
  ]
}
```

Every field already exists in one of the three endpoints we call today. **This
ticket is a join, not a feature.** If **T3** ships first, it becomes a loop over
shops.

## 3.2 · T9 — checklist progress over a date range

```
GET /consultant/shops/{shopId}/checklists/progress?from=2026-05-01&to=2026-05-31
```

`from` and `to` inclusive. The payload is exactly what the two single-day
endpoints return, grouped by day:

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

The only ticket that **deletes** code on our side: `mac_checklist_day_snapshot`
and its freezing logic — the logic that has already frozen days at zero and had
to be taught to heal itself.

A cap is fine — say 62 days. **Name the limit and the error above it**, and we
split the range client-side. Silent truncation is the one thing we cannot work
with: a month that comes back short reads as a month where nothing happened.

## 3.3 · T4 — material complaints, all shops

```
GET /consultant/shops/material-complaints
GET /consultant/shops/material-complaints?status=OPEN
GET /consultant/shops/material-complaints?from=2026-07-01&to=2026-07-31
```

All parameters optional. Without them: everything the single-shop endpoint would
return, for every shop the consultant may see.

```json
{
  "shops": [
    { "shop_id": 1,
      "complaints": [
        { "id": 10, "reported_at": "2026-07-28 08:12:00", "status": "OPEN" }
      ] },
    { "shop_id": 2, "complaints": [] }
  ]
}
```

Each complaint object is **exactly** what the single-shop endpoint returns
today — don't reshape it, we already parse that form.

Today: one parallel call per shop. **25 concurrent requests, 25 authorisation
checks, 25 database round trips** for one screen.

## 3.4 · T5b — monthly P&L, all shops

```
GET /consultant/shops/pnl/monthly?from=2025-08&to=2026-07
```

Months (`YYYY-MM`), inclusive. Same envelope as `/consultant/shops/monthly-sales`:

```json
{
  "shops": [
    { "shop_id": 1,
      "months": [
        { "month": "2025-08", "turnover": 52000, "material": 18000,
          "labour": 15000, "overhead": 9000, "result": 10000 }
      ] },
    { "shop_id": 2, "months": [] }
  ]
}
```

Same month object as the single-shop endpoint — including **T5a**: `material`
always present.

## 3.5 · T6 — Owner countersigns a review

```
POST   /consultant/shops/{shopId}/task-reviews/{reviewId}/validate   { "validated": true }
```

```json
{ "success": true,
  "owner_validated_at": "2026-07-30 14:02:00",
  "owner_validated_by_name": "Marc O." }
```

`{"validated": false}` clears it. Needs the columns of §1.1, and adds three
fields to each reviewed task in `/progress`: `owner_validated_at`,
`owner_validated_by`, `owner_validated_by_name`.

If this is out of scope, say so — the panel keeps storing it in local columns,
and we stop asking.

## 3.6 · T10 — the sector list, as data

```
GET /consultant/product-sectors
```

```json
{ "sectors": [ { "id": 1, "name": "Boulangerie" }, { "id": 2, "name": "Traiteur" } ] }
```

The list as **data**, not only as a dropdown inside the product form. Sectors
hard-coded on our side would drift the day one is renamed.

## 3.7 · T12 — six quarters of sales KPIs, per shop

```
GET /consultant/shops/sales-kpis/quarterly?quarters=6
GET /consultant/shops/sales-kpis/quarterly?quarters=6&shop_ids=2,4,7&end=2026-Q2
```

```json
{
  "quarters": ["2025-Q1","2025-Q2","2025-Q3","2025-Q4","2026-Q1","2026-Q2"],
  "shops": [
    { "shop_id": 4,
      "series": [
        { "quarter": "2025-Q1", "tickets": 17800, "ca": 336000.00,
          "products": 35600, "avg_basket": 18.88, "products_per_ticket": 2.0 },
        { "quarter": "2025-Q2", "tickets": null, "ca": null,
          "products": null, "avg_basket": null, "products_per_ticket": null }
      ] }
  ]
}
```

Same object shape as `sales-kpis` — a quarter must look like a window.

Two rules specific to this payload:

1. **Every requested quarter is present for every requested shop**, even before
   the shop existed — as `null`, never `0`. Zero means "sold nothing"; a
   sparkline drawing zero for a shop that did not exist yet invents a collapse.
2. **The current quarter is partial and says so** — an `is_partial: true` flag,
   or its window bounds. A third of a quarter drawn next to five full ones shows
   a crash that isn't there.

Without it: 18 monthly windows *per shop* — 90 calls at 5 shops, 540 at 30. The
KPI modal ships **today without the chart**; it lights up the day this exists.

---

# Part 4 — Three rules for every payload above

Not style preferences. Each one comes from a bug we have already had.

1. **Every requested entity is present, even when empty.** A shop with no task,
   a month with no material, a day with no checklist: present, with zeros. A
   missing row reads as a missing shop, and we cannot tell the two apart.
2. **A key is never dropped to save bytes.** `review_rating: null` and no
   `review_rating` key mean different things. The first says *not reviewed*; the
   second says *nothing*.
3. **Object shapes never change between endpoints.** A task from the network
   endpoint must look like a task from the shop endpoint. Two shapes for one
   thing means two parsers, and the second one always rots.

**Why round trips matter more than milliseconds.** The panel's HTTP client times
out after **10 s per call**, with at most 24 in flight. A screen needing N
sequential calls can be cut off by the gateway. Turning "N calls" into "1 call"
is worth more to us than making any single call faster — and §0.6 now proves it
per screen.

---

# Part 5 — Order of work, and how to check each one

| Order | Ticket | Why this rank |
|---|---|---|
| 0 | **Part 0** | half a page, no API work, and it ends every argument about whose latency it is |
| 1 | **T5a** | a wrong margin, shown as fact. One line of SQL |
| 2 | **T8** | a wrong revenue, on every screen |
| 3 | **T1** | unlocks the deletion of our review journal |
| 4 | **T3** | cheap alone, and makes T11 nearly free |
| 5 | **T11** | the daily screen: 181 requests → 1 |
| 6 | **T9** | deletes our snapshot table *and* its freezing logic |
| 7 | T2, T7 | a document, and six headers |
| 8 | T4, T5b, T6, T10 | fan-outs and reference data |
| 9 | T12 | a sparkline; the modal already ships without it |

T5a and T8 come first because they are the only two about figures being
**wrong**. Everything else makes the panel faster, simpler or richer.

## Acceptance — one line each

`$API` and `$TOKEN` as usual. `$PANEL` is the panel's base URL.

| # | Run this | Done when |
|---|---|---|
| Part 0 | `curl -s "$PANEL/system/db-setup" \| jq '.tables.mac_consultant_perf.exists'` | `true` |
| T1 | `curl -s "$API/consultant/shops/4/checklists/44/progress?date=2026-07-30" -H "Authorization: Bearer $TOKEN" \| jq '[.tasks[] \| select(.review_id != null) \| has("review_by")] \| all'` | `true` |
| T2 | — a document, not an endpoint | the contract, written down |
| T3 | `curl -s "$API/consultant/shops/4/tasks?date=2026-07-30" -H "Authorization: Bearer $TOKEN" \| jq '[.data.tasks[] \| has("completion_id")] \| all'` | `true` |
| T4 | `curl -s -o /dev/null -w '%{http_code}' "$API/consultant/shops/material-complaints?shop_ids=2,4" -H "Authorization: Bearer $TOKEN"` | `200` |
| T5a | `curl -s "$API/consultant/shops/4/pnl/monthly?year=2026" -H "Authorization: Bearer $TOKEN" \| jq '[.data.months[] \| has("material")] \| all'` | `true` |
| T5b | `curl -s -o /dev/null -w '%{http_code}' "$API/consultant/shops/pnl/monthly?from=2025-08&to=2026-07" -H "Authorization: Bearer $TOKEN"` | `200` |
| T6 | `curl -s -o /dev/null -w '%{http_code}' -X POST "$API/consultant/shops/4/task-reviews/1/validate" -H "Authorization: Bearer $TOKEN"` | not `404` |
| T7 | `curl -sI "$API/consultant/shops/sales-kpis?date_from=2026-06-01&date_to=2026-06-30" -H "Authorization: Bearer $TOKEN" \| grep -i cache-control` | a `Cache-Control` line |
| T8 | the three curls of §2.2, same shop, same **closed** month | the same figure, three times |
| T9 | `curl -s -o /dev/null -w '%{http_code}' "$API/consultant/shops/4/checklists/progress?from=2026-07-01&to=2026-07-31" -H "Authorization: Bearer $TOKEN"` | `200` |
| T10 | `curl -s "$API/consultant/product-sectors" -H "Authorization: Bearer $TOKEN" \| jq '.sectors \| length > 0'` | `true` |
| T11 | `curl -s -o /dev/null -w '%{http_code}' "$API/consultant/network/tasks?date=2026-07-30" -H "Authorization: Bearer $TOKEN"` | `200` |
| T12 | `curl -s "$API/consultant/shops/sales-kpis/quarterly?quarters=6" -H "Authorization: Bearer $TOKEN" \| jq '.quarters \| length'` | `6` |

## What we owe you, in return

Three answers are ours, not yours, and we are holding them up:

1. **What the panel should DO with `is_pdm`** (§1.2). We will carry the field
   and display nothing until this is decided.
2. **The `/api-debug?ca=1` ticket counts** behind the three revenue sources
   (§2.2) — we produce them, you diagnose T8 from them.
3. **Which figure is authoritative per screen**, once T8 answers what each
   source counts.

Ping us on any of the three and it stops blocking.
