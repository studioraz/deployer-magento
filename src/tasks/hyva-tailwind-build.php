<?php

namespace SR\Deployer;

use function Deployer\desc;
use function Deployer\get;
use function Deployer\run;
use function Deployer\task;
use function Deployer\writeln;
use function Deployer\test;

desc('Builds TailwindCSS for Hyva themes');
task('hyva:tailwind:build', function () {
    if (test('[ -f {{hyva_tailwind_package_path}} ]')) {
        writeln('Found package.json at magento root folder. Running npm ci and build-all');
        run('cd {{release_or_current_path}}/{{magento_dir}} && npm ci && npm run build-all');
    } else {
        writeln('No package.json at magento root folder. Skipping Tailwind build.');
    }
});
