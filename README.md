# splregistry: SPL Registry bundles from the wiki

DokuWiki plugin for the Sydney Propulsion Lab wiki. It builds the bundle that the **SPL Registry**
Home Assistant add-on imports from wiki pages tagged `record` (inductions and checklists such as
preflights, QC checks and inspections), and publishes it as a media file the app downloads.

Requires the **tag**, **approve** and **sqlite** plugins (all part of the hassio-dokuwiki add-on).

## How it works

- **Which pages:** every page tagged `{{tag>record}}`, anywhere in the wiki. The `induction` tag
  (used before records, and by the FabLab training pages) no longer counts. Templates are excluded: pages matching
  `ignore_pages` (`c_template`, `i_template`) and pages tagged `record-template`
  (setting `template_tag`). A page made from a template gets the `record` tag.
- **Which revision:** the last **approved** revision of each record page. Drafts never reach the
  app. SWP and RA pages only supply the text attached to items: their approved revision is used when
  there is one, otherwise their current revision (shown as version `draft`). SWP approval never holds
  a record back.
- **When:** right after a page is approved, and in DokuWiki's background task runner whenever an
  approval, a tagged page, a deletion or a move has changed anything. A rebuild that gives the
  same bundle publishes nothing.
- **Where:** two media files, each keeping every published version as a media revision:
  - `whs:inductions:registry.json` (setting `registry_media_id`): **format 2**, with `inductions`
    and `checklists`. The SPL Registry app (0.13 and later) reads this one.
  - `whs:inductions:inductions.json` (setting `media_id`): **format 1**, inductions only, exactly as
    before, for app versions before 0.13. Turn off `legacy_bundle` once the app reads the registry
    bundle.

  Reading them needs read access to the namespace: give the app its own wiki account and use HTTP
  Basic auth or a DokuWiki API token.
- **Errors and warnings:** each record is published on its own. If an approved page itself is
  broken (no ID, no checklist items, a duplicate ID), only that record keeps its last published
  version; every other record still updates. A reference that doesn't match the SWP, a missing SWP
  page, or a Requires that isn't published yet is a warning. Both show on the page and in
  `~~REGISTRYSTATUS~~` (`~~INDUCTIONSTATUS~~` still works).
- **Checks on preview and save:** pressing **Preview** checks the text in the editor (nothing is
  saved) and shows the result above the editor: errors that would stop it publishing, warnings, or
  a summary of what it builds to. Saving runs the same check.

The JSON needs a MIME type. Add this line to `conf/mime.local.conf`:

    json  application/json

## Page format

`templates/record_template.txt` is the shared template for every kind of record, with
`<placeholders>` and notes.

    {{tag>record}}
    ====== UAV preflight checklist ======

    <datatables plain="true" striped="false" header-background="#eef2f5">
    ^ ID:                | CHK_UAV_PREFLIGHT ||| **Version:** <badge type="success">@APPROVE_VERSION@</badge> |
    ^ Type:              | checklist ||| **Issued:** @APPROVE_DATE@ |
    ^ Category:          | preflight ||||
    ^ Requires:          | [[whs:inductions:ind-007]] ||||
    ^ Outcome:           | Go / No-go ||||
    ^ Approved by:       | @APPROVE_NAME@ ||||
    </datatables>

    Preflight checks before every flight.

    ===== Checks =====

      * [pass] Props secure, no chips or cracks {photo} {comment on fail}
      * [num 25.0-25.2 V] Flight battery voltage
      * [select Clear|Light rain|Windy!] Conditions
      * [rich] Flight log / notes {optional}

- **Header table:** the build reads the cell straight after each label. Only `ID` and `Type` are
  required; leave any other row out (or write `-`) to use its default. Problems are checked on
  preview and save.

  | Row | Rule (an error unless noted) | Default |
  | --- | --- | --- |
  | `ID` (or `Induction ID`) | letters, digits, `_ . -`; can't change once published | required |
  | `Type` | `induction` or `checklist`. The older `equipment` and `space` mean an induction of that category | required |
  | `Category` | any short label; the app makes a filter for each one it sees | none (older pages: `equipment`/`space`) |
  | `Location` | any text, e.g. `J11 N209B` | none |
  | `SWP`, `RA` | links to existing pages, or `-`; outside `whs:swp`/`whs:ra` is a warning | none |
  | `Requires` | links to existing induction pages (not itself), or `-` | none |
  | `Grants access` | inductions only: `Name` or `id: Name`, comma separated, or `-`; no duplicates. Dropping a published id is a warning (card readers use it) | none |
  | `Outcome` | checklists only: two results separated by `/`, e.g. `Go / No-go` or `Airworthy / Grounded` (the first when nothing failed, the second when any item failed), or one word, e.g. `Complete`, which is always the result. The old keywords `go-nogo` and `pass-fail` are errors | `Pass / Fail` if an item can fail, otherwise `Complete` |
  | `Refresh (months)` | a whole number; `0` = no expiry. Checklists: when it is next due | 12 for inductions, 0 for checklists |
  | `Self-service` | `yes` or `no`: can be done without an assessor | `no` |
  | `Signature required` | `yes` or `no`. Inductions: the inductee draws a signature as well as typing their name. Checklists: whoever submits it signs | `no` |

  A misspelt label (`Grant access`) is a warning naming the row it was probably meant to be. Rows
  like `Approved by` and `Prepared by` are fine; any other extra row is a warning.
- **Items:** `  * [kind] text {options}`, indented two spaces. Any other list item or paragraph is
  information only.

  | Kind | Answer | Used in |
  | --- | --- | --- |
  | `[ ]` or `[check]` | a tick (covered by the assessor in an induction) | both |
  | `[demo]` | demonstrated by the person, ticked by the assessor | inductions |
  | `[ack]` | acknowledged by the person on their own phone | both |
  | `[pass]` | Pass / Fail / N/A | checklists |
  | `[num MIN-MAX unit]` | a reading; outside the range is a fail. Also `[num >= MIN unit]`, `[num <= MAX unit]`, `[num unit]` | checklists |
  | `[select A\|B\|C!]` | one of the choices; a choice ending in `!` is a fail | checklists |
  | `[text]` | a short answer | checklists |
  | `[rich]` | a longer formatted answer (notes, a flight log) | checklists |
  | `[photo]` | a photo | checklists |

  | Option | Meaning |
  | --- | --- |
  | `{photo}` / `{photo!}` | a photo can / must be attached |
  | `{comment}` | a comment box |
  | `{comment on fail}` | a comment is required when the item fails |
  | `{optional}` | the item can be left blank |

  A mistyped kind (`[dmeo]`), a kind the record type can't use, a bad `[num]` range, a `[select]`
  with one choice and an unknown `{option}` are errors.
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
| `tag` | `record` |
| `swp_namespaces` | `whs:swp` |
| `ra_namespaces` | `whs:ra` |
| `registry_media_id` | `whs:inductions:registry.json` |
| `media_id` | `whs:inductions:inductions.json` |
| `legacy_bundle` | on |
| `require_approval` | on |
| `ignore_pages` | `(^\|:)(c_\|i_\|_)template(_[a-z0-9]+)?$` |
| `template_tag` | `record-template` |
