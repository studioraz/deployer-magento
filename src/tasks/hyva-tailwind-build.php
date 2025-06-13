<?php

namespace SR\Deployer;

use function Deployer\desc;
use function Deployer\get;
use function Deployer\run;
use function Deployer\task;
use function Deployer\writeln;

desc('Builds Tailwind CSS for Hyva themes');
task('hyva:tailwind:build:', function () {
    $themes = get('hyva_themes');

    if (!is_array($themes)) {
        writeln('No Hyva themes found. Skipping Tailwind build.');
        return;
    }

    foreach ($themes as $theme => $path) {
        if (!is_string($path)) {
            writeln("The path for theme {$theme} is not a valid string.");
            continue;
        }
        run("cd {{release_or_current_path}}/{{magento_dir}}/$path/web/tailwind && npm install && npm run build-prod");
    }
});