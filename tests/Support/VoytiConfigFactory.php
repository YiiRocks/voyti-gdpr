<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Composer\InstalledVersions;
use ReflectionClass;
use YiiRocks\Voyti\VoytiConfig;

/**
 * Builds a {@see VoytiConfig} from core's real `config/params.php` defaults, with
 * per-test overrides layered on top — avoids duplicating the default values here.
 */
final class VoytiConfigFactory
{
    public static function create(mixed ...$overrides): VoytiConfig
    {
        return new VoytiConfig(...[...self::defaults(), ...$overrides]);
    }

    /**
     * @psalm-suppress MixedArgument, UnresolvableInclude
     */
    private static function defaults(): array
    {
        $params = require InstalledVersions::getInstallPath('yiirocks/voyti') . '/config/params.php';

        // Replicate yiisoft/config's auto-merge of the views package's viewsPackagePaths param.
        $viewsPackageInstallPath = InstalledVersions::getInstallPath('yiirocks/voyti-views-bootstrap5');
        $viewsPackageParams = require $viewsPackageInstallPath . '/config/params.php';
        $params['yiirocks/voyti'] = array_merge($params['yiirocks/voyti'], $viewsPackageParams['yiirocks/voyti']);

        // Keep only keys that map to a VoytiConfig constructor parameter, in case config/params.php
        // carries params VoytiConfig does not accept.
        $constructorParameters = array_column(
            (new ReflectionClass(VoytiConfig::class))->getConstructor()?->getParameters() ?? [],
            'name',
        );

        $voytiParams = $params['yiirocks/voyti'];

        return array_intersect_key($voytiParams, array_flip($constructorParameters));
    }
}
