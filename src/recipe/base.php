<?php

namespace SR\Deployer;

require_once 'recipe/common.php';
require_once 'contrib/cachetool.php';
require_once 'contrib/slack.php';
require_once  __DIR__ . '/config.php';
require_once  __DIR__ . '/artifact.php';

use Deployer\Host;
use Deployer\ConfigurationException;
use function Deployer\Support\array_is_list;
use function Deployer\add;
use function Deployer\desc;
use function Deployer\run;
use function Deployer\parse;
use function Deployer\writeln;
use function Deployer\invoke;
use function Deployer\before;
use function Deployer\after;
use function Deployer\select;
use function Deployer\task;


const ENV_CONFIG_FILE_PATH = 'app/etc/env.php';
const TMP_ENV_CONFIG_FILE_PATH = 'app/etc/env_tmp.php';

add('recipes', ['magento2']);



// To work correctly with artifact deployment, it is necessary to set the MAGE_MODE correctly in `app/etc/config.php`
// e.g.
// ```php
// 'MAGE_MODE' => 'production'
// ```
desc('Compiles magento di');
task('magento:compile', function () {
    run("{{bin/php}} {{bin/magento}} setup:di:compile");
    run('cd {{release_or_current_path}}/{{magento_dir}} && {{bin/composer}} dump-autoload -o');
});



// To work correctly with artifact deployment it is necessary to set `system/dev/js` , `system/dev/css` and `system/dev/template`
// in `app/etc/config.php`, e.g.:
// ```php
// 'system' => [
//     'default' => [
//         'dev' => [
//             'js' => [
//                 'merge_files' => '1',
//                 'minify_files' => '1'
//             ],
//             'css' => [
//                 'merge_files' => '1',
//                 'minify_files' => '1'
//             ],
//             'template' => [
//                 'minify_html' => '1'
//             ]
//         ]
//     ]
// ```
desc('Deploys assets');
task('magento:deploy:assets', function () {
    $themesToCompile = '';
    if (get('split_static_deployment')) {
        invoke('magento:deploy:assets:adminhtml');
        invoke('magento:deploy:assets:frontend');
    } else {
        if (count(get('magento_themes')) > 0) {
            $themes = array_is_list(get('magento_themes')) ? get('magento_themes') : array_keys(get('magento_themes'));
            foreach ($themes as $theme) {
                $themesToCompile .= ' -t ' . $theme;
            }
        }
        run("{{bin/php}} {{release_or_current_path}}/bin/magento setup:static-content:deploy -f --content-version={{content_version}} {{static_deploy_options}} {{static_content_locales}} $themesToCompile -j {{static_content_jobs}}");
    }
});

desc('Deploys assets for backend only');
task('magento:deploy:assets:adminhtml', function () {
    magentoDeployAssetsSplit('backend');
});

desc('Deploys assets for frontend only');
task('magento:deploy:assets:frontend', function () {
    magentoDeployAssetsSplit('frontend');
});

/**
 * @phpstan-param 'frontend'|'backend' $area
 *
 * @throws ConfigurationException
 */
function magentoDeployAssetsSplit(string $area)
{
    if (!in_array($area, ['frontend', 'backend'], true)) {
        throw new ConfigurationException("\$area must be either 'frontend' or 'backend', '$area' given");
    }

    $isFrontend = $area === 'frontend';
    $suffix = $isFrontend
        ? ''
        : '_backend';

    $themesConfig = get("magento_themes$suffix");
    $defaultLanguages = get("static_content_locales$suffix");
    $useDefaultLanguages = array_is_list($themesConfig);

    /** @var list<string> $themes */
    $themes = $useDefaultLanguages
        ? array_values($themesConfig)
        : array_keys($themesConfig);

    $staticContentArea = $isFrontend
        ? 'frontend'
        : 'adminhtml';

    if ($useDefaultLanguages) {
        $themes = '-t ' . implode(' -t ', $themes);

        run("{{bin/php}} {{bin/magento}} setup:static-content:deploy -f --area=$staticContentArea --content-version={{content_version}} {{static_deploy_options}} $defaultLanguages $themes -j {{static_content_jobs}}");
        return;
    }

    foreach ($themes as $theme) {
        $languages = parse($themesConfig[$theme] ?? $defaultLanguages);

        run("{{bin/php}} {{bin/magento}} setup:static-content:deploy -f --area=$staticContentArea --content-version={{content_version}} {{static_deploy_options}} $languages -t $theme -j {{static_content_jobs}}");
    }
}

