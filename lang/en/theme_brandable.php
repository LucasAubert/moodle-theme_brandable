<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Strings for theme_brandable (en).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['abandoned'] = 'Abandoned';
$string['abandonedattempts'] = '{$a} abandoned attempt(s)';
$string['account'] = 'Account';
$string['account:billingcontact'] = 'Public body billing (Chorus Pro): contact us';
$string['account:billingcountry'] = 'Billing country';
$string['account:billingcountry_help'] = 'Asked before the first purchase: it sets the taxes and the currency of the organisation.';
$string['account:billingcountrylocked'] = 'Billing country: {$a} (fixed at the first payment; contact Brandable to change it).';
$string['account:billinginterval'] = 'Billing period';
$string['account:billingportal'] = 'Billing';
$string['account:changeplan'] = 'Choose a paid plan';
$string['account:checkoutcancel'] = 'Payment cancelled.';
$string['account:checkoutopened'] = 'The payment page is open in a new tab. Credits are added a few seconds after the payment.';
$string['account:checkoutsuccess'] = 'Payment recorded: the credits or the plan are updated within a few seconds.';
$string['account:choosecountry'] = 'Choose the billing country';
$string['account:chooseplan'] = 'Choose {$a}';
$string['account:compare'] = 'Compare the plans';
$string['account:confirmdisconnect'] = 'Disconnect this site from Brandable? Its key is revoked: prompts cannot be sent until the site is connected again, by any administrator.';
$string['account:contactbilling'] = 'Contact Brandable billing';
$string['account:continuecheckout'] = 'Continue to the payment';
$string['account:countrynotsupported'] = 'Online payment is not available in the billing country of the organisation.';
$string['account:countryrequired'] = 'Choose the billing country, then continue to the payment.';
$string['account:credits'] = 'Credits available';
$string['account:currentoffer'] = 'Current offer';
$string['account:dashboard'] = 'Brandable dashboard';
$string['account:disconnect'] = 'Disconnect this site';
$string['account:disconnect_help'] = 'Revokes the key of this site. Every administrator of the site is disconnected.';
$string['account:disconnectfailed'] = 'Brandable could not revoke the key:';
$string['account:environment'] = 'Environment';
$string['account:forgetlocally'] = 'Forget it locally anyway';
$string['account:intervalmonth'] = 'Monthly';
$string['account:intervalyear'] = 'Yearly';
$string['account:licences'] = 'Licences and deployments';
$string['account:lowcredits'] = '{$a} credit(s) left: sending a prompt needs at least 5.';
$string['account:monthly'] = 'Monthly credits: {$a->remaining} / {$a->allocation}';
$string['account:monthlycredits'] = '{$a} credits per month';
$string['account:morecredits'] = 'Need more credits?';
$string['account:multisiteoffer'] = '{$a->name}: {$a->credits}';
$string['account:multisites'] = 'Several sites?';
$string['account:multisiteslink'] = 'See the plans for several sites';
$string['account:organization'] = 'Organisation';
$string['account:packoffer'] = '{$a->credits} credits, {$a->price}';
$string['account:packoffer_noprice'] = '{$a->credits} credits';
$string['account:periodend'] = 'Monthly credits renewed on {$a}.';
$string['account:permonth'] = '{$a} a month';
$string['account:peryear'] = '{$a} a year';
$string['account:plan'] = 'Plan';
$string['account:planchangeopened'] = 'The change of plan is open in a new tab, on its confirmation. The plan shown changes a few seconds after it.';
$string['account:project'] = 'Project';
$string['account:purchased'] = 'Purchased credits: {$a}';
$string['account:recharge'] = 'Buy credits';
$string['account:revokefromdashboard'] = 'You can forget the key on this site only, then revoke it from the Brandable dashboard.';
$string['account:taxnote'] = 'Prices exclusive of tax. Taxes may apply depending on the country.';
$string['account:unavailable'] = 'The Brandable account cannot be read right now.';
$string['account:updated'] = 'The account is up to date.';
$string['account:updating'] = 'update in progress';
$string['account:usage'] = 'This month: {$a->generations} generation(s), {$a->credits} credit(s) used.';
$string['account:yearlypermonth'] = 'that is {$a} per month';
$string['action:askadmin'] = 'Ask a site administrator.';
$string['action:connect'] = 'This site is no longer connected to Brandable.';
$string['action:credits'] = 'Buy credits, then send the prompt again:';
$string['action:fallback'] = 'Open the Brandable page';
$string['action:plan'] = 'This environment needs a paid plan:';
$string['action:relink'] = 'This site no longer matches the linked site (copy of the site, or URL changed).';
$string['asset:error:corrupt'] = 'This image cannot be read: the file is damaged or its content does not match its type.';
$string['asset:error:empty'] = 'The file is empty or missing.';
$string['asset:error:familyfull'] = 'A font family has {$a} files at most.';
$string['asset:error:filesize'] = 'The file is too large ({$a} at most).';
$string['asset:error:font'] = 'Only WOFF2 fonts can be added.';
$string['asset:error:fontmeta'] = 'A font needs its family (100 characters at most), its weight (1 to 1000) and its style (normal or italic).';
$string['asset:error:fontrequest'] = 'The fonts asked by Brandable are not described as expected.';
$string['asset:error:full'] = 'The library is full ({$a} images and fonts at most): delete the ones you no longer use.';
$string['asset:error:licence'] = 'The licence must be a text file (TXT or Markdown, 1 MB at most).';
$string['asset:error:missing'] = 'This image or font is no longer in the library.';
$string['asset:error:pixels'] = 'This image has too many pixels (40 million at most).';
$string['asset:error:slug'] = 'The name may only contain lowercase letters, digits and dashes (64 characters at most), for example campus-winter.';
$string['asset:error:slugtaken'] = 'The name {$a} is already used in the library.';
$string['asset:error:slugused'] = 'This name can no longer change: the design uses it.';
$string['asset:error:svg'] = 'This SVG image cannot be kept: it is not a valid SVG image, or it declares a DOCTYPE or entities.';
$string['asset:error:thumbnail'] = 'The thumbnail prepared by the editor is not valid (PNG, JPEG or WebP, 512 px and 400 KB at most).';
$string['asset:error:toolarge'] = 'This image is larger than {$a} px and cannot be reduced here: reduce it before adding it.';
$string['asset:error:type'] = 'Only PNG, JPEG, WebP, GIF and SVG images, ICO icons (favicon) and WOFF2 fonts can be added.';
$string['asset:error:upload'] = 'The file was not received completely. Try again.';
$string['asset:error:used'] = 'This image or font cannot be deleted: it is used by {$a}.';
$string['asset:error:version'] = 'A version prepared by the editor does not match the original image. Try again.';
$string['asset:licencenotice'] = '{$a->family} is distributed under the licence {$a->licence} ({$a->url}).';
$string['asset:moodlelogo'] = 'Logo of the site';
$string['asset:usage:previous'] = 'the previous version';
$string['asset:usage:published'] = 'the published version';
$string['asset:usage:session'] = 'the changes in progress';
$string['assets'] = 'Images and fonts';
$string['assets:addfont'] = 'Add the font';
$string['assets:addimages'] = 'Add images';
$string['assets:alt'] = 'Alternative text (optional)';
$string['assets:attach'] = 'Attach to the prompt';
$string['assets:attached'] = 'Attached to the prompt';
$string['assets:attachimages'] = 'Attach images (or drop them on the chat, or paste them in the prompt)';
$string['assets:count'] = '{$a->count} / {$a->max}';
$string['assets:deleteconfirm'] = 'Delete {$a} from the library?';
$string['assets:deletetitle'] = 'Delete from the library';
$string['assets:detach'] = 'Remove {$a} from the prompt';
$string['assets:family'] = 'Family';
$string['assets:favicononly'] = 'favicon only';
$string['assets:fontfile'] = 'WOFF2 file';
$string['assets:fonts'] = 'Fonts';
$string['assets:images'] = 'Images';
$string['assets:imageshelp'] = 'PNG, JPEG, WebP, GIF or SVG, 10 MB at most; ICO for the favicon. Large images are reduced to 2560 px; the original is kept. You can also drop images on the chat or paste them in the prompt.';
$string['assets:licencefile'] = 'Licence (TXT or Markdown, optional)';
$string['assets:nofonts'] = 'No font yet. Fonts from Google Fonts are added by themselves when a request uses them.';
$string['assets:noimages'] = 'No image yet.';
$string['assets:preparing'] = 'Preparing {$a}…';
$string['assets:sending'] = 'Sending {$a}…';
$string['assets:slug'] = 'Name in the design';
$string['assets:style'] = 'Style';
$string['assets:styleitalic'] = 'Italic';
$string['assets:stylenormal'] = 'Normal';
$string['assets:toomanyattachments'] = '{$a} images at most can be attached to a request.';
$string['assets:unavailable'] = 'The library cannot be read right now.';
$string['assets:uploadfailed'] = '{$a->name} was not added: {$a->error}';
$string['assets:usedby'] = 'Used by {$a}';
$string['assets:weight'] = 'Weight';
$string['brandable:edit'] = 'Edit, preview and publish the site design with Brandable';
$string['brandable:managejs'] = 'Turn the generated JavaScript of Brandable on or off, and suspend it';
$string['brandable_apikey'] = 'API key';
$string['brandable_apikey_desc'] = 'Dev mode: static key of the Brandable server (development only). Takes precedence over the site key.';
$string['brandable_apiurl'] = 'API URL for the server';
$string['brandable_apiurl_desc'] = 'Dev mode: API base URL called by the Moodle server, when it reaches Brandable at another address than the browser, for example http://host.docker.internal:3000/api/v1. Empty: the Brandable URL followed by /api/v1. The site cURL security settings apply (blocked hosts and allowed ports).';
$string['brandable_generator'] = 'Generator';
$string['brandable_generator_desc'] = 'Turns prompts into design changes. The demo generator works offline with a few keywords (colours, dark navbar, rounded corners, hero banner).';
$string['brandable_js'] = 'Generated JavaScript';
$string['brandable_parent'] = 'Parent theme';
$string['brandable_parent_desc'] = 'Theme Brandable builds upon: its layouts, templates, styles and settings are used as the base for your customisations. Changing it purges all theme caches. A published design stays applied on the new parent: the templates it overrides whose original differs there are listed in the editor, to be reviewed.';
$string['brandable_weburl'] = 'Brandable URL (browser)';
$string['brandable_weburl_desc'] = 'Dev mode: Brandable web app URL, opened by the browser, for example http://localhost:3000. Empty: production.';
$string['cachedef_resolved'] = 'Resolved Brandable overrides of each revision';
$string['changecount'] = '{$a} change(s)';
$string['changescope'] = 'Change the scope';
$string['choosereadme'] = 'Brandable lets administrators restyle Moodle with prompts, preview the result and publish it.';
$string['cloud:compare'] = 'Compare with the version running here';
$string['cloud:compare:pick'] = 'Compare with…';
$string['cloud:compare:picked'] = 'Choose the other version to compare.';
$string['cloud:contactsupport'] = 'Contact the support';
$string['cloud:diff:assets'] = 'Images, fonts and licences';
$string['cloud:diff:back'] = 'Back to the history';
$string['cloud:diff:changes'] = 'Changes';
$string['cloud:diff:licence'] = 'Licence of {$a}';
$string['cloud:diff:none'] = 'No difference.';
$string['cloud:diff:notexact'] = 'The templates were compared with a release close to Moodle {$a}, not this exact one.';
$string['cloud:diff:nothinghere'] = 'Nothing is published here yet: everything is added.';
$string['cloud:diff:notindexed'] = 'Brandable does not know the templates of Moodle {$a}: the template overrides were not compared with the originals.';
$string['cloud:diff:status:added'] = 'Added';
$string['cloud:diff:status:modified'] = 'Changed';
$string['cloud:diff:status:removed'] = 'Removed';
$string['cloud:diff:title'] = 'Version {$a->from} → version {$a->to}';
$string['cloud:error:asset_changed'] = 'An image or a font of this version is no longer in the library as it was: it cannot be sent.';
$string['cloud:error:assetchanged'] = 'The file is not the one of the version (its content changed when checked).';
$string['cloud:error:download'] = 'The file could not be downloaded from the cloud.';
$string['cloud:error:invalid_request'] = 'Brandable refused this version (invalid). Publish it again, or contact the support.';
$string['cloud:error:invalidversion'] = 'Nothing valid on this site in this version: {$a}';
$string['cloud:error:other'] = 'Not saved in the cloud ({$a}).';
$string['cloud:error:payload_too_large'] = 'This version is too large for the cloud.';
$string['cloud:error:plan'] = 'The cloud backup needs the Pro offer: this version is kept here and sent once the offer is back.';
$string['cloud:error:proposalgone'] = 'This proposal is no longer waiting (replaced, accepted or dismissed).';
$string['cloud:error:ratelimited'] = 'Too many sendings to the cloud in the last hour: sent again later by itself.';
$string['cloud:error:storage'] = 'The storage of the cloud of your organisation is full. Contact the support of Brandable.';
$string['cloud:error:unavailable'] = 'Cloud not available for now: sent again later by itself.';
$string['cloud:finding:colourmodes'] = 'This version has variants for the dark mode of Moodle 5.3: they have no effect on this site, which has no colour modes.';
$string['cloud:history'] = 'History';
$string['cloud:history:current'] = 'Run here';
$string['cloud:history:empty'] = 'No version in the cloud yet.';
$string['cloud:history:environments'] = 'Other environments';
$string['cloud:history:from'] = 'from {$a}';
$string['cloud:history:here'] = 'This site: {$a}';
$string['cloud:history:intro'] = 'Versions of the project, shared by its environments.';
$string['cloud:history:more'] = 'Older versions';
$string['cloud:history:none'] = 'Version history, comparison and sending to another environment come with the Pro offer.';
$string['cloud:history:nothing'] = 'no version';
$string['cloud:history:openversions'] = 'Open the versions in Brandable';
$string['cloud:history:publishedin'] = 'Published in: {$a}';
$string['cloud:history:readonly'] = 'The Pro offer was lost: the history can be read and restored until {$a}, nothing new is saved.';
$string['cloud:history:upgrade'] = 'Choose the Pro offer';
$string['cloud:history:version'] = 'Version {$a}';
$string['cloud:pending'] = 'Waiting for the cloud backup';
$string['cloud:proposal:banner'] = 'Version {$a->number} proposed from {$a->from}';
$string['cloud:proposal:body'] = '{$a->author} proposes version {$a->number} of the project to this site, from {$a->from}.';
$string['cloud:proposal:bodylink'] = 'See the changes and pull it in the Brandable editor: {$a->url}';
$string['cloud:proposal:bodymessage'] = 'Message: {$a->message}';
$string['cloud:proposal:by'] = 'by {$a}';
$string['cloud:proposal:changes'] = 'See the changes';
$string['cloud:proposal:dismiss'] = 'Dismiss';
$string['cloud:proposal:dismissconfirm'] = 'Dismiss the proposal of version {$a}? It stays in the history of the project.';
$string['cloud:proposal:pull'] = 'Pull';
$string['cloud:proposal:subject'] = 'Version {$a->number} proposed from {$a->from}';
$string['cloud:propose'] = 'Propose to…';
$string['cloud:propose:done'] = 'Version {$a->number} proposed to {$a->environment}.';
$string['cloud:propose:environment'] = 'Environment';
$string['cloud:propose:intro'] = 'The site of this environment shows the proposal and notifies its administrators. A new proposal replaces the one waiting there.';
$string['cloud:propose:message'] = 'Message (optional)';
$string['cloud:propose:send'] = 'Propose';
$string['cloud:propose:title'] = 'Propose version {$a}';
$string['cloud:pull:confirm'] = 'Version {$a} becomes the draft of a new session: the current draft and its conversation are deleted. Nothing is published until you publish; the proposal is then accepted.';
$string['cloud:pullednote'] = 'Version {$a->number} taken from {$a->environment}. {$a->note}';
$string['cloud:report:blocked'] = 'JavaScript modules refused by the analysis of Brandable, not taken: {$a}';
$string['cloud:report:done'] = 'Version {$a} is the draft: review it in the preview, then publish.';
$string['cloud:report:downloaded'] = 'Files downloaded into the library: {$a}';
$string['cloud:report:findings'] = 'Points to check:';
$string['cloud:report:noscope'] = 'No such course or activity here ({$a}): left out of the draft.';
$string['cloud:report:pulltitle'] = 'Pull';
$string['cloud:report:refused'] = 'Files refused: {$a}';
$string['cloud:report:removed'] = 'Left out of the draft:';
$string['cloud:report:renamed'] = 'Renamed in the library (name already used): {$a}';
$string['cloud:report:title'] = 'Restoration';
$string['cloud:restore'] = 'Restore this version';
$string['cloud:restore:confirm'] = 'Version {$a} becomes the draft of a new session: the draft in progress and its conversation are deleted. Nothing is published until you publish.';
$string['cloud:restorednote'] = 'Version {$a->number} restored. {$a->note}';
$string['cloud:retry'] = 'Send again';
$string['cloud:synced'] = 'Version {$a} of the project · Saved in the cloud';
$string['configtitle'] = 'Brandable';
$string['confirmdiscard'] = 'Discard the draft? The revisions and the thread of this session are deleted; the design goes back to the published version.';
$string['confirmrevert'] = 'Publish the previous version again? Every user will see it; the current version becomes the previous one.';
$string['confirmrevertdraft'] = 'Publish the previous version again? Every user will see it. The draft in progress is based on the current version: its revisions and its thread are deleted.';
$string['connect'] = 'Connect Brandable';
$string['connect:accept'] = 'By connecting, you accept the terms of Brandable.';
$string['connect:askadmin'] = 'Ask a site administrator to connect Brandable.';
$string['connect:banner'] = 'Brandable is not connected to an account yet: the design cannot be changed with prompts.';
$string['connect:button'] = 'Connect to Brandable';
$string['connect:cancelled'] = 'The connection was cancelled.';
$string['connect:connected'] = 'This site is connected to Brandable.';
$string['connect:devmode'] = 'Dev mode is on ($CFG->theme_brandable_devmode): the API URL and key set manually in the Brandable settings take precedence over the connection.';
$string['connect:disconnected'] = 'This site is disconnected from Brandable.';
$string['connect:done'] = 'This site is now connected to Brandable: {$a->organization}, {$a->project}, {$a->environment}.';
$string['connect:editorintro'] = 'Connect this site to a Brandable account to change its design with prompts. Previewing, undoing and publishing the existing draft remain available.';
$string['connect:expired'] = 'The connection has expired or was started from another session: connect again.';
$string['connect:failed'] = 'Brandable could not connect the site. Try again in a few minutes.';
$string['connect:freetitle'] = 'Create your free account: 30 credits offered every month';
$string['connect:intro'] = 'Connect this site to a Brandable account to change its design with prompts. You will sign in on the Brandable website, then come back here.';
$string['connect:invalidgrant'] = 'The connection code was refused (expired or already used): connect again.';
$string['connect:nopassword'] = 'Moodle never sees your Brandable password: the site only receives a key of its own, which you can revoke at any time.';
$string['connect:privacy'] = 'Privacy policy';
$string['connect:reconnect'] = 'Connect again';
$string['connect:reconnect_help'] = 'Issues a new key (the current one is revoked), to the same environment or another one.';
$string['connect:relink'] = 'Link this site again';
$string['connect:relink_help'] = 'After a copy of the site or a change of its URL: Brandable offers to update the link or to choose another environment.';
$string['connect:sent'] = 'What Brandable receives';
$string['connect:sentcloud'] = 'With the cloud (Pro plan): the versions you publish, their note and the name of their author.';
$string['connect:sentconnection'] = 'When you connect: the address of the site, an anonymous identifier of the site, and the country of your profile when the site has none (currency of the invoices).';
$string['connect:sentonly'] = 'Only the editor, used by the administrators, and a daily check of the plugin version talk to Brandable: learners and teachers are never involved, and the published design does not depend on Brandable.';
$string['connect:sentprompt'] = 'With each prompt: the prompt, the page shown in the preview (address, templates and a sample of their data, with personal fields redacted), the element you target, the images you attach, your language and the versions of Moodle and of the plugin.';
$string['connect:startfree'] = 'Start for free';
$string['connect:terms'] = 'Terms of service';
$string['connect:title'] = 'Connect Brandable';
$string['connect:unavailable'] = 'Connecting to Brandable is not available yet in this version.';
$string['device'] = 'Preview width';
$string['device_desktop'] = 'Desktop';
$string['device_mobile'] = 'Mobile';
$string['device_tablet'] = 'Tablet';
$string['disablemarkers'] = 'Disable template markers';
$string['disablemarkers_help'] = 'The preview marks where each template starts and ends, so that the targeted element can be linked to its templates. Disable them if they disturb a page: the targeted element is then sent without templates.';
$string['discard'] = 'Discard draft';
$string['discardshort'] = 'Discard';
$string['dismiss'] = 'Dismiss';
$string['draft'] = 'Draft';
$string['editor'] = 'Brandable editor';
$string['emptythread'] = 'Describe the design you want: Brandable previews it here before you publish it.';
$string['error:ajax_network'] = 'The server could not be reached.';
$string['error:ajax_timeout'] = 'The server did not answer in time.';
$string['error:ajax_title'] = 'The action failed.';
$string['error:apinotconfigured'] = 'The Brandable API is not configured (URL and key).';
$string['error:apiresponse'] = 'The Brandable API sent an unexpected answer.';
$string['error:apistatus'] = 'The Brandable API answered with HTTP status {$a}.';
$string['error:assetmarker'] = 'Images and fonts of the library are designated by brandable-asset("name") in SCSS (never inside url()) and by [[brandable-asset:name]] in templates.';
$string['error:cannotrescope'] = 'This change is not the active override any more: its scope cannot be changed.';
$string['error:cannotresend'] = 'This request cannot be sent again.';
$string['error:cannotresume'] = 'This attempt cannot be tried again as is (only a download of fonts that failed, within 24 hours).';
$string['error:contextexpired'] = 'This generation has expired. Please send the prompt again.';
$string['error:darkinpre'] = 'The brandable-dark mixin is only for the SCSS after the theme (post): the SCSS before it (pre) only sets variables.';
$string['error:darknested'] = 'brandable-dark is used inside another brandable-dark: write the dark variant once.';
$string['error:darkroot'] = 'brandable-dark is used in a rule targeting html or :root: put the variant at the root of the chunk instead.';
$string['error:draftchanged'] = 'The draft changed while the changes were being generated. Please try again.';
$string['error:duplicatetarget'] = 'The same target is changed twice in this revision.';
$string['error:emptycontent'] = 'The content is empty.';
$string['error:emptyprompt'] = 'The prompt is empty.';
$string['error:generationfailed'] = 'The generation failed.';
$string['error:generationrunning'] = 'A generation is in progress: wait for it to finish.';
$string['error:generator'] = 'Generator error: {$a}';
$string['error:interrupted'] = 'The generation was interrupted. Please send the prompt again.';
$string['error:invalidscsstarget'] = 'Invalid SCSS target: expected pre/<slug> or post/<slug> with a kebab-case slug.';
$string['error:invalidtemplatename'] = 'Invalid template name: expected component/name with an installed component.';
$string['error:jscompiled'] = 'The compiled module is missing or invalid: a function expression of {$a} characters at most is expected.';
$string['error:jscontent'] = 'The source of a JavaScript module is {$a} characters at most.';
$string['error:jsgenerationonly'] = 'JavaScript modules only come from the generations of Brandable.';
$string['error:jsmaxmodules'] = 'The draft can hold {$a} JavaScript modules at most.';
$string['error:jsnoreview'] = 'This JavaScript module is not one of the draft to review.';
$string['error:jsnotenabled'] = 'The generated JavaScript is not turned on.';
$string['error:jsnotreviewed'] = 'JavaScript modules changed since the published version must be reviewed before publishing: {$a}';
$string['error:jsnoversion'] = 'This version does not exist.';
$string['error:jsreport'] = 'The analysis report of the JavaScript module is not valid JSON.';
$string['error:jsreviewchanged'] = 'The code of this module changed since it was shown: read it again.';
$string['error:jssignature'] = 'The JavaScript module has no valid signature from Brandable.';
$string['error:jstarget'] = 'Invalid JavaScript target: expected js/<slug> with a kebab-case slug.';
$string['error:jstemplate'] = 'The JavaScript module renders a template that does not exist: {$a}';
$string['error:locktimeout'] = 'Another change is being saved. Please try again.';
$string['error:mustache'] = 'Invalid Mustache template: {$a}';
$string['error:nochanges'] = 'The revision contains no change.';
$string['error:noprevious'] = 'There is no previous version.';
$string['error:noquestion'] = 'This question can no longer be answered: send a new request.';
$string['error:notconnected'] = 'This site is not connected to Brandable.';
$string['error:nothingtodelete'] = 'There is no override to remove for this target.';
$string['error:nothingtopublish'] = 'The draft does not differ from the published version.';
$string['error:nothingtoredo'] = 'There is nothing to redo.';
$string['error:nothingtoundo'] = 'There is nothing to undo.';
$string['error:nothingvalid'] = 'None of the proposed changes could be applied.';
$string['error:prescope'] = 'A pre/ SCSS chunk (variables) always applies to the whole site: it cannot have another scope.';
$string['error:protectedtemplate'] = 'This Brandable template is part of the Brandable interface and cannot be overridden.';
$string['error:repairconflict'] = 'This generation cannot be repaired.';
$string['error:repairlimit'] = 'The changes are still invalid after several attempts.';
$string['error:scopelevel'] = 'Unknown scope level "{$a}".';
$string['error:scopevalue'] = 'Invalid scope value ({$a}).';
$string['error:scss'] = 'SCSS error: {$a}';
$string['error:serviceunavailable'] = 'The service is temporarily overloaded, try again in a few minutes.';
$string['error:settingasset'] = '{$a} is not an image of the library.';
$string['error:settingscope'] = 'The logo, the compact logo and the favicon apply to the whole site.';
$string['error:settingtarget'] = 'Only the logo, the compact logo and the favicon can be set (logo, logocompact, favicon).';
$string['error:settingtype'] = '{$a} cannot be used here: the favicon must be a PNG, SVG or ICO image, and an ICO icon can only be the favicon.';
$string['error:stillinvalid'] = 'The proposed changes are still invalid after the repairs. Please rephrase your request.';
$string['error:templatejs'] = 'JavaScript is not allowed in template overrides; use CSS or template structure (added: {$a}).';
$string['error:templatenotfound'] = 'This template does not exist. Only templates under {$a} can be created.';
$string['error:unavailable'] = 'Brandable cannot be reached right now. Try again in a few minutes.';
$string['error:unknownpartial'] = 'The template uses the partial "{$a}", which does not exist.';
$string['error:unknownrevision'] = 'Unknown revision {$a}.';
$string['error:unknownstring'] = 'The template uses the string "{$a}", which does not exist.';
$string['error:unknowntype'] = 'Unknown change type "{$a}".';
$string['error:urlblocked'] = 'Moodle blocks the requests to {$a}: allow this host and its port in Site administration > General > Security > HTTP security (cURL blocked hosts list, cURL allowed ports list).';
$string['error:validation'] = 'The changes are invalid: {$a}';
$string['event:done'] = 'Changes ready';
$string['event:editing'] = 'Editing the theme';
$string['event:planning'] = 'Planning the changes';
$string['event:rejected'] = 'Some changes were rejected by Moodle:';
$string['event:repairing'] = 'Fixing the rejected changes';
$string['exiteditor'] = 'Leave the editor';
$string['exitpreview'] = 'Exit preview';
$string['export'] = 'Export as a theme';
$string['export:author'] = 'Author';
$string['export:author_help'] = 'Shown in the Brandable dashboard with the export (optional).';
$string['export:byid'] = 'Same id';
$string['export:byidnumber'] = 'ID number';
$string['export:byshortname'] = 'Short name';
$string['export:changeplan'] = 'Change plan';
$string['export:error:installed'] = 'A theme with this name is already installed on this site, and it was not exported from Brandable.';
$string['export:error:name'] = 'Invalid theme name: lower case letters, digits and underscores, starting with a letter, 50 characters at most (no double underscore).';
$string['export:error:nothingpublished'] = 'Nothing is published yet: publish a version before exporting it.';
$string['export:error:reserved'] = 'This name is reserved (Brandable or the parent theme).';
$string['export:error:zip'] = 'The zip of the theme could not be written.';
$string['export:from'] = 'Published version {$a->number} ({$a->date}) is exported as a standalone Moodle theme.';
$string['export:fromeditor'] = 'Export as a theme';
$string['export:intro'] = 'The zip holds a theme to install on any Moodle 4.5 or 5.1 site, without Brandable. It is downloaded once: it contains the key of its licence (revocable from the Brandable dashboard).';
$string['export:leaveout'] = 'Leave out';
$string['export:name'] = 'Theme name';
$string['export:name_help'] = 'Folder and component of the theme (theme_<name>): lower case letters, digits and underscores, starting with a letter. Export again under the same name to make a new version of the theme.';
$string['export:readme'] = '# theme_{$a->name}

