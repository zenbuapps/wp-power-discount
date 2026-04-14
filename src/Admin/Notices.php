<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

final class Notices
{
    private const TRANSIENT_PREFIX = 'power_discount_notice_';

    public static function add(string $message, string $type = 'success'): void
    {
        if (!function_exists('set_transient') || !function_exists('get_current_user_id')) {
            return;
        }
        $key = self::TRANSIENT_PREFIX . get_current_user_id();
        $existing = get_transient($key);
        if (!is_array($existing)) {
            $existing = [];
        }
        $existing[] = ['message' => $message, 'type' => $type];
        set_transient($key, $existing, 60);
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'renderNotices']);
    }

    public function renderNotices(): void
    {
        if (!function_exists('get_transient') || !function_exists('get_current_user_id')) {
            return;
        }
        $key = self::TRANSIENT_PREFIX . get_current_user_id();
        $notices = get_transient($key);
        if (!is_array($notices) || $notices === []) {
            return;
        }
        delete_transient($key);

        foreach ($notices as $notice) {
            $type = in_array($notice['type'] ?? 'success', ['success', 'error', 'warning', 'info'], true)
                ? $notice['type']
                : 'info';
            $message = (string) ($notice['message'] ?? '');
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($type),
                esc_html($message)
            );
        }
    }
}
