# Design System Foundation + Navbar/Sidebar Redesign — Design

Status: approved by user, ready for implementation plan

## Purpose

The user wants a full visual redesign of the entire application ("redesain ulang tampilan keseluruhan"), starting with a detailed spec for a richer Dashboard. Because the Dashboard's navbar and sidebar are shared layout (`resources/views/layouts/app.blade.php`, `resources/views/partials/sidebar.blade.php`, `resources/views/partials/design-tokens.blade.php`) used by every existing screen, and the requested palette/typography (slate/charcoal + electric blue + emerald, Plus Jakarta Sans) fully replaces the current locked-in design system (burnt-orange accent, IBM Plex Sans, warm off-white background — see project memory `bengkel-foundation-decisions`), this cannot be scoped to the Dashboard alone.

This is sub-project 1 of a decomposed redesign effort:
1. **This spec** — design system tokens + Navbar/Sidebar (foundation every other screen depends on).
2. Dashboard (KPI cards, charts, tabbed live-data sections).
3. Master Data group 1 — Cabang, Customer, Kendaraan (+ Referensi Kendaraan).
4. Master Data group 2 — Mekanik, Jasa Service, Master Sparepart.
5. Administrasi — Users (list + detail 3-tab).

Each sub-project gets its own spec → plan → implementation cycle. This spec covers only #1.

## Scope

**In scope:**
- Replace every color/typography CSS custom property in `design-tokens.blade.php` with the new palette and font.
- Update every component rule in that file that hard-codes the old look (card shadow/radius, button radius, accordion highlight color, sidebar active-link highlight) to fit the new "modern, elegant, soft shadow" direction.
- Redesign the navbar (`layouts/app.blade.php`): add a quick-search input (UI-only, non-functional), a small set of permission badges for the logged-in user, and a notification bell with a static badge count.
- Redesign the sidebar (`partials/sidebar.blade.php`) to show the **full target menu structure** (Operasional, Persediaan, Master Data, Administrasi, Reporting) as a preview of where the system is going — existing real links keep working, links to modules that don't exist yet render as inert "coming soon" placeholders.
- Add a new `--color-warning` token (orange) — doesn't exist in the current system, needed for future stok-kritis/jatuh-tempo badges (not consumed yet in this sub-project, just defined).

**Explicitly out of scope / deferred:**
- Any change to Dashboard content, or to any Master Data / Administrasi screen's own layout — those are sub-projects 2-5.
- Making the quick-search functional, making the notification bell real-time, or making any "coming soon" sidebar item navigate anywhere — those activate as their respective backend modules (migrations 006-011) ship.
- A database-driven sidebar (rendering from the `menus` table instead of hardcoded Blade). The current hardcoded-per-link pattern already works and is already tested; this redesign only adds more hardcoded entries in the same style. Revisit if maintaining the hardcoded list becomes a real burden.

## Design tokens

Replace in `resources/views/partials/design-tokens.blade.php`:

```text
--font-sans: 'Plus Jakarta Sans' (replaces IBM Plex Sans; Google Font import URL updated accordingly)
--font-mono: unchanged (IBM Plex Mono) — not part of the requested change, still reads fine paired with Plus Jakarta Sans

--color-bg:            #F4F6F9  (was #F7F7F5 — cooler slate instead of warm off-white)
--color-surface:       #FFFFFF  (unchanged)
--color-ink:           #0F172A  (was #1C1B19 — slate-900/charcoal instead of warm near-black)
--color-ink-muted:     #64748B  (was #6B6862 — slate-500)
--color-border:        #E2E8F0  (was #E5E2DC — slate-200)
--color-sidebar:       #0F172A  (was #161513 — cooler charcoal, matches new ink)
--color-sidebar-ink:           rgba(241, 245, 249, .68)  (was rgba(247,247,245,.68))
--color-sidebar-ink-active:    #FFFFFF (unchanged)
--color-accent:        #2563EB  (was #E8622C — electric blue)
--color-accent-dark:   #1D4ED8  (was #C6501E — hover/active state)
--color-success:       #10B981  (was #3F7D58 — emerald)
--color-danger:        #DC2626  (was #B3432E)
--color-warning:       #F59E0B  (NEW — not present in the old system; defined now, first real consumer is the Dashboard sub-project's stok-kritis/piutang-jatuh-tempo badges)
```

