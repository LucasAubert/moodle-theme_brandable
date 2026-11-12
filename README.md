# Brandable (theme_brandable)

Restyle Moodle with prompts: describe the change, preview it on your own pages, publish it.

Brandable is a theme that inherits from the theme of your choice (Boost by default). Site administrators describe
the design they want in plain language; the Brandable service turns it into SCSS, template overrides and, if you
allow it, small JavaScript modules. Every change is previewed on the real pages of the site before it is published,
and can be undone.

**Brandable requires an account on the Brandable service (brandablelms.com) to generate designs.** The free plan
needs no credit card and takes two minutes to create: 30 credits every month, on development and staging sites.
Paid plans are needed for production sites and for some features (see [What is free, what is paid](#what-is-free-what-is-paid)).

- Documentation: https://brandablelms.com/en/docs/moodle/installation (French: https://brandablelms.com/fr/docs/moodle/installation)
- Bug reports and feature requests: https://github.com/LucasAubert/moodle-theme_brandable/issues
- Release notes: [CHANGES.md](CHANGES.md)

## Requirements

| Moodle      | Status    | PHP         |
|-------------|-----------|-------------|
| 4.5 LTS     | Supported | 8.1 to 8.3  |
| 5.0         | Not tested (out of support) | 8.2 to 8.4 |
| 5.1         | Supported | 8.2 to 8.4  |
| 5.2         | Supported | 8.3 and later |
| 5.3 LTS     | Supported | 8.3 and later |

PostgreSQL, MariaDB and MySQL. The PHP version is the one required by Moodle: the plugin itself runs on PHP 8.1
and later. No Composer step and no other plugin are needed.

The parent theme is chosen in the settings of Brandable: Boost by default, or any installed theme that does not
itself inherit from Brandable. Classic was removed from core in Moodle 5.3; there, it is available as a plugin.

The editor tells when the parent theme applies something after Brandable: a custom CSS of its own, added after the
changes of Brandable, or a dark mode of its own, which recolours them (contrasts are only checked in the light mode).

Changing the parent theme keeps the published design: the templates it overrides whose original differs in the new
parent (or after a Moodle upgrade) are listed in the editor, to be reviewed.

## Installation

1. Install the zip from *Site administration > Plugins > Install plugins*, or extract it into `theme/brandable`
   (`public/theme/brandable` from Moodle 5.1) and visit the notifications page (or run `php admin/cli/upgrade.php`).
2. Select Brandable as the theme of the site (*Site administration > Appearance > Themes*). Until a design is
   published, the site looks exactly like the parent theme.
3. Open the editor (*Site administration > Appearance > Brandable editor*) and connect the site to a Brandable
   account: you sign in (or sign up) on brandablelms.com, then come back to Moodle. The screen lists what is sent to
   Brandable and links to its terms and privacy policy before you connect.

## What works without an account

- The theme: the parent theme with the design already published, on every page.
- The editor: preview, undo and publish the draft, history of the published versions, import of a design.

Only generating new changes from prompts needs the Brandable service. If the site is disconnected, the account is
closed or Brandable is unreachable, the published design keeps working: nothing on the site depends on the service
at display time.

## What is free, what is paid

| | Free plan | Paid plans |
|---|---|---|
| Prompts | 30 credits every month | Monthly credits of the plan, extra credit packs |
| Sites | 1 site, development and staging | Production sites (Essential), several sites (Multi-site) |
| JavaScript modules | Yes (reviewed by the administrator) | Yes, plus a review by Brandable (Pro) |
| Export as a standalone theme | No | Yes |
| Cloud history of the designs | No | Pro and Multi-site |

Prices and plans: https://brandablelms.com/en/pricing. Nothing is charged by the plugin itself, and nothing is ever
installed or bought automatically.

## Data sent to Brandable

Only when an administrator uses the editor of a connected site:

- the prompt, and the prompts and summaries of the current draft;
- the address and type of the page shown in the preview, the names of its templates and a sample of their data with
  personal fields redacted, and the element targeted in the preview (personal data redacted);
- the language of the user, the version of Moodle and of the plugin, the parent theme and its settings;
- at connection: the address of the site, and the country of the administrator when the site has none (billing
  currency);
- with the cloud (Pro): the versions published, their note and the name of the administrator who published them.

The site key is stored in the Moodle database; the administrators can disconnect the site at any time. Every item is
declared to the Privacy API of Moodle (*Site administration > Users > Privacy and policies > Plugin privacy registry*).
Terms: https://brandablelms.com/en/terms – privacy policy: https://brandablelms.com/en/privacy.

Designs are generated by large language models on the servers of Brandable. Only users with the capability
`theme/brandable:edit` (site administrators) use the editor; learners and teachers never interact with it.

## JavaScript generated

Some designs need a little JavaScript (a button, an animation). It is **disabled by default**:

- an administrator enables it on a consent page that explains the risk;
- no module is published before an administrator has reviewed and accepted its code on this site, and a module
  imported or restored is reviewed again;
- modules only use the small library provided by the plugin, and are validated before they are applied; no PHP is
  ever generated or run;
- *Stop every module* (page *Generated JavaScript*) turns every module off at once, and an administrator can add
  `?brandable_nojs=1` to any address to load that page without them, to take back control if a module misbehaves.

## Export

On paid plans, a published design can be exported as a standalone theme (a zip to install like any theme), so the
site no longer needs Brandable. The exported theme is GPL, like Moodle; it contains a licence key (checked
periodically, never blocking the site) that can be revoked from the Brandable dashboard if it leaks.

## Updates

Moodle announces new versions of the plugin like any other plugin. On a site connected to Brandable, the plugin
also tells the site administrators when Moodle would not (alpha or beta releases, update notifications disabled);
it never installs anything itself.

## Licence

GNU GPL v3 or later ([LICENSE](LICENSE)). Includes [svg-sanitizer](https://github.com/darylldoyle/svg-sanitizer)
(GPL 2.0 or later), see `thirdpartylibs.xml`.

Copyright 2026 Lucas Aubert.