Moodle theme exported from Brandable: version {$a->version}, parent theme {$a->parent}.

## Installation

1. Unzip the folder into theme/ of the Moodle site (theme/{$a->name}), then run the upgrade (Site administration > Notifications).
2. Choose the theme in Site administration > Appearance > Themes.

The settings of the parent theme ({$a->parent}: colours, logo, preset...) apply. Edit them on the settings page of {$a->parent}: they are copied to this theme.

## Licence

The code is under the GNU GPL v3 or later (COPYING.txt).

The theme checks a Brandable licence, without ever changing what the pages look like. Only administrators are told when the licence is expired, revoked or cannot be checked.

- **Daily signal**: once a day, the theme sends to Brandable the URL of the site, the name and version of the theme and the version of Moodle (no data about users or courses). It can be turned off in the settings of the theme (Daily signal).
- **Offline licence**: for a site without outgoing access, or with the signal turned off, paste in the settings of the theme (Offline licence) a licence downloaded from the Brandable dashboard (Licences and deployments).

**This zip contains a secret key** (licence.php), used only by the daily signal. Keep the zip private. If it leaks, revoke the licence from the Brandable dashboard (Licences and deployments).

## Scopes left out

{$a->warnings}

## Known limits

- The variants for a page type, a page, a course or an activity apply to the templates rendered by the server; the templates rendered in JavaScript use the version for the whole site.
- After renaming the short name of a course or the ID number of an activity used by a variant, purge the caches.

