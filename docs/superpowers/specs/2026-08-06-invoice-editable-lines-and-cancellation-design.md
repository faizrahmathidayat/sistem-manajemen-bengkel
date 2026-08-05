# Invoice: Editable Lines & Cancellation — Design

## Background

The Sales Invoice module (migration 009, `docs/superpowers/plans/2026-08-05-sales-invoice-module.md`) shipped with an Invoice that snapshots 1:1 from a completed PKB, and lets a draft invoice's header (discount %, PPN %, notes) be edited before posting. User feedback after using it identified two gaps:

1. Only the header is editable. In real operation, the *lines themselves* (qty, price, and which items are billed) can change before posting — a sparepart might not actually be needed, or the customer requests something not on the original PKB.
2. Invoice only has `draft`/`posted` in practice. There is no way to cancel a draft invoice (e.g. the customer decides not to proceed with service after a shortage), even though `InvoiceStatus::CANCELLED` already exists as an unused constant.

## Decisions (confirmed with the user)

1. **Free-form lines are allowed.** An invoice line does not have to trace back to a PKB line. This is intentional: the divergence between what was on the PKB and what's actually billed is exactly the data the future "PKB vs Invoice" gap report (`report.invoice_pkb_gap.view`, already seeded as a sidebar placeholder) needs.
2. **Removing a sparepart line from a draft invoice immediately releases that line's PKB reservation.** The stock becomes available to other PKBs right away, not on posting or cancellation.
3. **A cancelled invoice is permanent history.** The `invoices.work_order_id` unique constraint stays exactly as-is. A PKB whose invoice was cancelled can never get a new invoice through the normal flow — this is by design, not a bug to fix later.
4. **Cancelling a draft invoice releases every reservation still tied to it.** Matches the "customer decided not to proceed" scenario — nothing should stay reserved for a sale that isn't happening.

## What changes

### Schema

`invoice_details` gains a direct `sparepart_branch_id` (nullable FK) — the stock-deduction target for a sparepart-type line, independent of whether it traces to a PKB line. The old CHECK requiring *exactly one* of `work_order_service_line_id` / `work_order_sparepart_line_id` is replaced with one that only forbids *both* being set (both null — a free-form line — is now valid), plus a new CHECK that every `item_type = 'sparepart'` row has a `sparepart_branch_id`.

`invoices` gains `cancel_reason`, `cancelled_by`, `cancelled_at` — mirrors the PKB shortage-override columns (`shortage_override_reason`/`shortage_overridden_by`/`shortage_overridden_at`) already in this codebase.

### Service layer

`InvoiceController::update()`'s current header-only recalculation is replaced by `InvoiceService::updateInvoice()`, which now does a full **sync** of the lines (delete-all, recreate-from-submission — the same pattern `WorkOrderController::syncServiceLines()`/`syncSparepartLines()` already use), diffs the PKB-sourced sparepart lines that existed before against what's left after, and releases reservations for any that were dropped.

`InvoiceService::cancelInvoice()` is new: locks the invoice, verifies it's still `draft`, releases every remaining reservation still tied to the invoice's PKB-sourced sparepart lines, and marks it `cancelled` with a required reason. Both methods share a `releaseReservationsForLines()` helper (same lock order — ascending `sparepart_branch_id` — as every other reservation-touching path in this codebase, to avoid the AB-BA deadlocks those existing code comments warn about).

### UI

The invoice edit form gains a line-item editor identical in spirit to the PKB edit form (Select2 AJAX sparepart picker, `+ Tambah Jasa` / `+ Tambah Sparepart`, remove-row buttons). Lines that trace to a PKB line render with the item **locked** (plain text, not a picker) — only qty/price are editable for those, so a PKB-traced line can't silently start pointing at a different sparepart out from under the gap report. Free-form lines get the full picker.

The invoice show page gains a "Batalkan" action (permission `invoice.void`, already seeded) requiring a reason — same UX pattern as the PKB shortage-override form — and, once cancelled, displays who cancelled it, when, and why (same display pattern as the PKB shortage-override info card, including the same "show it after status has moved on" fix just applied there).

## Explicitly out of scope

- The "PKB vs Invoice" gap report itself (reporting module, not this feature).
- Any change to `WorkOrder`'s own lifecycle/status because its invoice was cancelled — the PKB stays `completed`, permanently un-invoiceable, per decision 3.
- Editing a *posted* invoice, or un-posting.
