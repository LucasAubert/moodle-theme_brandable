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
 * Strings of theme_brandablert.
 *
 * @package    theme_brandablert
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['choosereadme'] = 'Thème exporté depuis Brandable. Son design suit les réglages du thème parent.';
$string['configtitle'] = 'Réglages du thème';
$string['js'] = 'JavaScript généré';
$string['js:enabled'] = 'Exécuter les modules JavaScript';
$string['js:enabled_desc'] = 'Les modules de Brandable s\'exécutent dans le navigateur de chaque visiteur, avec ses droits (jamais sur les pages d\'administration). Activé à l\'export s\'ils l\'étaient sur le site de Brandable.';
$string['js:suspended'] = 'Kill switch';
$string['js:suspended_desc'] = 'Coupe d\'un coup tous les modules, sans désinstaller le thème.';
$string['js_desc'] = 'Modules JavaScript générés par Brandable, relus sur le site qui a exporté le thème. Leur source est dans brandable/js/ du thème. Pour les couper sur une seule page, les administrateurs peuvent ajouter ?{$a}=1 à son adresse.';
$string['licence'] = 'Licence Brandable';
$string['licence:banner:expired'] = 'La licence Brandable de ce thème a expiré : l’abonnement s’est terminé il y a plus de 30 jours. L’affichage ne change pas ; seuls les administrateurs voient ce message. <a href="{$a->pricing}" target="_blank" rel="noopener">Renouveler l’abonnement</a>.';
$string['licence:banner:grace'] = 'Abonnement Brandable terminé : la licence de ce thème est valide jusqu’au {$a->graceuntil}. <a href="{$a->pricing}" target="_blank" rel="noopener">Renouveler</a>.';
$string['licence:banner:offlineending'] = 'Licence Brandable hors ligne valable jusqu’au {$a->exp} : téléchargez-en une nouvelle depuis le dashboard Brandable (Licences et déploiements) et collez-la dans les <a href="{$a->settings}">réglages du thème</a>.';
$string['licence:banner:revoked'] = 'La licence Brandable de ce thème a été révoquée depuis le dashboard Brandable. L’affichage ne change pas ; seuls les administrateurs voient ce message. Exportez à nouveau le thème depuis Brandable, ou contactez Brandable.';
$string['licence:banner:unverified'] = 'La licence Brandable de ce thème ne peut pas être vérifiée (dernier signal : {$a->lastsignal}) : le signal quotidien n’atteint pas Brandable, ou la licence hors ligne a expiré. L’affichage ne change pas ; seuls les administrateurs voient ce message. Vérifiez les <a href="{$a->settings}">réglages du thème</a>.';
$string['licence:heartbeat'] = 'Signal quotidien';
$string['licence:heartbeat_desc'] = 'Une fois par jour, le thème envoie à Brandable l’URL du site, le nom et la version du thème et la version de Moodle, et reçoit sa licence. Aucune donnée sur les utilisateurs ni les cours. Désactivé, seule la licence hors ligne compte.';
$string['licence:notification:subject'] = 'Licence Brandable du thème';
$string['licence:offline'] = 'Licence hors ligne';
$string['licence:offline:invalid'] = 'Cette licence hors ligne n’est pas valide pour ce thème (autre thème, expirée, ou signée avec une clé que cette version du thème ne connaît pas : exportez à nouveau le thème).';
$string['licence:offline:notoffline'] = 'Ce jeton n’est pas une licence hors ligne active.';
$string['licence:offline_desc'] = 'Pour un site sans accès sortant, ou si le signal quotidien est désactivé : collez la licence téléchargée depuis le dashboard Brandable (Licences et déploiements). Elle sert quand le signal quotidien est désactivé ou en échec.';
$string['licence:source:offline'] = 'Licence hors ligne, valable jusqu’au {$a->exp}.';
$string['licence:source:online'] = 'Dernier signal : {$a->lastsignal}.';
$string['licence:state:active'] = 'La licence est active.';
$string['licence:state:expired'] = 'La licence a expiré.';
$string['licence:state:grace'] = 'L’abonnement est terminé : la licence est valide jusqu’au {$a->graceuntil}.';
$string['licence:state:revoked'] = 'La licence a été révoquée.';
$string['licence:state:unverified'] = 'La licence ne peut pas être vérifiée.';
$string['licence:test'] = 'Licence de test (développement).';
$string['messageprovider:licence'] = 'État de la licence Brandable du thème';
$string['parentsettings'] = 'Le design suit les réglages du thème parent ({$a->parent}) : <a href="{$a->url}">les modifier</a>, ils sont recopiés dans ce thème.';
$string['pluginname'] = 'brandablert';
$string['privacy:metadata:licence'] = 'Signal quotidien de la licence Brandable (désactivable dans les réglages du thème).';
$string['privacy:metadata:licence:moodleversion'] = 'La version de Moodle.';
$string['privacy:metadata:licence:siteurl'] = 'L’URL du site.';
$string['privacy:metadata:licence:theme'] = 'Le nom et la version du thème.';
$string['task:licenceheartbeat'] = 'Signal quotidien de la licence Brandable';
$string['task:syncparentsettings'] = 'Copie des réglages du thème parent';
