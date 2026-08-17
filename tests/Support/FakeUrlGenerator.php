<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Stringable;
use Yiisoft\Router\UrlGeneratorInterface;

final class FakeUrlGenerator implements UrlGeneratorInterface
{
    private array $defaultArguments = [];
    private string $uriPrefix = '';

    public function generate(string $name, array $arguments = [], array $queryParameters = [], ?string $hash = null): string
    {
        $url = '//' . $name;

        if ($arguments !== []) {
            $url .= '?' . http_build_query($arguments);
        }

        if ($queryParameters !== []) {
            $separator = $arguments !== [] ? '&' : '?';
            $url .= $separator . http_build_query($queryParameters);
        }

        if ($hash !== null) {
            $url .= '#' . $hash;
        }

        return $url;
    }

    public function generateAbsolute(
        string $name,
        array $arguments = [],
        array $queryParameters = [],
        ?string $hash = null,
        ?string $scheme = null,
        ?string $host = null,
    ): string {
        $relative = $this->generate($name, $arguments, $queryParameters, $hash);

        $scheme ??= 'https';
        $host ??= 'example.com';

        return $scheme . '://' . $host . $relative;
    }

    public function generateFromCurrent(
        array $replacedArguments,
        array $queryParameters = [],
        ?string $hash = null,
        ?string $fallbackRouteName = null,
    ): string {
        $name = $fallbackRouteName ?? '';

        return $this->generate($name, $replacedArguments, $queryParameters, $hash);
    }

    public function getUriPrefix(): string
    {
        return $this->uriPrefix;
    }

    public function setDefaultArgument(string $name, bool|float|int|string|Stringable|null $value): void
    {
        $this->defaultArguments[$name] = $value;
    }

    public function setUriPrefix(string $name): void
    {
        $this->uriPrefix = $name;
    }
}