desc('Syncs content version');
task('magento:sync:content_version', function () {
    $timestamp = time();
    on(select('all'), function (Host $host) use ($timestamp) {
        $host->set('content_version', $timestamp);
    });
})->once();

before('magento:deploy:assets', 'magento:sync:content_version');



desc('Enables maintenance mode');
task('magento:maintenance:enable', function () {
    // do not use {{bin/magento}} because it would be in "release" but the maintenance mode must be set in "current"
    run("if [ -d $(echo {{current_path}}) ]; then {{bin/php}} {{current_path}}/{{magento_dir}}/bin/magento maintenance:enable; fi");
});

desc('Disables maintenance mode');
task('magento:maintenance:disable', function () {
    // do not use {{bin/magento}} because it would be in "release" but the maintenance mode must be set in "current"
    run("if [ -d $(echo {{current_path}}) ]; then {{bin/php}} {{current_path}}/{{magento_dir}}/bin/magento maintenance:disable; fi");
});

desc('Set maintenance mode if needed');
task('magento:maintenance:enable-if-needed', function () {
    ! get('enable_zerodowntime') || get('database_upgrade_needed') || get('config_import_needed') ?
        invoke('magento:maintenance:enable') :
        writeln('Config and database up to date => no maintenance mode');
});

desc('Config Import');
task('magento:config:import', function () {
    if (get('config_import_needed')) {
        run('{{bin/php}} {{bin/magento}} app:config:import --no-interaction');
    } else {
        writeln('App config is up to date => import skipped');
    }
});

desc('Upgrades magento database');
task('magento:upgrade:db', function () {
    if (get('database_upgrade_needed')) {
        run("{{bin/php}} {{bin/magento}} setup:db-schema:upgrade --no-interaction");
        run("{{bin/php}} {{bin/magento}} setup:db-data:upgrade --no-interaction");
    } else {
        writeln('Database schema is up to date => upgrade skipped');
    }
})->once();

desc('Flushes Magento Cache');
task('magento:cache:flush', function () {
    run("{{bin/php}} {{bin/magento}} cache:flush");
});


after('deploy:symlink', 'magento:cache:flush');

after('deploy:failed', 'magento:maintenance:disable');


desc('Adds additional files and dirs to the list of shared files and dirs');
task('deploy:additional-shared', function () {
    add('shared_files', get('additional_shared_files'));
    add('shared_dirs', get('additional_shared_dirs'));
});

// **************************** Cron utility tasks **************************/

/**
 * Remove cron from crontab and kill running cron jobs
 * To use this feature, add the following to your deployer scripts:
 *  ```php
 *  after('magento:maintenance:enable-if-needed', 'magento:cron:stop');
 *  ```
 */
desc('Remove cron from crontab and kill running cron jobs');
task('magento:cron:stop', function () {
    if (has('previous_release')) {
        run('{{bin/php}} {{previous_release}}/{{magento_dir}}/bin/magento cron:remove');
    }

    run('pgrep -U "$(id -u)" -f "bin/magento +(cron:run|queue:consumers:start)" | xargs -r kill');
});

/**
 * Install cron in crontab
 * To use this feature, add the following to your deployer scripts:
 *   ```php
 *   after('magento:upgrade:db', 'magento:cron:install');
 *   ```
 */
desc('Install cron in crontab');
task('magento:cron:install', function () {
    run('cd {{release_or_current_path}}');
    run('{{bin/php}} {{bin/magento}} cron:install');
});


