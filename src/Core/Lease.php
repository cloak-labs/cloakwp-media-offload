<?php
declare(strict_types=1);

namespace CloakWP\MediaOffload\Core;

/** MySQL/MariaDB release an advisory lock when its owning connection closes. */
final class Lease
{
    private function __construct(private readonly string $name) {}

    public static function acquire(string $operation): ?self
    {
        global $wpdb;
        // Hash the site table as well as the database. Lock names are server-wide.
        $name = hash('sha256', DB_NAME . ':' . $wpdb->options . ':cloakwp-media:' . $operation);
        // GET_LOCK is recursive on a single connection. A nested caller must wait too.
        $locked = $wpdb->get_var($wpdb->prepare(
            'SELECT IF(IS_USED_LOCK(%s) = CONNECTION_ID(), 0, GET_LOCK(%s, 0))',
            $name,
            $name
        ));
        if ((string) $locked === '1') {
            return new self($name);
        }
        if ((string) $locked === '0') {
            return null;
        }
        throw new \RuntimeException('Could not acquire the media offload lock.');
    }

    public function assertHeld(): void
    {
        global $wpdb;
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->name)) !== '1') {
            throw new \RuntimeException('The media offload worker lost its database lock.');
        }
    }

    public function release(): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->name));
    }
}
