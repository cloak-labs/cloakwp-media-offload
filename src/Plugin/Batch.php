<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Plugin;
use CloakWP\MediaOffload\Core\Offloader;

/** A resumable scan; individual attachment uploads remain independent cron jobs. */
final class Batch
{
    public const OPTION = '_cloakwp_media_offload_batch';
    private const EVENT = 'cloakwp_media_offload_batch';
    private const LOCK = '_cloakwp_media_offload_batch_lock';
    public function __construct(private readonly Offloader $offloader, private readonly Integration $integration) {}
    public function register(): void { add_action(self::EVENT, [$this, 'run']); }
    public function start(): void
    {
        $state = get_option(self::OPTION);
        if (is_array($state) && ($state['status'] ?? '') === 'running') { throw new \RuntimeException('A scan is already running.'); }
        if (is_array($state) && in_array($state['status'] ?? '', ['failed', 'cancelled'], true)) {
            $state['id'] = wp_generate_uuid4(); $state['status'] = 'running'; unset($state['error']);
        } else {
            $state = ['id' => wp_generate_uuid4(), 'status' => 'running', 'after' => 0, 'scanned' => 0, 'queued' => 0, 'excluded' => 0, 'already_current' => 0];
        }
        update_option(self::OPTION, $state, false);
        if (!wp_schedule_single_event(time() + 5, self::EVENT)) {
            $state['status'] = 'failed'; $state['error'] = 'Could not schedule the scan.'; update_option(self::OPTION, $state, false);
            throw new \RuntimeException($state['error']);
        }
    }
    public function cancel(): void
    {
        $state = get_option(self::OPTION);
        if (is_array($state)) { $state['status'] = 'cancelled'; update_option(self::OPTION, $state, false); }
        wp_clear_scheduled_hook(self::EVENT);
    }
    public function run(): void
    {
        $state = get_option(self::OPTION);
        if (!is_array($state) || ($state['status'] ?? '') !== 'running') { return; }
        $token = wp_generate_uuid4();
        if (!add_option(self::LOCK, $token, '', false)) { return; }
        try {
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT 50", (int) $state['after']));
            foreach ($ids as $id) {
                $latest = get_option(self::OPTION);
                if (!is_array($latest) || ($latest['status'] ?? '') !== 'running' || ($latest['id'] ?? '') !== $state['id']) { return; }
                $id = (int) $id;
                if (!$this->offloader->eligible($id)) { ++$state['excluded']; }
                elseif ($this->offloader->current($id)) { ++$state['already_current']; }
                elseif ($this->integration->schedule($id)) { ++$state['queued']; }
                else { throw new \RuntimeException('Could not schedule attachment ' . $id . '.'); }
                $state['after'] = $id;
                ++$state['scanned'];
            }
            $latest = get_option(self::OPTION);
            if (!is_array($latest) || ($latest['status'] ?? '') !== 'running' || ($latest['id'] ?? '') !== $state['id']) { return; }
            if (count($ids) < 50) { $state['status'] = 'finished'; }
            update_option(self::OPTION, $state, false);
            if ($state['status'] === 'running' && !wp_schedule_single_event(time() + 10, self::EVENT)) {
                throw new \RuntimeException('Could not schedule the next scan batch.');
            }
        } catch (\Throwable $e) {
            $latest = get_option(self::OPTION);
            if (is_array($latest) && ($latest['id'] ?? '') === $state['id'] && ($latest['status'] ?? '') === 'running') {
                $state['status'] = 'failed'; $state['error'] = $e->getMessage(); update_option(self::OPTION, $state, false);
            }
        } finally { if (get_option(self::LOCK) === $token) { delete_option(self::LOCK); } }
    }
}
