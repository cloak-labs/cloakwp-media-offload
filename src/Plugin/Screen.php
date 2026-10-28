<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Plugin;
use CloakWP\MediaOffload\Core\Offloader;

final class Screen
{
    public function __construct(private readonly Offloader $offloader, private readonly Integration $integration) {}
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_cloakwp_media_offload_batch', [$this, 'batch']);
    }
    public function menu(): void { add_media_page('Offload Media', 'Offload Media', 'manage_options', 'media-offload', [$this, 'render']); }
    public function batch(): void
    {
        if (!current_user_can('manage_options')) { wp_die('You cannot manage media offloading.'); }
        check_admin_referer('cloakwp_media_offload_batch');
        $batch = new Batch($this->offloader, $this->integration);
        try {
            if (($_POST['operation'] ?? '') === 'cancel') { $batch->cancel(); }
            elseif (($_POST['operation'] ?? '') === 'start') { $batch->start(); }
            else { wp_die('Invalid operation.', '', ['response' => 400]); }
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()), '', ['response' => 400]); }
        wp_safe_redirect(add_query_arg('page', 'media-offload', admin_url('upload.php')));
        exit;
    }
    public function render(): void
    {
        if (!current_user_can('manage_options')) { return; }
        $state = get_option(Batch::OPTION);
        $running = is_array($state) && ($state['status'] ?? '') === 'running';
        echo '<div class="wrap"><h1>Offload Media</h1><p>Scan attachments in the background. Existing local files follow the configured retention policy. WordPress cron must run for processing to continue.</p>';
        if (is_array($state)) {
            echo '<h2>Scan status</h2><p>' . esc_html(ucfirst((string) ($state['status'] ?? 'unknown'))) . '</p>';
            echo '<p>Scanned: ' . esc_html((string) ($state['scanned'] ?? 0)) . ' · Queued: ' . esc_html((string) ($state['queued'] ?? 0)) . ' · Already offloaded: ' . esc_html((string) ($state['already_current'] ?? 0)) . ' · Excluded: ' . esc_html((string) ($state['excluded'] ?? 0)) . '</p>';
            echo '<p>Queued attachments process separately; a finished scan does not mean every upload has finished. Cancelling the scan does not cancel already queued uploads.</p>';
            if (!empty($state['error'])) { echo '<p>' . esc_html((string) $state['error']) . '</p>'; }
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('cloakwp_media_offload_batch');
        echo '<input type="hidden" name="action" value="cloakwp_media_offload_batch"><input type="hidden" name="operation" value="' . ($running ? 'cancel' : 'start') . '">';
        $resume = is_array($state) && in_array($state['status'] ?? '', ['failed', 'cancelled'], true);
        submit_button($running ? 'Cancel scan' : ($resume ? 'Resume scan' : 'Start scan'));
        echo '</form>';
        $failures = get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 20, 'meta_key' => Offloader::ERROR, 'orderby' => 'ID', 'order' => 'DESC']);
        if ($failures) {
            echo '<h2>Recent failures</h2><ul>';
            foreach ($failures as $failure) { echo '<li>' . esc_html((string) $failure->ID) . ': ' . esc_html((string) get_post_meta($failure->ID, Offloader::ERROR, true)) . '</li>'; }
            echo '</ul>';
        }
        echo '</div>';
    }
}
