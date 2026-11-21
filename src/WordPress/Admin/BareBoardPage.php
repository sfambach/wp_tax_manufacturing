<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\WordPress\ManufacturingService;

/**
 * D-1572, his word 21.11. 19:25 «aus einer Revision dann ein Platinenmaterial generieren»: a button at a board revision's
 * record, a small page that asks once, and the act that makes (or finds) the bare board material and opens it.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/fertigung/konzept.md
 */
final class BareBoardPage
{
    public const PAGE   = 'taxmod-mf-platine';
    public const ACTION = 'taxmod_mf_bare_board';
    private const NONCE = 'taxmod_mf_bare_board';

    public static function boot(): void
    {
        add_filter('taxmod_record_actions', [self::class, 'action'], 10, 3);
        add_action('admin_menu', [self::class, 'register'], 20);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     * @return array<int, array<string, mixed>>
     */
    public static function action(array $actions, int $nodeId, int $recordId): array
    {
        $service = new ManufacturingService();

        if (! current_user_can('manage_options') || ! $service->canMakeBareBoard() || $nodeId !== $service->structure()->node('board_revision')) {
            return $actions;
        }

        $actions[] = [
            'label' => __('Platinenmaterial erzeugen', 'taxmod-manufacturing'),
            'url'   => admin_url('admin.php?page=' . self::PAGE . '&revision=' . $recordId),
            'title' => __('Legt die nackte Platine als Material an, mit Verweis auf diese Revision', 'taxmod-manufacturing'),
        ];

        return $actions;
    }

    /** A page without a menu entry: it is reached from the button only. */
    public static function register(): void
    {
        add_submenu_page('taxmod', __('Platinenmaterial erzeugen', 'taxmod-manufacturing'), '', 'manage_options', self::PAGE, static function (): void {
            print self::render(absint(wp_unslash((string) ($_GET['revision'] ?? '0')))); // phpcs:ignore WordPress.Security -- read-only page, escaped in render()
        });
        remove_submenu_page('taxmod', self::PAGE);
    }

    private static function render(int $revision): string
    {
        if (! current_user_can('manage_options')) {
            return '';
        }

        $service = new ManufacturingService();
        $name    = $service->labelsOf([$revision])[$revision] ?? '#' . $revision;
        $failed  = sanitize_text_field(wp_unslash((string) ($_GET['fehler'] ?? '')));
        $html    = ListPage::recordHead(__('Revision', 'taxmod-manufacturing'), $name, self::recordUrl($service->structure()->node('board_revision'), $revision), __('Zurück zur Revision', 'taxmod-manufacturing'));

        if ($failed !== '') {
            $html .= '<div class="notice notice-error inline"><p>' . esc_html($failed) . '</p></div>';
        }

        $have = $service->bareBoards()[$revision] ?? 0;

        if ($have > 0) {
            return self::framed($html . '<p>' . esc_html__('Zu dieser Revision gibt es schon eine Platine.', 'taxmod-manufacturing') . '</p>'
                . ListPage::toolbar([ListPage::button(__('Platine öffnen', 'taxmod-manufacturing'), self::recordUrl($service->structure()->node('bare_board'), $have), true)]));
        }

        return self::framed($html
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="revision" value="' . esc_attr((string) $revision) . '">'
            . '<p>' . esc_html__('Legt unter «Platinen» ein Material an: gleicher Name, die Bilder der Revision, «für Revision» zeigt hierher. Rückgängig über Undo.', 'taxmod-manufacturing') . '</p>'
            . ListPage::toolbar([
                ListPage::button(__('Platinenmaterial erzeugen', 'taxmod-manufacturing'), '', true),
                ListPage::button(__('Abbrechen', 'taxmod-manufacturing'), self::recordUrl($service->structure()->node('board_revision'), $revision)),
            ])
            . '</form>');
    }

    /** CD-5: capability, nonce, validate, act — then the new board opens, or the page says why not. */
    public static function handle(): void
    {
        $revision = absint(wp_unslash((string) ($_POST['revision'] ?? '0')));

        // D-1254: a refusal is said on the page, never as a bare error page.
        if (! current_user_can('manage_options') || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_POST['_wpnonce'] ?? ''))), self::NONCE)) {
            self::back($revision, __('Keine Berechtigung oder die Seite ist abgelaufen, bitte neu laden.', 'taxmod-manufacturing'));
        }

        $service = new ManufacturingService();

        try {
            $board = $service->makeBareBoard($revision);
        } catch (ManufacturingError $error) {
            self::back($revision, (new OrdersPage())->message($error));
        }

        wp_safe_redirect(self::recordUrl($service->structure()->node('bare_board'), $board));
        exit;
    }

    private static function back(int $revision, string $why): never
    {
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'revision' => $revision, 'fehler' => rawurlencode($why)], admin_url('admin.php')));
        exit;
    }

    private static function recordUrl(int $node, int $record): string
    {
        return add_query_arg(['page' => 'taxmod', 'taxmod_node' => (string) $node, 'taxmod_open_record' => (string) $record], admin_url('admin.php'));
    }

    private static function framed(string $body): string
    {
        return ListPage::page(__('Platinenmaterial erzeugen', 'taxmod-manufacturing'), [], [['html' => $body]], TAXMOD_MANUFACTURING_VERSION);
    }
}