The changes of each version are in CHANGELOG.md.';
$string['export:readmecolourmodes'] = '## Colour modes

From Moodle 5.3, Boost has a light and a dark colour mode (experimental, off by default: settings of Boost, Experimental tab). The theme follows them: its dark variants apply when a user chooses the dark mode. On earlier versions of Moodle, or with the modes off, they are inert.';
$string['export:readmejs'] = '## Generated JavaScript

This theme has {$a->count} JavaScript module(s) generated by Brandable and reviewed on the site that exported it. Their readable source, the function run, the analysis of Brandable and its signature are in brandable/js/. The library they receive (b) is amd/src/runtime.js.

- They run in the browser of every visitor, with their rights, never on the administration pages.
- **Turn them on or off**: settings of the theme, "Run the JavaScript modules" (on at the export when they were on).
- **Kill switch**: settings of the theme, "Kill switch", stops every module at once.
- **On a single page**: administrators add ?{$a->nojs}=1 to its address.';
$string['export:scopedropped'] = '{$a->target} ({$a->scope}): left out, no course or activity on the target site.';
$string['export:scopes'] = 'Courses and activities';
$string['export:scopes_help'] = 'Some changes apply to a course or an activity of this site. Choose how to find it on the site where the theme will be installed, or leave the change out.';
$string['export:submit'] = 'Export and download';
$string['failedattempt'] = 'No revision was created.';
$string['generalsettings'] = 'Brandable';
$string['generating'] = 'Working…';
$string['generation:autoretry'] = 'New attempt in {$a} s.';
$string['generation:fonts'] = 'Fonts downloaded into the library: {$a->done} / {$a->total}.';
$string['generation:fontsfailed'] = 'The fonts could not be downloaded from Brandable, the changes are not applied: {$a} Try again in a moment (nothing is generated again).';
$string['generation:missingassets'] = 'Not in the library any more, shown empty: {$a}.';
$string['generation:waiting'] = 'Brandable is unavailable for a moment. Your request is kept: its progress is read again in {$a} s.';
$string['generator_mock'] = 'Demo generator (offline)';
$string['generator_remote'] = 'Brandable API';
$string['generatorheading'] = 'Generator';
$string['generatorunavailable'] = 'No generator is available yet.';
$string['import'] = 'Import an exported theme';
$string['import:again'] = 'Choose another zip';
$string['import:assets'] = 'Images and fonts: {$a->images} image(s) and {$a->fonts} font file(s), added to the library (the same file already there is reused).';
$string['import:discards'] = 'The draft in progress is discarded: the imported version replaces the version it builds on.';
$string['import:done'] = 'Version {$a} published from the imported theme.';
$string['import:error:invalid'] = 'The theme cannot be imported: some changes are refused by the checks of Brandable.';
$string['import:error:manifest'] = 'The manifest of the theme (brandable/manifest.json) is invalid.';
$string['import:error:notexport'] = 'This zip is not a theme exported from Brandable (brandable/manifest.json missing, or unknown format).';
$string['import:error:parent'] = 'The theme was exported with the parent theme {$a->zip}, and Brandable uses {$a->site} on this site: change the parent theme of Brandable to import it.';
$string['import:error:zip'] = 'This file is not a valid zip of a theme (one folder, 20 MB at most).';
$string['import:fromeditor'] = 'Import an exported theme';
$string['import:intro'] = 'The design of a theme exported from Brandable becomes a new published version of this site: the current version becomes the previous one. Only the templates and the SCSS of the zip are read, and they are checked again; nothing of the zip is installed.';
$string['import:jschanged'] = '{$a}: left out, its source does not match the manifest.';
$string['import:jsdone'] = 'Imported as the draft of the editor, with {$a} JavaScript module(s) to review before publishing.';
$string['import:jsdraft'] = 'This theme has {$a} JavaScript module(s): they are not reviewed on this site. The version is imported as the draft of the editor, not published: review the modules there (an administrator managing the generated JavaScript), then publish. The JavaScript stays off until this site gives its own consent.';
$string['import:note'] = 'Imported from theme_{$a->name} (version {$a->version}).';
$string['import:read'] = 'Read the zip';
$string['import:scopedropped'] = '{$a->target} ({$a->scope}): left out, no single course or activity of this site has this key.';
$string['import:submit'] = 'Import and publish';
$string['import:summary'] = 'theme_{$a->name}, version {$a->version}, exported from Moodle {$a->moodle}: {$a->templates} templates for the whole site, {$a->variants} variants, {$a->scss} SCSS chunks.';
$string['import:templatemissing'] = '{$a}: left out, this template does not exist on this site.';
$string['import:warnings'] = 'Some changes are left out:';
$string['import:zip'] = 'Zip of the theme';
$string['js:apis'] = 'APIs:';
$string['js:blocked'] = 'Not run (analysis errors)';
$string['js:findingline'] = 'Line {$a}:';
$string['js:unsigned'] = 'Unsigned (changed out of Brandable)';
$string['jsactivation:accept'] = 'I accept, turn the JavaScript on';
$string['jsactivation:activate'] = 'Turn the JavaScript on';
$string['jsactivation:alreadyon'] = 'The generated JavaScript is on: the request can be sent again.';
$string['jsactivation:askadmin'] = 'Ask an administrator of the site to turn the generated JavaScript on, then send the request again.';
$string['jsactivation:pending'] = 'Waiting for the JavaScript';
$string['jsactivation:resend'] = 'Send the request again';
$string['jsactivation:resent'] = 'Sent again';
$string['jsactivation:title'] = 'JavaScript needed';
$string['jsconsent'] = 'Generated JavaScript';
$string['jsconsent:accept'] = 'I accept, turn the generated JavaScript on';
$string['jsconsent:action'] = 'Action';
$string['jsconsent:actionoff'] = 'Turned off';
$string['jsconsent:actionon'] = 'Turned on';
$string['jsconsent:changed'] = 'The text of the consent changed since it was shown: read the current text, then accept it again.';
$string['jsconsent:disable'] = 'Turn the generated JavaScript off';
$string['jsconsent:disabled'] = 'The generated JavaScript is off: no module runs any more, and the generations no longer produce any.';
$string['jsconsent:enabled'] = 'The generated JavaScript is on. The modules still have to be reviewed before they are published.';
$string['jsconsent:history'] = 'History';
$string['jsconsent:killswitch'] = 'Kill switch';
$string['jsconsent:killswitch_desc'] = 'Stops at once the execution of every generated module, draft and published, without unpublishing anything nor calling Brandable. To stop them on a single page, administrators can add ?{$a}=1 to its address.';
$string['jsconsent:manage'] = 'Manage the generated JavaScript';
$string['jsconsent:mocktext'] = 'Demo text (demo generator): nothing is sent to Brandable.

