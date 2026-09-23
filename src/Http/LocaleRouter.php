<?php

declare(strict_types=1);

namespace HScript\Http;

use InvalidArgumentException;

/** Public URL policy. Paths are relative to the installation root. */
final class LocaleRouter
{
    private array $locales = [];
    private const ALIASES = [
        'wallets' => 'balance/wallets',
        'balance/wallets' => 'balance/wallets',
        'operation' => 'balance/oper',
        'operations' => 'balance',
        'message/show' => 'message/show',
    ];

    public function __construct(private array $routes)
    {
    }

    /** Enabled CMS locales intersected with installed catalogs, in primary order. */
    public static function enabledLocales(array|string $configured, string $root): array
    {
        $installed = [];
        foreach (glob($root . '/lang/*.json') ?: [] as $file) {
            $locale = basename($file, '.json');
            if (preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/D', $locale)
                && is_array(json_decode((string)file_get_contents($file), true))) {
                $installed[] = $locale;
            }
        }
        $configured = is_array($configured) ? $configured : explode("\n", $configured);
        $enabled = [];
        foreach ($configured as $value) {
            if (!is_string($value)) {
                continue;
            }
            // Legacy Cfg hydration splits on CRLF; LF-only settings may arrive
            // as a single array entry containing several languages.
            foreach (preg_split('/\r\n|\r|\n/', $value) as $entry) {
                $locale = strtolower(trim($entry));
                if (in_array($locale, $installed, true) && !in_array($locale, $enabled, true)) {
                    $enabled[] = $locale;
                }
            }
        }
        // A broken/empty setting has a deterministic installed fallback.
        return $enabled ?: (in_array('en', $installed, true) ? ['en'] : array_slice($installed, 0, 1));
    }

    public function setLocales(array $locales): void
    {
        $this->locales = $locales;
    }

    public function primaryLocale(): string
    {
        return $this->locales[0] ?? '';
    }

    public function supports(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }

    public function isIndexable(string $module): bool
    {
        return ($this->routes[$module]['indexable'] ?? false) === true;
    }

    public function moduleForAlias(string $path): string
    {
        if ($path === '') {
            return isset($this->routes['index']) ? 'index' : '';
        }
        if (isset(self::ALIASES[$path], $this->routes[self::ALIASES[$path]])) {
            return self::ALIASES[$path];
        }
        foreach ($this->routes as $module => $route) {
            if ($route[0] === $path) {
                return $module;
            }
        }
        return '';
    }

    /** Parse before DB bootstrap; validate the candidate locale after CMS config loads. */
    public function parse(string $path): ?array
    {
        if (!$this->safePath($path)) {
            return null;
        }
        // Existing aliases (including multi-segment API paths) always win.
        $match = $this->matchPath($path);
        if ($match !== null) {
            return $match + ['locale' => null];
        }
        if (!preg_match('~^([a-z]{2,3}(?:-[a-z]{2})?)(?:/(.*))?$~D', $path, $parts)) {
            return null;
        }
        $match = $this->matchPath($parts[2] ?? '');
        if ($match === null || !$this->isIndexable($match['module'])) {
            return null;
        }
        return $match + ['locale' => $parts[1]];
    }

    private function matchPath(string $path): ?array
    {
        $module = $this->moduleForAlias($path);
        $id = null;
        if ($module === '' && str_ends_with($path, '/')) {
            $candidate = $this->moduleForAlias(rtrim($path, '/'));
            if ($this->isIndexable($candidate)) {
                $module = $candidate;
            }
        }
        if ($module === '' && preg_match('~^(.+)/(\d+)/(.*)$~D', $path, $parts)) {
            $module = $this->moduleForAlias($parts[1]);
            $id = $parts[2];
        }
        return $module === '' ? null : ['module' => $module, 'id' => $id, 'path' => $path];
    }

    private function safePath(string $path): bool
    {
        if (preg_match('/%(?![0-9a-f]{2})/i', $path)) {
            return false;
        }
        $decoded = rawurldecode($path);
        return !preg_match('~[\\\\\x00-\x20\x7f?#]|(?:^|/)\.{1,2}(?:/|$)|//~', $decoded)
            && !str_starts_with($decoded, '/')
            && !preg_match('/%(?:2f|5c|25)/i', $path);
    }

    /** Generate from a registered route only; never prefix technical/private routes. */
    public function url(string $module, string $path, ?string $locale = null, array $query = [], string $referralKey = '', bool $canonical = false): string
    {
        if (!$this->isIndexable($module)) {
            return $path;
        }
        $locale ??= $this->primaryLocale();
        $match = $this->matchPath($path);
        if (!$this->supports($locale) || !$this->safePath($path) || ($match['module'] ?? '') !== $module) {
            throw new InvalidArgumentException('Invalid public locale URL');
        }
        $path = $module === 'index' && $match['id'] === null ? '' : rtrim($path, '/') . '/';
        $parameters = $this->safeQuery($module, $query, $referralKey, $canonical);
        if ($match['id'] !== null) {
            unset($parameters['id']); // The path ID is authoritative.
        }
        $suffix = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        return $locale . '/' . $path . ($suffix === '' ? '' : '?' . $suffix);
    }

    /** Functional parameters are allowlisted; attribution is excluded from canonical URLs. */
    public function safeQuery(string $module, array $query, string $referralKey = '', bool $canonical = false): array
    {
        $safe = [];
        $keys = match ($module) {
            'news', 'faq', 'review' => ['page'],
            'news/show' => ['id'],
            default => [],
        };
        foreach ($keys as $key) {
            $value = $query[$key] ?? null;
            if (is_scalar($value) && preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value)) {
                $safe[$key] = (string)$value;
            }
        }
        if ($module === 'review' && in_array($query['sort'] ?? null, ['nTS', 'nTS0'], true)) {
            $safe['sort'] = $query['sort'];
        }
        if (!$canonical) {
            foreach (array_unique(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', $referralKey]) as $key) {
                if ($key === '' || in_array($key, ['page', 'id', 'sort', 'lang', 'url'], true)) {
                    continue;
                }
                $value = $query[$key] ?? null;
                if (is_string($value) && strlen($value) <= 512 && !preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    $safe[$key] = $value;
                }
            }
            if ($module === 'review' && isset($query['awating']) && is_scalar($query['awating'])) {
                $safe['awating'] = '';
            }
        }
        return $safe;
    }
}
