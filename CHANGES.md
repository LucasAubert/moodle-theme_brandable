# Changes

One section per release, newest first: `## <release> (<version>) - <date>`, then the notes in English (required),
then in French. Brandable reads these notes when it publishes a release.

## 0.4.0 (2026111100) - 2026-11-11

### English

- The editor tells when the parent theme applies something after Brandable: a custom CSS of its own, a dark mode of
  its own.
- Parent themes that build their own branding: the logos of Brandable replace theirs in the navigation bar and on the
  login page.
- The editor lists the templates Brandable overrides whose original changed since (another parent theme, a Moodle
  upgrade), to be reviewed.
- Fixed: the CSS of a draft could be ignored by the browser in the preview when the parent theme triggers a debugging
  message while it is compiled, with debugging messages displayed.
- Fixed: the consent of the generated JavaScript closed by itself while a published version was waiting for its cloud
  backup (the editor reads its state again every 5 seconds meanwhile).
- Exported themes carry the runtime 1.3.2 (the same logos of the parent theme replaced).

### Français

- L'éditeur signale ce que le thème parent applique après Brandable : un CSS personnalisé à lui, un mode sombre à lui.
- Thèmes parents qui construisent leur propre en-tête de marque : les logos de Brandable remplacent les leurs dans la
  barre de navigation et sur la page de connexion.
- L'éditeur liste les templates surchargés par Brandable dont l'original a changé depuis (autre thème parent, mise à
  jour de Moodle), à revoir.
- Corrigé : dans l'aperçu, le navigateur pouvait ignorer le CSS d'un brouillon quand le thème parent déclenche un
  message de débogage pendant sa compilation, avec l'affichage du débogage activé.
- Corrigé : le consentement au JavaScript généré se refermait tout seul pendant qu'une version publiée attendait sa
  sauvegarde dans le cloud (l'éditeur relit alors son état toutes les 5 secondes).
- Les thèmes exportés embarquent le runtime 1.3.2 (même remplacement des logos du thème parent).

## 0.3.0 (2026111000) - 2026-11-10

### English

- Beta, and the first version for Moodle Marketplace.
- Before connecting, the site shows what Brandable receives, with its terms and privacy policy.
- When Brandable is unavailable, the editor shows its message and keeps your request: a generation in progress
  resumes when Brandable is back, and a prompt sent meanwhile is sent again automatically (or with "Try again").
- New versions in beta are announced by the plugin, since Moodle only announces stable versions by default.
- The list of the images of the library is declared to the privacy API.
- Upgrading from a development version older than 0.1.0 is refused with a clear message (uninstall, then install).
- Fixed: on Moodle 5.3, a theme child of Boost no longer looks for an editor stylesheet of its own (warning at the
  installation); exported themes carry the runtime 1.3.1 and their own component name in every file.
- Fixed: the steps of a generation are listed in their order, whatever the format of their time.
- The preview banner takes its styles from a stylesheet, no longer from an inline style.
- Tested on Moodle 4.5 to 5.3, PHP 8.2 to 8.4 (PHP 8.1: static compatibility check), PostgreSQL, MariaDB and MySQL.
- Public documentation, README and Behat tests.

### Français

- Version bêta, et première version pour Moodle Marketplace.
- Avant la connexion, le site indique ce que Brandable reçoit, avec ses conditions et sa politique de confidentialité.
- Quand Brandable est indisponible, l'éditeur affiche son message et garde votre demande : une génération en cours
  reprend au retour de Brandable, et un prompt envoyé entre-temps est renvoyé automatiquement (ou avec « Réessayer »).
- Les nouvelles versions bêta sont annoncées par le plugin, Moodle n'annonçant par défaut que les versions stables.
- La liste des images de la bibliothèque est déclarée à la Privacy API.
- Une mise à jour depuis une version de développement antérieure à la 0.1.0 est refusée avec un message clair
  (désinstaller, puis installer).
- Corrigé : sur Moodle 5.3, un thème enfant de Boost ne cherche plus de feuille de style d'éditeur à lui (avertissement
  à l'installation) ; les thèmes exportés embarquent le runtime 1.3.1 et leur propre nom de composant dans chaque fichier.
- Corrigé : les étapes d'une génération sont listées dans leur ordre, quel que soit le format de leur heure.
- Le bandeau d'aperçu prend ses styles d'une feuille de style, et non plus d'un style en ligne.
- Testé sur Moodle 4.5 à 5.3, PHP 8.2 à 8.4 (PHP 8.1 : contrôle statique de compatibilité), PostgreSQL, MariaDB et MySQL.
- Documentation publique, README et tests Behat.

## 0.2.0 (2026110601) - 2026-11-09

### English

- Every request to Brandable states the version of the plugin, of Moodle, and what the plugin knows how to apply.
- When Brandable requires a newer plugin, the editor says so clearly, with the link to the update for the site
  administrators; the published design, the preview and publishing keep working.
- A request that needs a newer plugin explains it and offers the update; cloud versions that need one are marked.
- New versions of the plugin: checked once a day on a connected site, the site administrators are notified and see a
  banner in the editor with the release notes. Security updates are announced every week until installed. Nothing is
  ever installed automatically.

### Français

- Chaque requête à Brandable indique la version du plugin, celle de Moodle, et ce que le plugin sait appliquer.
- Quand Brandable exige un plugin plus récent, l'éditeur le dit clairement, avec le lien de mise à jour pour les
  administrateurs du site ; le design publié, l'aperçu et la publication continuent de fonctionner.
- Une demande qui nécessite un plugin plus récent l'explique et propose la mise à jour ; les versions du cloud qui en
  ont besoin sont signalées.
- Nouvelles versions du plugin : recherchées une fois par jour sur un site connecté ; les administrateurs du site sont
  notifiés et voient un bandeau dans l'éditeur, avec les notes de version. Les mises à jour de sécurité sont
  rappelées chaque semaine tant qu'elles ne sont pas installées. Rien n'est jamais installé automatiquement.

## 0.1.1 (2026110600) - 2026-11-06

### English

- Moodle 5.2 and 5.3 LTS are supported (Moodle 4.5 LTS to 5.3 LTS).
- Colour modes of Moodle 5.3: designs can define their dark variants, the preview switches between light and dark,
  and the verification reports fixed colours and low contrast in each mode.
- The editor follows the colours of Moodle, in light and dark mode, with the Brandable identity on its main actions.
- Accessibility of the editor: visible focus ring, 44 px targets, high contrast mode supported.
- Header of the editor: shorter discard label, and the publication status is no longer cut off.
- The installation zip no longer contains the integration tests.

### Français

- Moodle 5.2 et 5.3 LTS sont pris en charge (de Moodle 4.5 LTS à 5.3 LTS).
- Modes de couleur de Moodle 5.3 : les designs peuvent définir leurs variantes sombres, l'aperçu bascule entre clair
  et sombre, et la vérification signale les couleurs fixes et les contrastes insuffisants dans chaque mode.
- L'éditeur suit les couleurs de Moodle, en mode clair et sombre, avec l'identité Brandable sur ses actions
  principales.
- Accessibilité de l'éditeur : anneau de focus visible, cibles de 44 px, prise en charge du mode contraste élevé.
- En-tête de l'éditeur : libellé « Abandonner » plus court, et l'état de publication n'est plus coupé.
- Le zip d'installation ne contient plus les tests d'intégration.
