<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Plugin;
use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Core\Lease;

/** A resumable scan; individual attachment uploads remain independent cron jobs. */
final class Batch
{
    public const OPTION = '_cloakwp_media_offload_batch';
    private const EVENT = 'cloakwp_media_offload_batch';
    public function __construct(private readonly Offloader $offloader, private readonly Integration $integration) {}
    public function register(): void { add_action(self::EVENT, [$this, 'run']); }
    public function start(): void
    {
        if (!$this->offloader->writesAllowed()) { throw new \RuntimeException('Media offload writes are disabled for this site.'); }
        $lease = Lease::acquire('batch');
        if (!$lease) { throw new \RuntimeException('A scan is already being processed.'); }
        try { $this->startScan(); }
        finally { $lease->release(); }
    }
    private function startScan(): void
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
        if (!$this->offloader->siteAllowed()) { throw new \RuntimeException('Media offloading is not enabled for this site.'); }
        $lease = Lease::acquire('batch');
        if (!$lease) { throw new \RuntimeException('The scan is processing a batch. Retry cancellation shortly.'); }
        try {
            $state = get_option(self::OPTION);
            if (is_array($state)) { $state['status'] = 'cancelled'; update_option(self::OPTION, $state, false); }
            wp_clear_scheduled_hook(self::EVENT);
        } finally { $lease->release(); }
    }
    public function run(): void
    {
        if (!$this->offloader->writesAllowed()) { return; }
        $lease = Lease::acquire('batch');
        if (!$lease) { return; }
        try { $this->scan($lease); }
        finally { $lease->release(); }
    }

    public function recover(): void
    {
        if ($this->offloader->writesAllowed() && !wp_next_scheduled(self::EVENT)) {
            $this->run();
        }
    }
    private function scan(Lease $lease): void
    {
        $state = get_option(self::OPTION);
        if (!is_array($state) || ($state['status'] ?? '') !== 'running') { return; }
        try {
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT 50", (int) $state['after']));
            if ($wpdb->last_error) { throw new \RuntimeException('Could not list attachments for the scan.'); }
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
            $lease->assertHeld();
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
        }
    }
}
