<?php
namespace SR\Deployer;

/**
 * Loads this package’s autoloader plus all Magento-specific recipes.
 */
final class RecipeLoader
{
    public static function load(): void
    {
        // Include base recipe (which pulls in config and artifact)
        require __DIR__ . '/recipe/base.php';
    }
}
