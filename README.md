# splinductions: SPL induction bundles from the wiki

DokuWiki plugin for the Sydney Propulsion Lab wiki. It builds the induction bundle that the
**SPL Inductions** Home Assistant add-on imports (the same `format: 1` JSON as
`tools/build_inductions.py` produced) from wiki pages tagged `induction`, and publishes it as a
media file the app downloads.

Requires the **tag**, **approve** and **sqlite** plugins (all part of the hassio-dokuwiki add-on).

## How it works

- **Which pages:** every page in `whs:inductions` (setting `namespace`) tagged `{{tag>induction}}`.
  The same tag on pages elsewhere (the FabLab training pages use it) is ignored. Also excluded: namespace templates (`c_template`,
  `i_template`; setting `ignore_pages`).
- **New inductions:** put `~~NEWINDUCTION~~` on a page (it's on `whs:inductions:start`) for a
  **New induction** button. It works out the next free `ind-NNN` when clicked (numbers of deleted
  pages are never reused) and opens it in the editor, starting from `whs:inductions:c_template`
  (via the templatepagename plugin). The template has one header table for both kinds, with
  `<placeholders>`, and two commented-out checklist blocks, equipment and space; you keep one.
  Preview flags any placeholder left in the title, header or description.
- **The template page** is tagged `induction-template` (setting `template_tag`), not `induction`,
  so it shows in the sitemap but never in induction lists. A page made from it gets the
  `induction` tag instead.
- **Which revision:** the last **approved** revision of each induction page. Drafts never reach the
  app. SWP and RA pages only supply the text attached to items: their approved revision is used when
  there is one, otherwise their current revision (shown as version `draft`). SWP approval never holds
  an induction back.
- **When:** right after a page is approved, and in DokuWiki's background task runner whenever an
  approval, a tagged page, a deletion or a move has changed anything. A rebuild that gives the
  same bundle publishes nothing.
- **Where:** `whs:inductions:inductions.json` (setting `media_id`), at
  `<wiki>/lib/exe/fetch.php?media=whs:inductions:inductions.json`. Every published bundle is kept
  as a media revision. Reading it needs read access to the namespace: give the app its own wiki
  account and use HTTP Basic auth or a DokuWiki API token.
- **Errors and warnings:** each induction is published on its own. If an approved induction page
  itself is broken (no Induction ID, no checklist items, a duplicate ID), only that induction keeps
  its last published version; every other induction still updates. A reference that doesn't match
  the SWP, a missing SWP page, or a Requires that isn't published yet is a warning: the item is
  published without the SWP text. Both show on the induction page and in `~~INDUCTIONSTATUS~~`.
- **Checks on preview and save:** pressing **Preview** in the editor checks the text in the editor
  (nothing is saved) and shows the result above the editor: errors that would stop it publishing,
  warnings, or a summary of what it builds to (sections, item counts, attached references). Saving
  runs the same check. Mistyped item types (`[dmeo]`) and checklist items that aren't indented two
  spaces are errors, so they're caught before anyone approves the page.

The JSON needs a MIME type. Add this line to `conf/mime.local.conf`:

    json  application/json

## Page format

    {{tag>induction}}
    <grid>
    <col xs="6">{{:whs:usyd_logo_dark.png?nolink&160|}}</col>
    <col xs="6"><wrapper class="h3">**Induction Checklist**</wrapper></col>
    </grid>

    ====== Creality CR-10 and CR-10 Max induction ======

    <datatables plain="true" striped="false" header-background="#eef2f5">

    ^ Induction ID:      | IND_J11_N209B_CR10 ||| **Version:** <badge type="success">@APPROVE_VERSION@</badge> |
    ^ Type:              | equipment ||| **Issued:** @APPROVE_DATE@ |
    ^ Location:          | J11 N209B ||||
    ^ SWP:               | [[whs:swp:creality-cr-10-printers]] ||||
    ^ RA:                | - ||||
    ^ Grants access:     | creality-cr-10: Creality CR-10, Creality CR-10 Max ||||
    ^ Requires:          | [[whs:inductions:ind-001]] ||||
    ^ Refresh (months):  | 12 ||||
    ^ Self-service:      | no ||||
    ^ Approved by:       | @APPROVE_NAME@ ||||

    </datatables>

    One or two sentences shown to the inductee at the top.

    ===== Hazards =====

      * [ ] Never touch the hot end or bed while printing ([[whs:swp:creality-cr-10-printers|hazard: Burns]])
      * [demo] Loads filament and starts a print from the SD card ([[whs:swp:creality-cr-10-printers|step 7]])
      * [ack] I will not use the CR-10 unsupervised until this induction is confirmed
      * Information only, not ticked

- **Header table:** laid out like the SWP header (logo, `datatables`, and the approve plugin's
  `@APPROVE_VERSION@`, `@APPROVE_DATE@` and `@APPROVE_NAME@`, which fill in once a revision is approved).
  The build reads the cell straight after each label. Every row below is required, written once,
  and checked on preview and save; use `-` for none.

  | Row | Rule (an error unless noted) |
  | --- | --- |
  | `Induction ID` | letters, digits, `_ . -`; can't change once published |
  | `Type` | `equipment` or `space` |
  | `Location` | any text, e.g. `J11 N209B` |
  | `SWP`, `RA` | links to existing pages, or `-`; outside `whs:swp`/`whs:ra` is a warning |
  | `Grants access` | `Name` or `id: Name`, comma separated, or `-`; no duplicates. Dropping an id that is published is a warning (card readers use it) |
  | `Requires` | links to existing induction pages (not itself), or `-` |
  | `Refresh (months)` | a whole number, e.g. `12` or `12 months`; `0` = no expiry |
  | `Self-service` | `yes` or `no` |

  A row with no value cell (`^ Location: J11 N209B`), a misspelt label (`Grant access`) or a
  missing row is an error. Rows like `Approved by` and `Prepared by` are fine; any other extra row
  is a warning.
- **Items:** `[ ]` covered by the assessor, `[demo]` demonstrated by the inductee, `[ack]`
  acknowledged by the inductee; any other list item or paragraph is information only.
- **References:** a link to an SWP or RA page (in `whs:swp` or `whs:ra`, or named in the header)
  whose label starts with a keyword attaches that text to the item for the tablet:

  | Label | Matches |
  | --- | --- |
  | `intro` | the SWP's introduction paragraph |
  | `hazard: Burns` | the hazard whose name starts with "Burns" |
  | `step 7` | step 7 in the Steps section |
  | `section: Emergency shutdown` | the section whose heading starts with that |
  | `risk: Fire` | an RA risk heading in the Risks section |
  | `emergency: First aid` | a line in the RA's Emergency controls |

  A link with only an anchor, `[[whs:swp:x#emergency_shutdown]]`, means that section. If a
  reference stops matching the SWP, the item is published without its text and the page shows a warning.
  Step numbers shift when steps are added, so prefer hazards and sections.

## Settings

| Setting | Default |
| --- | --- |
| `tag` | `induction` |
| `namespace` | `whs:inductions` (tagged pages outside it are ignored; empty for the whole wiki) |
| `swp_namespaces` | `whs:swp` |
| `ra_namespaces` | `whs:ra` |
| `media_id` | `whs:inductions:inductions.json` |
| `require_approval` | on |
| `ignore_pages` | `(^\|:)(c_\|i_\|_)template(_[a-z0-9]+)?$` |
| `page_prefix` | `ind-` |
| `template_tag` | `induction-template` |

## Testing

`php _test/offline.php <dir>=<namespace> ...` builds a bundle from page files without DokuWiki,
e.g. `php _test/offline.php swp-pages=whs:swp induction-pages=whs:inductions`.
