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

namespace theme_brandable\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use theme_brandable\local\account\account;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\cloud\cloud;
use theme_brandable\local\plugin_info;

/**
 * History of the versions of the project (contract 13.5, spec C8): a page of versions, the one this site runs, the
 * versions of the other environments, the rights of the site (without the cloud: the Pro offer is offered).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_cloud_history extends external_api {
    /** @var int Versions of a page. */
    public const PAGE = 20;

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cursor' => new external_value(PARAM_ALPHANUMEXT, 'Next page (nextCursor), empty for the first', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Returns a page of the history.
     *
     * @param string $cursor Next page.
     * @return array
     */
    public static function execute(string $cursor = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cursor' => $cursor]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('theme/brandable:edit', $context);

        $result = ['access' => cloud::NONE, 'retaineduntil' => 0, 'versions' => [], 'nextcursor' => '', 'environment' => '',
            'currentnumber' => 0, 'environments' => [], 'versionsurl' => '', 'upgradeitem' => '', 'errormessage' => ''];
        try {
            $account = account::get();
            $result['versionsurl'] = preg_match('~^https?://~i', (string) ($account['urls']['versions'] ?? ''))
                ? (string) $account['urls']['versions'] : '';
            // Without the cloud: the monthly price of the first plan with it (the Pro offer).
            foreach ($account['offers']['plans'] ?? [] as $offer) {
                if (
                    $result['upgradeitem'] === '' && in_array('cloud', (array) ($offer['features'] ?? []), true)
                        && ($offer['price']['interval'] ?? '') === 'month' && (int) ($offer['maxSites'] ?? 1) <= 1
                ) {
                    $result['upgradeitem'] = (string) $offer['id'];
                }
            }
            if (cloud::get_access($account) === cloud::NONE) {
                return $result;
            }
            $state = cloud::get_state(true);
            $result['access'] = (string) ($state['access'] ?? cloud::NONE);
            $result['retaineduntil'] = (int) strtotime((string) ($state['retainedUntil'] ?? '')) ?: 0;
            $result['environment'] = (string) ($state['environment']['name'] ?? '');
            $current = (string) ($state['environment']['published']['versionId'] ?? '');
            $result['currentnumber'] = (int) ($state['environment']['published']['number'] ?? 0);
            foreach ((array) ($state['environments'] ?? []) as $environment) {
                $result['environments'][] = [
                    'id' => (string) ($environment['id'] ?? ''),
                    'name' => (string) ($environment['name'] ?? ''),
                    'kind' => (string) ($environment['kind'] ?? ''),
                    'number' => (int) ($environment['published']['number'] ?? 0),
                ];
            }
            if ($result['access'] === cloud::NONE) {
                return $result;
            }
            $page = cloud::client()->request('GET', '/cloud/versions?' . http_build_query(array_filter(['limit' => self::PAGE,
                'cursor' => $params['cursor']]), '', '&'));
            foreach ((array) ($page['versions'] ?? []) as $version) {
                $result['versions'][] = [
                    'id' => (string) ($version['id'] ?? ''),
                    'number' => (int) ($version['number'] ?? 0),
                    'localnumber' => (int) ($version['localNumber'] ?? 0),
                    'note' => (string) ($version['note'] ?? ''),
                    'author' => (string) ($version['author']['name'] ?? ''),
                    'publishedat' => (int) strtotime((string) ($version['publishedAt'] ?? '')) ?: 0,
                    'environment' => (string) ($version['environment']['name'] ?? ''),
                    'origin' => (string) ($version['origin'] ?? ''),
                    'publishedin' => implode(', ', array_column((array) ($version['publishedIn'] ?? []), 'name')),
                    'current' => $current !== '' && (string) ($version['id'] ?? '') === $current,
                    // Capabilities this plugin lacks to apply it (contract 15.4): shown before any attempt.
                    'missing' => implode(', ', plugin_info::missing((array) ($version['requires'] ?? []))),
                ];
            }
            $result['nextcursor'] = (string) ($page['nextCursor'] ?? '');
        } catch (api_exception $e) {
            $result['errormessage'] = $e->displaymessage;
        }
        return $result;
    }

    /**
     * Describes the result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'access' => new external_value(PARAM_ALPHA, 'full, read or none'),
            'retaineduntil' => new external_value(PARAM_INT, 'End of the reading only (access read), 0 otherwise'),
            'versions' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_RAW, 'Version of the project'),
                'number' => new external_value(PARAM_INT, 'Number in the project'),
                'localnumber' => new external_value(PARAM_INT, 'Number in the Moodle it came from, 0 if none'),
                'note' => new external_value(PARAM_RAW, 'Note'),
                'author' => new external_value(PARAM_RAW, 'Author, as given by Brandable'),
                'publishedat' => new external_value(PARAM_INT, 'Publication'),
                'environment' => new external_value(PARAM_RAW, 'Environment it came from'),
                'origin' => new external_value(PARAM_ALPHA, 'publish, restore, pull or backfill'),
                'publishedin' => new external_value(PARAM_RAW, 'Environments where it is published now'),
                'current' => new external_value(PARAM_BOOL, 'Whether this site runs it'),
                'missing' => new external_value(PARAM_RAW, 'Capabilities the plugin lacks to apply it, empty if none'),
            ])),
            'nextcursor' => new external_value(PARAM_RAW, 'Next page, empty on the last one'),
            'environment' => new external_value(PARAM_RAW, 'Environment of this site'),
            'currentnumber' => new external_value(PARAM_INT, 'Number of the version this site runs, 0 if none'),
            'environments' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_RAW, 'Environment'),
                'name' => new external_value(PARAM_RAW, 'Name'),
                'kind' => new external_value(PARAM_ALPHA, 'dev, staging or prod'),
                'number' => new external_value(PARAM_INT, 'Version it runs, 0 if none'),
            ]), 'Other environments of the project'),
            'versionsurl' => new external_value(PARAM_RAW, 'Page of the versions in the dashboard, empty if none'),
            'upgradeitem' => new external_value(PARAM_RAW, 'Offer with the cloud (billing_checkout), empty if none'),
            'errormessage' => new external_value(PARAM_RAW, 'Why the history could not be read, empty if it was'),
        ]);
    }
}
