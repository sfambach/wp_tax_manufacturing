# Taxmod Manufacturing («Fertigung»)

Build orders for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler), shown inside the logistics
navigation of [Taxmod Stock](https://github.com/sfambach/wp_tax_stock) under **Fertigung › Aufträge**.

- A **build order** names a project and a piece count. It holds one **assembly** per board of the project, built from the
  board's newest revision.
- Each assembly has a **build status**: offen → Teile da → bestückt → geprüft → fertig.
- An assembly also lists the **parts-list positions still missing** (for example, every board fitted except the coil) and
  takes a note.
- An order closes once every assembly is done. Cancelling and closing can both be undone with «Wieder aufnehmen».
  Nothing is ever deleted.

Concept and decisions: [`docs/pakete/fertigung/konzept.md`](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/fertigung/konzept.md),
D-1142 and D-1143 in the modeller's decision log.

## Requirements

- Taxonomy Modeller with the abilities `taxmod/read-node` and `taxmod/write-data`.
- Taxmod Stock with the navigation area «production» for the menu entry.
- The model change `fertigung-apply.json` (nodes «Fertigungsaufträge», «Fertigung Baugruppen», the choices «Fertigungsstatus»
  and «Aufbaustatus»). Its ids go into the option `taxmod_manufacturing_structure` (REST `/wp/v2/settings`) or into
  `structure-live.json`. Until all of them are there, the page lists the missing keys.

## Layout

- `src/Core`: domain, no WordPress (CD-1). It reads orders (`BuildOrders`) and builds the change lists (`OrderChanges`).
- `src/WordPress`: the boundary: the model gateway, the service and the admin page.
- `tests/Core`: PHPUnit, no WordPress.

## Not yet built

- Booking at «fertig» (D-1143): consumption of the parts and receipt of the finished board as its own material, then
  inspection. This needs an interface from the stock module.
- Moving «Projekt-Gebinde» (D-1139) over from the stock module.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.

## License

GPL-2.0-or-later.
