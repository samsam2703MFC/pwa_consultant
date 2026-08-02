# Build sheet — what to change, in order

> **Start with `DEV_HANDOFF.md`.** It is the single to-do list: the infra half
> page (Part 0), then these same tickets with their payloads inline. This file
> and `BACKEND_SPEC.md` remain the reference behind it.

The full reasoning, the JSON before/after and the acceptance tests live in
`BACKEND_SPEC.md`. **This file is the short version: the columns to add, and the
endpoints to create.** Nothing else.

Three sections, in the order they should be done:

1. **Database** — three tickets need new columns. Everything else is query work.
2. **Existing endpoints to modify** — fields to add to a payload that already exists.
3. **New endpoints to create** — seven URLs that do not exist yet.

Column and table names below are **suggestions**. Use your own; only the
*meaning* and the *nullability* matter, because those are what the panel reads.

---

## 1. Database — the only three tickets that touch a table

### 1.1 · Task reviews — who reviewed, and who validated  (T1, T6)

The table that stores a consultant's review of a completed task holds the
verdict today (`rating`, `is_accepted`, `comment`) but **not its author**. The
panel keeps its own journal to fill that gap; the journal only knows about
reviews posted through the panel, which is exactly why it has to go.

| Column | Type | Null | Why |
|---|---|---|---|
| `reviewed_by` | `BIGINT UNSIGNED` | no | the consultant who judged. **T1** |
| `reviewed_at` | `DATETIME` | no | when. **T1** |
| `owner_validated_at` | `DATETIME` | yes — null until validated | the Owner countersigns a consultant's review. **T6** |
| `owner_id` | `BIGINT UNSIGNED` | yes | who countersigned. **T6** |

```sql
ALTER TABLE task_review
  ADD COLUMN reviewed_by        BIGINT UNSIGNED NOT NULL,
  ADD COLUMN reviewed_at        DATETIME        NOT NULL,
  ADD COLUMN owner_validated_at DATETIME        NULL,
  ADD COLUMN owner_id           BIGINT UNSIGNED NULL,
  ADD KEY idx_reviewed_by (reviewed_by, reviewed_at);
```

**Backfill.** Existing rows have no author. Either set `reviewed_by` to a
service account and `reviewed_at` to `created_at`, or make the two columns
nullable — but say which, because "no author" and "author unknown" are not the
same thing to a screen that prints *Vérifié par …*.

### 1.2 · Products — the PDM flag and the sector  (T10)

Two additions on the product, and one new reference table.

| Column | Type | Null | Why |
|---|---|---|---|
| `is_pdm` | `TINYINT(1)` | **no**, default `0` | a product is PDM or it is not. Never null, never absent — otherwise "not PDM" and "unknown" become the same value |
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

**The decision we need from you, not the other way round:** the product form
must *require* a sector from now on, but the existing catalogue has none.

- **Backfill them** → `sector_id` becomes `NOT NULL`, and every payload carries a
  sector. Cleanest.
- **Leave them null** → tell us, and we display *« secteur non renseigné »*.

Either is fine. What we must not do is count those products as zero in a
breakdown, and that is exactly what happens if nobody decides.

### 1.3 · Nothing else touches a table

T2, T3, T4, T5a, T5b, T7, T8, T9 and T11 are query, payload or header work. No
migration.

---

## 2. Existing endpoints to modify — fields to add

| Endpoint | Add | Ticket |
|---|---|---|
| `GET /consultant/shops/{id}/checklists/{cid}/progress` | `review_by`, `review_by_name`, `reviewed_at` on each task | **T1** |
| `GET /consultant/shops/{id}/tasks` | `completion_id`, `attachment_id`, `review_rating`, `review_is_accepted`, `review_comment` on each task | **T3** |
| `GET /consultant/shops/{id}/pnl/monthly` | **`material` present on every month**, `0` when there is nothing — never an absent line | **T5a — blocking** |
| `GET …/monthly-sales`, `…/sales-kpis`, `…/pnl/monthly` | nothing to add: make the **three revenues agree**, or document which one is authoritative | **T8** |
| 5 read-only aggregates | a `Cache-Control` header | **T7** |
| `POST /consultant/shops/{id}/task-reviews` | nothing to add: **write down the contract**. We currently send every field twice to cover both spellings | **T2** |