By turning the generated JavaScript on, you allow the generations to add JavaScript modules to the theme. A module runs in the browser of each visitor, with the rights of that visitor (student, teacher, administrator).

Each module must be reviewed before it is published. The kill switch stops every module at once.';
$string['jsconsent:newversionmessage'] = 'The text of the consent to the generated JavaScript of Brandable changed. The modules already published keep running; to generate new ones, read and accept the new text: {$a}';
$string['jsconsent:newversionsubject'] = 'Brandable: new text of the consent to the generated JavaScript';
$string['jsconsent:nohistory'] = 'The generated JavaScript was never turned on.';
$string['jsconsent:noterms'] = 'The text of the consent could not be read from Brandable: {$a}';
$string['jsconsent:notrecorded'] = 'Brandable could not be told (it will be at the next activation).';
$string['jsconsent:outdated'] = 'The text of the consent changed. The modules already published keep running; to generate new ones, read and accept the new text.';
$string['jsconsent:resume'] = 'Run the modules again';
$string['jsconsent:resumed'] = 'The generated modules run again.';
$string['jsconsent:stateoff'] = 'The generated JavaScript is off.';
$string['jsconsent:stateon'] = 'The generated JavaScript is on (text of the consent: version {$a}).';
$string['jsconsent:suspend'] = 'Stop every module';
$string['jsconsent:suspendeddone'] = 'Every generated module is stopped.';
$string['jsconsent:suspendedstate'] = 'Kill switch on: no module runs.';
$string['jsconsent:version'] = 'Version of the text: {$a}';
$string['jsconsent:versioncolumn'] = 'Version of the text';
$string['jsdownload'] = 'Download the JavaScript';
$string['jsdownload:none'] = 'No module.';
$string['jsdownload:readme'] = 'Generated JavaScript of Brandable, site {$a->site}, {$a->label}.