Component-rule updates in the same file:
- `.card`: `box-shadow: none` → a soft shadow (`0 1px 2px rgba(15, 23, 42, .04), 0 4px 12px rgba(15, 23, 42, .04)` — subtle, not a heavy drop shadow), `border-radius: .5rem` → `.75rem`.
- `.btn`: `border-radius: .4rem` → `.5rem`.
- `.accordion-button:not(.collapsed)` highlight color: switches from the orange-tinted `rgba(232, 98, 44, .06)` to a blue-tinted `rgba(37, 99, 235, .06)` (derives from the new `--color-accent`, not hardcoded independently — same pattern the current file already uses elsewhere via CSS variables, this one rgba literal is the only exception and should be fixed to reference the variable properly while touching this file).
- `#sidebar .nav-link.active` background: `rgba(232, 98, 44, .14)` → `rgba(37, 99, 235, .14)` (same fix — derive from `--color-accent`).
- Every other component rule (`.status-dot`, `.stat-card`, `.nav-tabs .nav-link.active`, `.table thead th`) already references CSS variables exclusively and needs no direct edits — the token swap above is sufficient.

## Navbar (`layouts/app.blade.php`)

- **Quick search**: a text input with a search icon, placed between the brand and the right-side user area. Placeholder text: "Cari No. PKB, No. Polisi, Kode Sparepart, No. Invoice..." No `action`/submit handler in this sub-project — visually present, inert. A later sub-project wires it once the underlying modules exist to search.
- **Permission badges**: next to the user's name, render up to 3 of the user's **global** permission codes as small `<code>`-styled pill badges (`auth()->user()->permissionCodes()`, already exists), plus a "+N lainnya" badge if the user holds more than 3. Branch-scoped permissions are not shown here — no branch context in the navbar, and the badge purpose is a quick global-access glance, not a full permission audit (that's what the Users → Permission tab is for).
- **Notification bell**: a bell icon with a small numeric badge. The count is a **static placeholder value** in this sub-project (not wired to real stock/due-date data — `sparepart_branch_stocks.on_hand_qty` is always 0 until migration 008, so a "real" critical-stock count today would be uniformly alarming and misleading). Revisit once migration 008 gives stock a writer.

## Sidebar (`partials/sidebar.blade.php`)

Render the full target menu structure under five headings, in this order: **Operasional, Persediaan, Master Data, Administrasi, Reporting** (reordered from today's Master Data-first layout to match the business-priority ordering implied by the user's spec).

**Revised per user feedback**: placeholder items are NOT shown unconditionally — every item, real or placeholder, is gated by its corresponding permission code, exactly like real items already are. This uses the permission catalog that already exists in the `menus`/`permissions` tables (seeded by `MenuPermissionSeeder`, see the full listing below) — the menu structure itself stays hardcoded in Blade (not rendered from a DB query), only the visibility check changes from "always show" to "check the matching code."

Each heading lists its items in this shape (permission code → gating call, `[branch-scoped]` uses `$user->branchesWithPermission($code)->isNotEmpty()`, `[global]` uses `@can($code)`):

**Operasional** (all placeholder — migration 006/009/010 not built):
- Perintah Kerja Bengkel → `pkb.view` [branch-scoped]
- Invoice → `invoice.view` [branch-scoped]
- Penerimaan Pembayaran → `payment.view` [branch-scoped]

**Persediaan** (Master Sparepart real, rest placeholder — migration 008 not built):
- Master Sparepart → `route('sparepart-branches.index')`, real, gated by existing `branchesWithPermission('sparepart.view')` (unchanged)
- Penerimaan Barang → `receipt.view` [branch-scoped]
- Stock Adjustment → `stock_adjustment.view` [branch-scoped]
- Transfer Stock → `stock_transfer.view` [branch-scoped]
- Kartu Stok → `sparepart.view` [branch-scoped] (no dedicated permission code exists for stock-ledger viewing yet — it's conceptually part of the sparepart-viewing permission family until migration 008 introduces its own, if ever)

**Master Data** (all real, unchanged links and gating, just re-skinned):
- Cabang → `branch.view` [global], Customer → `customer.view` [global], Kendaraan → `vehicle.view` [global], Referensi Kendaraan → `vehicle_reference.view` [global], Mekanik → `mechanic.view` [global], Jasa Service → `service.view` [global]

**Administrasi** (Users real; Audit Log placeholder — migration 011 not built):
- Users → `route('users.index')`, real, gated by existing `@can('user.view')` (unchanged)
- Audit Log → `audit_log.view` [global] (this permission code already exists in the catalog even though the feature doesn't — `audit_logs` table itself is deferred, see project roadmap memory)

**Reporting** (all placeholder — migration 011 not built):
- Laporan PKB → `report.pkb.view` [branch-scoped], Laporan Invoice → `report.invoice.view` [branch-scoped], Laporan Piutang → `report.receivable.view` [branch-scoped], PKB vs Invoice → `report.invoice_pkb_gap.view` [branch-scoped], Laporan Sparepart → `report.sparepart.view` [branch-scoped]

**Placeholder item markup**: a `<span>` (never an `<a href>`, so there is no dead link and no `route()` call that could throw `RouteNotFoundException`), muted color, `cursor: not-allowed`, and a small "Segera Hadir" badge — wrapped in the same `@can(...)` / `branchesWithPermission(...)` conditional a real link for that code would use.

A consequence worth stating explicitly: a user who holds zero permissions for a given heading now sees the heading itself disappear entirely (same as today's behavior for `Master Data`/`Administrasi`, extended to the three new headings) — nobody sees an empty "Operasional" heading with nothing under it.

"User Branches" and "User Permissions" (menu codes `administrasi.user_branches` / `administrasi.user_permissions`) are **not** separate sidebar entries — they are tabs inside the Users detail page (`users/show`), not standalone routes, and have never had their own top-level page.

## Testing

- Existing `AppShellTest` assertions that check specific link visibility (`assertSee`/`assertDontSee` on real routes like `branches.index`, `sparepart-branches.index`, etc.) must keep passing unchanged — the gating logic for real links is not touched, only their heading grouping and CSS classes.
- New test: a placeholder item (e.g. "Perintah Kerja Bengkel") renders as inert text (not a link) for a user holding the matching branch-scoped permission (`pkb.view` in at least one branch), and the page does not error — proves no `route()` call is made for it even when visible (the strongest regression guard against someone later "fixing" a placeholder into an `<a href="{{ route(...) }}">` for a route that doesn't exist).
- New test: that same placeholder item is absent for a user who does NOT hold `pkb.view` in any branch — proves placeholders are genuinely gated, not shown unconditionally.
- New test: a user holding zero permissions anywhere sees none of the three new headings (Operasional, Persediaan beyond what they already have, Reporting) at all.
- Manual verification (no visual/screenshot test infra in this project): load `/dashboard` as `faiz_rahmat`, confirm new colors/font render, confirm placeholder items show "Segera Hadir" styling and are not clickable, confirm real links (Cabang, Sparepart, Users, etc.) still navigate correctly.

## Execution

Recommend **`subagent-driven-development` in a worktree**, matching this project's established process for multi-file, cross-cutting changes. This one is lower security-risk than the sparepart migration (no auth logic touched, purely presentational) but has the widest blast radius of any change so far — every single existing screen inherits it — so per-task review before merge still matters, particularly to catch any hardcoded old-palette color (`#E8622C`, `#B3432E`, `#3F7D58`, IBM Plex Sans references) left behind outside `design-tokens.blade.php` itself.
