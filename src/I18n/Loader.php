<?php
declare(strict_types=1);

namespace PowerDiscount\I18n;

final class Loader
{
    /** @var array<string, array<string, string>> locale => [msgid => msgstr] */
    private array $cache = [];

    public function register(): void
    {
        add_action('init', [$this, 'loadTextDomain']);
        add_filter('gettext', [$this, 'translate'], 10, 3);
        add_filter('gettext_with_context', [$this, 'translateWithContext'], 10, 4);
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain(
            'power-discount',
            false,
            dirname(POWER_DISCOUNT_BASENAME) . '/languages'
        );
    }

    /**
     * Override `__()` / `_e()` / `esc_html__()` etc. for our text domain
     * using a plain PHP array of translations. Avoids needing compiled .mo files.
     */
    public function translate(string $translated, string $original, string $domain): string
    {
        if ($domain !== 'power-discount') {
            return $translated;
        }
        $map = $this->loadMap();
        return $map[$original] ?? $translated;
    }

    public function translateWithContext(string $translated, string $original, string $context, string $domain): string
    {
        if ($domain !== 'power-discount') {
            return $translated;
        }
        $map = $this->loadMap();
        return $map[$original] ?? $translated;
    }

    /**
     * @return array<string, string>
     */
    private function loadMap(): array
    {
        $locale = function_exists('determine_locale') ? determine_locale() : 'en_US';
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }

        $file = POWER_DISCOUNT_DIR . 'languages/' . $locale . '.php';
        if (!file_exists($file)) {
            $this->cache[$locale] = [];
            return $this->cache[$locale];
        }

        $map = include $file;
        $this->cache[$locale] = is_array($map) ? $map : [];
        return $this->cache[$locale];
    }
}
