# Demo sample inputs

Typed and scanned sources an agent can feed to `siteworks.skill_import_catalogue_from_source` on the Camino Bakehouse portal. They are not imported by `demo:seed`; the starter catalogue is already in the database. These files are the *next* intake — a fall counter menu that overlaps one existing tart so the skill can practise matching.

All four input files carry the same nine items in the same order. Two of them have no usable price on purpose: `Pumpkin Praline Tart` (smudged on the flyer) and `Celebration Cake` ("ask us"). A correct import never invents a number for either: passed with an empty price (or `price_pence` null), each is still drafted at no price and carries a `price_missing` review note, so the portal shows it as *Needs review* until a human enters the price.

## Files

| File | What it is |
|---|---|
| `counter-menu-fall.png` | A phone photo of the printed counter menu taped to the bakery counter glass. The paper is creased and the price for `Pumpkin Praline Tart` is torn and smudged, leaving only a faint `$`. `Celebration Cake` reads "ask us". Everything else is legible. Use this to exercise image extraction. |
| `price-list.txt` | The same list typed up as plain text, one product per line, prices in USD. `Pumpkin Praline Tart $?` stands in for the smudged price; `Celebration Cake — ask us` is the ask-style price. Start here. |
| `price-list.pdf` | The same list as a one-page PDF headed "CAMINO BAKEHOUSE / Fall counter menu - prices from this week", names on the left and prices on the right. Text is selectable. |
| `price-list.docx` | The same list as a Word document headed "Camino Bakehouse / Fall counter menu (typed up from the counter flyer)"; the smudged price is written as `$?  (price smudged on the flyer)`. |

`Fig & Walnut Tart $5.50` appears in every file and already exists on the seeded catalogue. `import_products` reports that row as `matched` (with the existing slug) and leaves the product alone; only `force_create` or a slug of its own would create a second one.

## Using `skill_import_catalogue_from_source`

1. Sign in to the client portal (`http://app.localhost:8090`) as the demo user and open **Products**.
2. Call `siteworks.skill_import_catalogue_from_source`. It returns a protocol, not a write. Follow that protocol:
   - `get_site_context` and `get_brand_system` first.
   - `describe_import_products` for the field spec.
   - Extract items from **one** of these files (start with `price-list.txt`). Keep merchant names verbatim. Leave `$?`, the smudged flyer price, and “ask us” prices empty; never invent a number. The import drafts them with a `price_missing` note.
   - `list_products` and match against the existing catalogue. `Fig & Walnut Tart` is a probable match — do not create a duplicate; ask. The import's dry run reports exact name matches as `matched` too.
   - Categories must already exist. `Seasonal — Fall` (`seasonal-fall`) is seeded empty for these fall items. Create any other missing category with `manage_category` before import.
   - `import_products` with `dry_run` first, then commit with `catalogue_revision` (alias `expected_revision`) and a fresh `idempotency_key`.
3. Import drafts only. Publishing stays a human action in the UI.

The protocol text lives on the tool itself (`SkillImportCatalogueFromSourceOperation`); this README does not replace it.
