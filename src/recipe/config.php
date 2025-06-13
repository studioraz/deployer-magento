<?php
declare(strict_types=1);

namespace SR\Deployer;

use Deployer\Exception\RunException;
use function Deployer\add;
use function Deployer\set;
use function Deployer\run;
use function Deployer\before;
use function Deployer\after;

require_once 'recipe/common.php';

const CONFIG_IMPORT_NEEDED_EXIT_CODE = 2;
const DB_UPDATE_NEEDED_EXIT_CODE = 2;


// Configuration

// General Configuration
set('keep_releases', 2);

//set('magento_dir', 'src');
set('writable_mode', 'chmod');


// By default setup:static-content:deploy uses `en_US`.
// To change that, simply put `set('static_content_locales', 'en_US de_DE');`
// in you deployer script.
set('static_content_locales', 'en_US');

// Configuration

// You can also set the themes to run against. By default it'll deploy
// all themes - `add('magento_themes', ['Magento/luma', 'Magento/backend']);`
// If the themes are set as a simple list of strings, then all languages defined in {{static_content_locales}} are
// compiled for the given themes.
// Alternatively The themes can be defined as an associative array, where the key represents the theme name and
// the key contains the languages for the compilation (for this specific theme)
// Example:
// set('magento_themes', ['Magento/luma']); - Will compile this theme with every language from {{static_content_locales}}
// set('magento_themes', [
//     'Magento/luma'   => null,                              - Will compile all languages from {{static_content_locales}} for Magento/luma
//     'Custom/theme'   => 'en_US fr_FR'                      - Will compile only en_US and fr_FR for Custom/theme
//     'Custom/another' => '{{static_content_locales}} it_IT' - Will compile all languages from {{static_content_locales}} + it_IT for Custom/another
// ]); - Will compile this theme with every language
set('magento_themes', [

]);

// Static content deployment options, e.g. '--no-parent'
set('static_deploy_options', '--no-parent --no-js-bundle');

// Deploy frontend and adminhtml together as default
set('split_static_deployment', true);

// Use the default languages for the backend as default
set('static_content_locales_backend', '{{static_content_locales}}');

// backend themes to deploy. Only used if split_static_deployment=true
// This setting supports the same options/structure as {{magento_themes}}
set('magento_themes_backend', ['Magento/backend' => null]);

// Configuration

// Also set the number of concurrent jobs to run. The default is 1
// Update using: `set('static_content_jobs', '1');`
set('static_content_jobs', '4');

set('content_version', function () {
    return time();
});

// Magento directory relative to repository root. Use "." (default) if it is not located in a subdirectory
set('magento_dir', '.');


set('shared_files', [
    '{{magento_dir}}/app/etc/env.php',
    '{{magento_dir}}/var/.maintenance.ip',
]);
set('shared_dirs', [
    '{{magento_dir}}/var/composer_home',
    '{{magento_dir}}/var/log',
    '{{magento_dir}}/var/export',
    '{{magento_dir}}/var/report',
    '{{magento_dir}}/var/import',
    '{{magento_dir}}/var/import_history',
    '{{magento_dir}}/var/session',
    '{{magento_dir}}/var/importexport',
    '{{magento_dir}}/var/backups',
    '{{magento_dir}}/var/tmp',
    '{{magento_dir}}/pub/sitemap',
    '{{magento_dir}}/pub/media',
    '{{magento_dir}}/pub/static/_cache',
]);
set('writable_dirs', [
    '{{magento_dir}}/var',
    '{{magento_dir}}/pub/static',
    '{{magento_dir}}/pub/media',
    '{{magento_dir}}/generated',
    '{{magento_dir}}/var/page_cache',
]);
set('clear_paths', [
    '{{magento_dir}}/generated/*',
    '{{magento_dir}}/pub/static/_cache/*',
    '{{magento_dir}}/var/generation/*',
    '{{magento_dir}}/var/cache/*',
    '{{magento_dir}}/var/page_cache/*',
    '{{magento_dir}}/var/view_preprocessed/*',
]);

set('bin/magento', '{{release_or_current_path}}/{{magento_dir}}/bin/magento');

set('magento_version', function () {
    // detect version
    $versionOutput = run('{{bin/php}} {{bin/magento}} --version');
    preg_match('/(\d+\.?)+(-p\d+)?$/', $versionOutput, $matches);
    return $matches[0] ?? '2.0';
});

set('config_import_needed', function () {
    // detect if app:config:import is needed
    try {
        run('{{bin/php}} {{bin/magento}} app:config:status');
    } catch (RunException $e) {
        if ($e->getExitCode() == CONFIG_IMPORT_NEEDED_EXIT_CODE) {
            return true;
        }

        throw $e;
    }
    return false;
});

set('database_upgrade_needed', function () {
    // detect if setup:upgrade is needed
    try {
        run('{{bin/php}} {{bin/magento}} setup:db:status');
    } catch (RunException $e) {
        if ($e->getExitCode() == DB_UPDATE_NEEDED_EXIT_CODE) {
            return true;
        }

        throw $e;
    }

    return false;
});

// Deploy without setting maintenance mode if possible
set('enable_zerodowntime', true);


// Array of shared files that will be added to the default shared_files without overriding
set('additional_shared_files', []);
// Array of shared directories that will be added to the default shared_dirs without overriding
set('additional_shared_dirs', []);


// ################## Slack Notifications ##################
/** message templates  */
set('slack_text', '_{{user}}_ deploying `{{branch}}` to *{{alias}}*');
set('slack_success_text', 'Deploy to *{{alias}}* successful');
set('slack_failure_text', 'Deploy to *{{alias}}* failed');

/** hooks */
before('deploy', 'slack:notify');
after('deploy:success', 'slack:notify:success');
after('deploy:failed', 'slack:notify:failure');


# Global variable so other tasks/hosts can override if needed
set('config_store_path', '{{release_or_current_path}}/{{magento_dir}}/config/store');