`GET /consultant/shops/{id}/product-category-groups` and any product payload
must also carry `is_pdm`, `sector_id` and `sector_name` once section 1.2 is
done — **T10**.

---

## 3. New endpoints to create

Seven, in priority order. Each links to its full specification.

### 3.1 · `GET /consultant/network/tasks?date=YYYY-MM-DD`  — **T11**

Every task of the network for one day, grouped **shop › checklist › task**, with
the review already joined. Optional `shop_ids=2,4,7`.

Replaces `1 + N + N + M` calls — **31 requests for 5 shops, 181 for 30** — with
one. This is the screen a consultant opens every morning.

> If **T3** ships first this becomes almost free: a loop over shops.

### 3.2 · `GET /consultant/shops/{id}/checklists/progress?from=…&to=…`  — **T9**

The checklists and their tasks for a **range of days**, grouped by day. Same
payload as the two single-day endpoints.

The only ticket that **deletes** code on our side: the snapshot table and its
freezing logic — the one that has already frozen days at zero.

### 3.3 · `GET /consultant/shops/material-complaints?shop_ids=…&from=…&to=…`  — **T4**

Material complaints for several shops in one call. Replaces one parallel call
per shop.

### 3.4 · `GET /consultant/shops/pnl/monthly?from=2025-08&to=2026-07`  — **T5b**

Monthly P&L for several shops in one call. Same shape as the single-shop
endpoint, keyed by shop. Months (`YYYY-MM`), inclusive.

### 3.5 · `POST /consultant/shops/{shopId}/task-reviews/{reviewId}/validate`  — **T6**

The Owner countersigns a consultant's review. Needs the columns of §1.1.
`DELETE` on the same URL removes the validation.

### 3.6 · `GET /consultant/product-sectors`  — **T10**

The sector list as **data**, not only as a dropdown in the product form.
Sectors hard-coded on our side would drift the day one is renamed.

### 3.7 · `GET /consultant/shops/sales-kpis/quarterly?quarters=6`  — **T12**

Six quarters of sales KPIs per shop, in one call. Feeds the sparkline of the
KPI modal on the *Boutiques* screen.

Without it: 18 monthly windows per shop — 90 calls at 5 shops, 540 at 30. The
modal ships today **without** the chart; it lights up when this exists.

---

## Three rules that apply to every payload above

They are not style preferences. Each one comes from a bug we have already had.

1. **Every requested entity is present, even when empty.** A shop with no task,
   a month with no material, a day with no checklist: present, with zeros. A
   missing row reads as a missing shop, and we cannot tell the two apart.
2. **A key is never dropped to save bytes.** `review_rating: null` and no
   `review_rating` key at all mean different things. The first says "not
   reviewed", the second says nothing.
3. **Object shapes do not change between endpoints.** A task returned by the
   network endpoint must look like a task returned by the shop endpoint. Two
   shapes for one thing means two parsers, and the second one is always the one
   that rots.

---

## Suggested order

| Order | Ticket | Why this rank |
|---|---|---|
| 1 | **T5a** | a wrong margin, shown as fact. One line of SQL |
| 2 | **T8** | a wrong revenue, on every screen |
| 3 | **T1** | unlocks the deletion of our review journal |
| 4 | **T3** | cheap on its own, and makes T11 nearly free |
| 5 | **T11** | the daily screen, 181 requests → 1 |
| 6 | **T9** | deletes our snapshot table and its freezing logic |
| 7 | T2, T7 | a document, and six headers |
| 8 | T4, T5b, T6, T10 | fan-outs and reference data |
| 9 | T12 | a sparkline; the modal already ships without it |

T5a and T8 are first because they are the only two about figures being
**wrong**. Everything else makes the panel faster, simpler, or richer.
