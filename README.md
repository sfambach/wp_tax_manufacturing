# Taxmod Manufacturing («Fertigung»)

Build orders for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler), shown inside the logistics
navigation of [Taxmod Stock](https://github.com/sfambach/wp_tax_stock) under **Fertigung › Aufträge**.

- A **build order** names a project, a piece count and its **box** (a project box of the stock module). It holds one
  **assembly** per board of the project, built from the board's newest revision.
- Each assembly shows its **demand list**: the board itself and every parts-list position, «Anzahl × Stückzahl», with
  **on how many boards it is fitted**, what lies in the box, and what is missing (D-1145, D-1146). «Best.» = nein drops
  out; an optional position can be marked «nicht verwenden». «Alle auf n» fills every count at once.
- «Boards fitted» is the smallest count over the used positions. Saving books the difference as consumption at the stock
  module (filter `taxmod_stock_book_build`); without that interface the counts are saved and the page says nothing was booked.
- «Geprüft» and «Fertig» are counts per assembly. An order closes once every board is finished; cancelling and closing
  are undone with «Wieder aufnehmen». Nothing is ever deleted.

Concept and decisions: [`docs/pakete/fertigung/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/fertigung/konzept.md),
D-1142 and D-1143 in the modeller's decision log.

- Several people at once (D-1148, from 0.0.4): a step that books through the stock waits for the stock's other bookings, so
  two builds cannot use the same last part.

## Requirements

- Taxonomy Modeller with the abilities `taxmod/read-node` and `taxmod/write-data`. Taxonomy Modeller 0.2.67 or later (buttons in the toolbar above each form, D-1308).
- Taxmod Stock with the navigation area «production» for the menu entry.
- The model changes `fertigung-apply.json` and `fertigung-0.0.3-apply.json` (nodes «Fertigungsaufträge», «Fertigung Baugruppen»,
  «Fertigung Bestückung», the choices «Fertigungsstatus» and «Aufbaustatus»). Its ids go into the option `taxmod_manufacturing_structure` (REST `/wp/v2/settings`) or into
  `structure-live.json`. Until all of them are there, the page lists the missing keys.

## Layout

- `src/Core`: domain, no WordPress (CD-1). It reads orders (`BuildOrders`) and builds the change lists (`OrderChanges`).
- `src/WordPress`: the boundary: the model gateway, the service and the admin page.
- `tests/Core`: PHPUnit, no WordPress.

## Not yet built

- Receipt of a finished board as its own material and its inspection (D-1143).
- Moving «Projekt-Gebinde» (D-1139) over from the stock module.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.

## License

GPL-2.0-or-later.