/**
 * Magento2 deployment operations
 */

desc('Magento2 deployment operations');
task('deploy:magento', [
    'magento:build',
    'magento:maintenance:enable-if-needed',
    'magento:config:import',
    'magento:upgrade:db',
    'magento:maintenance:disable',
]);

desc('Magento2 build operations');
task('magento:build', [
    'magento:compile',
    'magento:deploy:assets',
]);

desc('Deploys your project');
task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:clear_paths',
    'deploy:magento',
    'deploy:publish',
]);

// **************************** Update Cache Key Prefix ****************************/

/**
 * Update cache id_prefix on deploy so that you are compiling against a fresh cache
 * Reference Issue: https://github.com/davidalger/capistrano-magento2/issues/151
 * To use this feature, add the following to your deployer scripts:
 * ```php
 * after('deploy:shared', 'magento:set_cache_prefix');
 * after('deploy:magento', 'magento:cleanup_cache_prefix');
 * ```
 **/
desc('Update cache id_prefix');
task('magento:set_cache_prefix', function () {
    $tmpConfigFile = tempnam(sys_get_temp_dir(), 'deployer_config');
    download('{{deploy_path}}/shared/{{magento_dir}}/' . ENV_CONFIG_FILE_PATH, $tmpConfigFile);
    $envConfigArray = include($tmpConfigFile);

    $prefixUpdate = get('magento_cache_id_prefix') . '_' . get('release_name') . '_';

    if (isset($envConfigArray['cache']['frontend']['default']['backend_options']['preload_keys'])) {
        $oldPrefix = $envConfigArray['cache']['frontend']['default']['id_prefix'];
        $preloadKeys = $envConfigArray['cache']['frontend']['default']['backend_options']['preload_keys'];
        $newPreloadKeys = [];
        foreach ($preloadKeys as $preloadKey) {
            $newPreloadKeys[] = preg_replace('/^' . $oldPrefix . '/', $prefixUpdate, $preloadKey);
        }
        $envConfigArray['cache']['frontend']['default']['backend_options']['preload_keys'] = $newPreloadKeys;
    }

    $envConfigArray['cache']['frontend']['default']['id_prefix'] = $prefixUpdate;
    $envConfigArray['cache']['frontend']['page_cache']['id_prefix'] = $prefixUpdate;

    $envConfigStr = '<?php return ' . var_export($envConfigArray, true) . ';';
    file_put_contents($tmpConfigFile, $envConfigStr);

    upload($tmpConfigFile, '{{deploy_path}}/shared/{{magento_dir}}/' . TMP_ENV_CONFIG_FILE_PATH);

    unlink($tmpConfigFile);

    run('rm {{release_or_current_path}}/{{magento_dir}}/' . ENV_CONFIG_FILE_PATH);

    run('{{bin/symlink}} {{deploy_path}}/shared/{{magento_dir}}/' . TMP_ENV_CONFIG_FILE_PATH . ' {{release_path}}/{{magento_dir}}/' . ENV_CONFIG_FILE_PATH);
});

/**
 * After successful deployment, move the tmp_env.php file to env.php ready for next deployment
 */
desc('Cleanup cache id_prefix env files');
task('magento:cleanup_cache_prefix', function () {
    run('rm {{deploy_path}}/shared/' . ENV_CONFIG_FILE_PATH);
    run('rm {{release_or_current_path}}/' . ENV_CONFIG_FILE_PATH);
    run('mv {{deploy_path}}/shared/' . TMP_ENV_CONFIG_FILE_PATH . ' {{deploy_path}}/shared/' . ENV_CONFIG_FILE_PATH);
    // Symlink shared dir to release dir
    run('{{bin/symlink}} {{deploy_path}}/shared/' . ENV_CONFIG_FILE_PATH . ' {{release_path}}/' . ENV_CONFIG_FILE_PATH);
});

after('deploy:shared', 'magento:set_cache_prefix');
after('deploy:magento', 'magento:cleanup_cache_prefix');
