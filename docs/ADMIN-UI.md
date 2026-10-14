# KOP Tools screens: one look

Every review screen under KOP Tools follows the same rules, so a button, a dropdown and a filter mean the
same thing everywhere. The shared pieces are `inc/admin-kit.php` (markup), `css/admin-kit.css` and
`js/admin-kit.js`. Screens on the kit so far: Drive Docs, Fornits, Woodbury Facts.

## The rules

1. **Decisions are buttons.** The choices made about each item (Add / Skip, Approve / Reject; three at most)
   are visible buttons. The main one comes first and is always the primary colour (`kop_ak_button(.., 'primary')`).
   Reject, skip and delete are `danger`.
2. **Everything else is folded away.** The rest of an item's actions go in one "More" menu (`kop_ak_more()`) or a
   fold (`kop_ak_fold()`: another record, create one, edit). Never a fourth button in the row.
3. **A dropdown only picks a value.** A `<select>` never runs an action by itself; a button beside it does.
   (The filter toolbar is the one exception: picking a filter shows the list for it.)
4. **One filter toolbar over each list** (`kop_ak_filters()`): views as tabs with their counts (`kop_ak_tabs()`;
   a second row of `segments` for Waiting / Added / Skipped), then search, then dropdowns. Filters apply on change,
   live in the address (so a view can be bookmarked or shared), and "Clear filters" shows when any is set.
   A long list pages (`kop_ak_pager()`).
5. **Undo has one look and follows every action.** After Add, Skip or any other decision, the result line on the
   card says what happened and offers Undo (`js/admin-kit.js`). The screen's Added tab has Undo too.

## Pieces

| Piece | Function | Use |
|---|---|---|
| Header | `kop_ak_header($title, [lede, steps, help, notices])` | Title, one line saying what the screen is for, the how-to folded under "How this works" (remembered per screen) |
| Status strip | `kop_ak_status($html, $tools)` | Counts and last run; the screen's own tools (small buttons) at its end |
| Tabs | `kop_ak_tabs($tabs, $current, $url, [kind, label])` | Views with counts |
| Filters | `kop_ak_filters($hidden, $fields, $clear_url)` | Search box and dropdowns |
| Pager | `kop_ak_pager($paged, $pages, $total, $noun, $url)` | Previous / numbers / Next and the total |
| Button | `kop_ak_button($label, $variant, $attrs, $class)` | primary, secondary, danger, quiet |
| More menu | `kop_ak_more($items)` | Seldom-needed actions |
| Fold | `kop_ak_fold($summary, $body, $open)` | A picker with its own button |
| Sticky bar | `kop_ak_bar($html)` | Page-wide actions ("Add everything ticked on this page") |
| Card | `kop_ak_card_head()`, `kop_ak_pick()`, `kop_ak_card_actions()` | A card of ticked rows (`tr[data-key]`) with Tick all and its result line |

`KOPAdminKit.cards({action, nonce, words, whole, undoable, before, after})` runs a screen's cards: it collects the
ticked rows (and each row's `.kop-ak-target` choice), posts `act` + `keys` to the screen's `wp_ajax_` action,
greys out what was done, writes the result and adds Undo (`act: 'undo'` with the keys that went through).
A button with `data-other` takes the record from the card's `.kop-ak-fid` finder. The handler answers
`{results: {key: {ok, error, keep}}, url?, label?}`.

## Putting a screen on the kit

1. `require_once __DIR__ . '/admin-kit.php';` and
   `add_filter('kop_admin_kit_pages', function ($p) { $p[] = '<page slug>'; return $p; });`
2. Wrap the page in `<div class="wrap kop-ak ...">` and build it from the pieces above.
3. Keep only what the screen alone needs in its own style and script (Drive Docs' "Add all" loop, Woodbury's Edit box).
4. Its `undo` act takes rows by key, for both added and skipped rows.
5. Add the screen to `scripts/preview-admin-kit.php` and look at it at 390, 768 and 1280 px:
   `php -d extension=pdo_sqlite -d extension=mbstring scripts/preview-admin-kit.php` writes `tmp/admin-kit-preview/index.html`.

The order the rest follows is in `docs/PLAN.md` (Admin screens).