One folder per module (target, and scope when not the whole site): source.js (source reviewed), compiled.js (function run by the theme), report.json (analysis of Brandable), signature.txt (signature of Brandable).

{$a->modules}';
$string['jsreview'] = 'Review of the JavaScript';
$string['jsreview:analysis'] = 'Analysis of Brandable (version {$a}):';
$string['jsreview:ask'] = 'Security review by AI';
$string['jsreview:askadmin'] = 'Only an administrator managing the generated JavaScript can record this review: publishing waits for it.';
$string['jsreview:check'] = 'I reviewed this code';
$string['jsreview:code'] = 'Code';
$string['jsreview:concerns'] = 'Points to check:';
$string['jsreview:currenterrors'] = 'The current analysis finds errors: this module will not run.';
$string['jsreview:defaultnotice'] = 'This report helps you review the module; it is not a guarantee. Read the code before publishing it.';
$string['jsreview:diff'] = 'Changes since the published version';
$string['jsreview:diffnew'] = 'Changes (new module)';
$string['jsreview:download'] = 'Download the JavaScript of the draft';
$string['jsreview:errors'] = 'The analysis found errors: this module never runs.';
$string['jsreview:intro'] = 'Each JavaScript module changed since the published version runs in the browser of every visitor, with their rights. Read its code before publishing: your review is recorded with the date, for this exact code.';
$string['jsreview:new'] = 'New';
$string['jsreview:nofindings'] = 'No finding.';
$string['jsreview:pending'] = '{$a} module(s) still to review.';
$string['jsreview:publish'] = 'Go on publishing';
$string['jsreview:reviewed'] = 'Reviewed';
$string['jsreview:seeplan'] = 'See the offers';
$string['jssuspend'] = 'Stop the JavaScript';
$string['jssuspend_help'] = 'Kill switch: stops at once every generated module, draft and published';
$string['messageprovider:cloudproposal'] = 'Version of the project proposed to this site from another environment';
$string['messageprovider:jsconsent'] = 'New text of the consent to the generated JavaScript';
$string['messageprovider:pluginupdate'] = 'New version of the Brandable plugin, or update required by Brandable';
$string['mock:accent'] = 'Subtle accents on links and cards.';
$string['mock:answered'] = 'Answer to "{$a}" taken into account.';
$string['mock:background'] = 'Background image {$a}.';
$string['mock:broken'] = 'A JavaScript module failing at its start (demo of the errors of the preview).';
$string['mock:brokenerror'] = 'Demo: the module fails at its start';
$string['mock:brokenfinding'] = 'Demo finding: the module throws an error.';
$string['mock:colour'] = 'Primary colour set to {$a}.';
$string['mock:dark'] = 'Dark navigation bar.';
$string['mock:favicon'] = 'Favicon {$a}.';
$string['mock:font'] = 'Montserrat for the headings.';
$string['mock:ghost'] = 'Added a rule targeting no element (verification test).';
$string['mock:hero'] = 'Hero banner at the top of the pages.';
$string['mock:herotitle'] = 'Welcome';
$string['mock:logo'] = 'Logo {$a}.';
$string['mock:needsjs'] = 'A welcome window would be shown at the first visit. Turn the JavaScript on in the Brandable settings of this site, then send your request again.';
$string['mock:question'] = 'Which element do you mean: the buttons, the links or the navigation bar?';
$string['mock:rounded'] = 'Rounder corners.';
$string['mock:scoped'] = 'Limited to {$a}.';
$string['mock:summary'] = 'Demo changes: {$a}';
$string['mock:welcome'] = 'A welcome window at the first visit (JavaScript module, to review before publishing).';
$string['mock:welcomebody'] = 'Welcome! Have a good year.';
$string['mock:welcometitle'] = 'Welcome';
$string['nosummary'] = 'No summary.';
$string['outdatedtemplates'] = 'Templates to review';
$string['outdatedtemplates_desc'] = 'Brandable overrides these templates from another version of their original: the parent theme or Moodle changed since. The overrides still apply; check these pages in the preview and ask for a change if needed.';
$string['parentnotice:customcss'] = 'The custom CSS of the parent theme is added after the changes of Brandable: where they set the same property, the custom CSS wins.';
$string['parentnotice:darkmode'] = 'The parent theme lets users switch to a dark mode of its own, which recolours the pages, the changes of Brandable included. The preview and the contrast checks only cover the light mode.';
$string['parentnotices'] = 'Parent theme';
$string['parentsettings'] = '{$a->theme}: {$a->page}';
$string['parentsettings_desc'] = 'These settings belong to the {$a} theme. They are saved in its own configuration and copied to Brandable automatically.';
$string['pick'] = 'Target an element';
$string['pick_help'] = 'Target an element of the preview (Alt+Shift+C; Alt+click targets directly)';
$string['pluginname'] = 'Brandable';
$string['pluginupdate:askadmin'] = 'Ask a site administrator to update the Brandable plugin.';
$string['pluginupdate:availablebody'] = 'Brandable {$a->release} is available (installed: {$a->current}). Download it from Brandable, then install it in Site administration > Plugins > Install plugins. Nothing is installed automatically.';
$string['pluginupdate:availablesubject'] = 'Brandable {$a->release} is available';
$string['pluginupdate:banner'] = 'Brandable {$a} is available';
$string['pluginupdate:capabilities'] = 'This request needs a newer version of the Brandable plugin.';
$string['pluginupdate:dismiss'] = 'Hide until the next version';
$string['pluginupdate:install'] = 'Download the update';
$string['pluginupdate:installaddon'] = 'Install plugins (Moodle)';
$string['pluginupdate:installed'] = 'Installed version: {$a}';
$string['pluginupdate:latest'] = 'Latest version: {$a}';
$string['pluginupdate:moodleupdate'] = 'See the updates of the plugins';
$string['pluginupdate:notes'] = 'Release notes';
$string['pluginupdate:requiredbody'] = 'Installed version: {$a->release}. Latest version: {$a->latest}. Until the plugin is updated, prompts cannot be sent; the published design is unchanged.';
$string['pluginupdate:requiredmessage'] = 'Brandable no longer supports this version of the plugin. Install the latest version to send prompts again.';
$string['pluginupdate:requiredsubject'] = 'Update the Brandable plugin';
$string['pluginupdate:requiredtitle'] = 'Update the Brandable plugin';
$string['pluginupdate:securitybadge'] = 'Security update';
$string['pluginupdate:securitybody'] = 'Brandable {$a->release} is a security update (installed: {$a->current}). Install it as soon as possible: download it from Brandable, then install it in Site administration > Plugins > Install plugins. This reminder is sent every week until it is installed.';
$string['pluginupdate:securitysubject'] = 'Security update: Brandable {$a->release}';
$string['pluginupdate:stillworks'] = 'The published design, the preview, publishing, undo and the export keep working.';
$string['pluginupdate:versionneeds'] = 'Plugin update needed';
$string['pluginupdate:versionneedsdetail'] = 'This version uses features this plugin does not know yet ({$a}).';
$string['previewbanner'] = 'Preview of draft revision {$a}';
$string['previewdark'] = 'Show the preview in the dark mode';
$string['previewframe'] = 'Site preview';
$string['previewinactive'] = 'This page does not show the draft: the preview is not active (or the page uses another theme).';
$string['previewurl'] = 'Preview address';
$string['privacy:metadata:api'] = 'The site is connected to a Brandable account. With each request, the Brandable API receives the language of the user; to generate design changes, it also receives the prompt and the context of the previewed page.';
$string['privacy:metadata:api:assets'] = 'The list of the images and fonts of the library of Brandable (names, sizes, alternative texts), and a thumbnail of the images attached to the prompt or named in it.';
$string['privacy:metadata:api:country'] = 'The country in the profile of the administrator who connects the site, when the site has no default country (it sets the billing currency of the organisation).';
$string['privacy:metadata:api:history'] = 'The prompts and summaries of the previous revisions of the draft.';
$string['privacy:metadata:api:lang'] = 'The language of the user (Brandable answers in it).';
$string['privacy:metadata:api:page'] = 'The address and type of the page shown in the preview.';
$string['privacy:metadata:api:pagetemplates'] = 'The templates of the page shown in the preview, with a sample of their data (personal fields redacted).';
$string['privacy:metadata:api:prompt'] = 'The prompt.';
$string['privacy:metadata:api:selection'] = 'The element targeted in the preview: its HTML (personal data redacted), ancestors and templates.';
$string['privacy:metadata:asset'] = 'Images and fonts of the Brandable library.';
$string['privacy:metadata:asset:alt'] = 'Alternative text of the image.';
$string['privacy:metadata:asset:timecreated'] = 'When the image or font was added.';
$string['privacy:metadata:asset:userid'] = 'Who added the image or font.';
$string['privacy:metadata:cloud'] = 'With the Pro offer, each version published is saved in the cloud of Brandable (history of the project).';
$string['privacy:metadata:cloud:author'] = 'Name of the administrator who published, and a hash of the site and of their id (never the id).';
$string['privacy:metadata:cloud:content'] = 'Overrides, version note, images and fonts of the version.';
$string['privacy:metadata:cloud:proposal'] = 'Message of a proposal to another environment of the project, and the name of its author.';
$string['privacy:metadata:cloudqueue'] = 'Versions waiting to be sent to the cloud.';
$string['privacy:metadata:cloudqueue:payload'] = 'Version to send: overrides, note and name of the author.';
$string['privacy:metadata:cloudqueue:timecreated'] = 'Time the version was put in the queue.';
$string['privacy:metadata:cloudqueue:userid'] = 'Administrator who published the version.';
$string['privacy:metadata:generation'] = 'Generations requested from the Brandable editor.';
$string['privacy:metadata:generation:pageurl'] = 'The page shown in the preview when the prompt was sent.';
$string['privacy:metadata:generation:prompt'] = 'The prompt.';
$string['privacy:metadata:generation:selection'] = 'The element targeted in the preview when the prompt was sent (personal data redacted).';
$string['privacy:metadata:generation:timecreated'] = 'The time the prompt was sent.';
$string['privacy:metadata:generation:userid'] = 'The user who sent the prompt.';
$string['privacy:metadata:jsconsent'] = 'Activations and deactivations of the generated JavaScript (consent to the text of Brandable).';
$string['privacy:metadata:jsconsent:enabled'] = 'Whether it was an activation or a deactivation.';
$string['privacy:metadata:jsconsent:timecreated'] = 'When.';
$string['privacy:metadata:jsconsent:userid'] = 'Administrator who turned the generated JavaScript on or off.';
$string['privacy:metadata:jsconsent:version'] = 'Version of the consent text.';
$string['privacy:metadata:jsreview'] = 'Reviews of the generated JavaScript modules before publishing.';
$string['privacy:metadata:jsreview:contenthash'] = 'Fingerprint of the code reviewed.';
$string['privacy:metadata:jsreview:target'] = 'Module reviewed.';
$string['privacy:metadata:jsreview:timecreated'] = 'When.';
$string['privacy:metadata:jsreview:userid'] = 'Who reviewed the module.';
$string['privacy:metadata:preference:updatedismissed'] = 'Version of the plugin whose update banner the user hid.';
$string['privacy:metadata:revision'] = 'Revisions of the site design created with Brandable.';
$string['privacy:metadata:revision:prompt'] = 'The prompt that produced the revision.';
$string['privacy:metadata:revision:summary'] = 'The summary of the changes of the revision.';
$string['privacy:metadata:revision:timecreated'] = 'The time the revision was created.';
$string['privacy:metadata:revision:userid'] = 'The user who created the revision.';
$string['privacy:metadata:version'] = 'Versions of the site design published with Brandable (the published one and the previous one).';
$string['privacy:metadata:version:note'] = 'The version note.';
$string['privacy:metadata:version:timecreated'] = 'The time the version was published.';
$string['privacy:metadata:version:userid'] = 'The user who published the version.';
$string['privacy:path:assets'] = 'Images and fonts';
$string['privacy:path:cloudqueue'] = 'Versions waiting for the cloud';
$string['privacy:path:generations'] = 'Generations';
$string['privacy:path:jsconsents'] = 'Consents to the generated JavaScript';
$string['privacy:path:jsreviews'] = 'Reviews of JavaScript modules';
$string['privacy:path:revisions'] = 'Revisions';
$string['privacy:path:versions'] = 'Published versions';
$string['prompt'] = 'Prompt';
$string['promptplaceholder'] = 'Describe the change you want…';
$string['publish'] = 'Publish';
$string['publish:intro'] = 'Every user will see this design. The draft becomes a version: its revisions and its thread are deleted, and a new session starts.';
$string['publish:note'] = 'Version note';
$string['publish:title'] = 'Publish the draft';
$string['published'] = 'Published';
$string['question'] = 'Brandable needs a clarification';
$string['question_answer'] = 'Answer';
$string['question_answered'] = 'Answered';
$string['question_answering'] = 'Your answer to the question';
$string['question_answerto'] = 'Answer to the question';
$string['question_hint'] = 'Type your answer in the box at the bottom: it is sent together with this question.';
$string['question_newrequest'] = 'Send a new request instead';
$string['question_placeholder'] = 'Your answer…';
$string['question_selection'] = 'Targeted element kept: {$a}';
$string['question_unanswered'] = 'Not answered';
$string['reactivatepreview'] = 'Reactivate the preview';
$string['redo'] = 'Redo';
$string['reload'] = 'Reload';
$string['removed'] = 'Removed';
$string['removeselection'] = 'Remove the targeted element';
$string['restore'] = 'Restore';
$string['retry'] = 'Try again';
$string['retry_help'] = 'Send the same prompt again, with the same targeted element';
$string['retryfonts_help'] = 'Downloads the fonts again, then applies the changes already generated (no new generation).';
$string['revisionnumber'] = 'Revision {$a}';
$string['run_initial'] = 'Generation';
$string['run_repair'] = 'Repair {$a}';
$string['scope_cm'] = 'Activity: {$a}';
$string['scope_course'] = 'Course: {$a}';
$string['scope_page'] = 'Page: {$a}';
$string['scope_pagetype'] = 'Page type: {$a}';
$string['scope_site'] = 'Whole site';
$string['scopechanged'] = 'Scope of {$a->target} changed: {$a->from} → {$a->to}.';
$string['scopeoptions_none'] = 'Open a page in the preview to scope this change to it.';
$string['send'] = 'Send';
$string['session:migrateddraft'] = 'Draft kept from the previous version of Brandable.';
$string['showsteps'] = 'Show the steps';
$string['status_detail'] = 'Draft: revision {$a->draft}. Published: revision {$a->published}.';
$string['status_published'] = 'Published';
$string['status_unpublished'] = 'Not published';
$string['targetedelement'] = 'Targeted element: {$a}';
$string['task:checkjsconsent'] = 'Check the text of the consent to the generated JavaScript';
$string['task:checkpluginupdate'] = 'Look for a new version of the Brandable plugin';
$string['task:cloudsend'] = 'Send the versions just published to the cloud of the Pro offer';
$string['task:cloudsync'] = 'Save the versions in the cloud of the Pro offer';
$string['task_cleanupgenerations'] = 'Clean up Brandable generations';
$string['task_syncparentsettings'] = 'Synchronise the parent theme settings';
$string['undo'] = 'Undo';
$string['undone'] = 'Undone';
$string['upgradefromalpha'] = 'This version of Brandable ({$a}) is a development version that cannot be upgraded. Uninstall the Brandable plugin (Site administration > Plugins > Plugins overview), then install the new version.';
$string['verification:contrast'] = '{$a->target}: text hard to read in the {$a->mode} mode (contrast {$a->ratio}:1, 4.5:1 expected): {$a->selector}';
$string['verification:fixedcolour'] = '{$a->target}: colour fixed in both modes, without a dark variant (brandable-dark): {$a->selector}';
$string['verification:jserror'] = 'JavaScript modules failing in the preview:';
$string['verification:jsstillfailing'] = 'Still failing in the preview, disabled on the page (already sent to a repair): {$a}';
$string['verification:kept'] = 'The revision is kept: no repair is left, or the draft changed meanwhile.';
$string['verification:mode_dark'] = 'dark';
$string['verification:mode_light'] = 'light';
$string['verification:nomatch'] = 'Selectors matching no element of the page:';
$string['verification:ok'] = '{$a} selector(s) checked on the page.';
$string['verification:okjs'] = '{$a->selectors} selector(s) checked on the page; {$a->modules} JavaScript module(s) ran without error.';
$string['verification:outside'] = '{$a->target}: {$a->selector} also matches {$a->count} element(s) out of the targeted element.';
$string['verification:running'] = 'Checking the selectors on the page…';
$string['version:current'] = 'Published version {$a}';
$string['version:migrated'] = 'Version kept from the previous version of Brandable.';
$string['version:previous'] = 'Previous version ({$a})';
$string['version:revert'] = 'Go back to the previous version';
