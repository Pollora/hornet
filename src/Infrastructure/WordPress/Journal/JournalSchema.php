<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Journal;

use Pollora\Hornet\Infrastructure\WordPress\Database;

/**
 * The table of the journal, in the database of the site (`{prefix}hornet_journal`: one per site on a network), and the number of its version.
 *
 * It is made with dbDelta, which also brings an older table to the current shape. Nothing else of the site is touched, and nothing is
 * put in the posts or in their meta.
 *
 * @internal
 */
final class JournalSchema
{
    public const string OPTION = 'hornet_journal_schema';

    /** Raised each time the shape of the table changes. */
    public const string VERSION = '1';

    public static function table(): string
    {
        return Database::connection()->prefix.'hornet_journal';
    }

    /**
     * Makes the table if it is missing or older. One option read on a request that is not the first.
     */
    public static function ensure(): void
    {
        if (get_option(self::OPTION) === self::VERSION) {
            return;
        }

        $wpdb = Database::connection();
        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  occurred_at datetime NOT NULL,
  type varchar(40) NOT NULL,
  source varchar(12) NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor varchar(60) NOT NULL DEFAULT '',
  subject varchar(120) NOT NULL DEFAULT '',
  ip varchar(45) NOT NULL DEFAULT '',
  details text NOT NULL,
  PRIMARY KEY  (id),
  KEY occurred_at (occurred_at),
  KEY type (type),
  KEY user_id (user_id),
  KEY subject (subject)
) {$charset};");

        update_option(self::OPTION, self::VERSION);
    }

    /**
     * Removes the table and the option: what `wp hornet journal drop` and an uninstall do.
     */
    public static function drop(): void
    {
        $wpdb = Database::connection();

        $query = $wpdb->prepare('DROP TABLE IF EXISTS %i', self::table());

        if ($query !== null) {
            $wpdb->query($query);
        }

        delete_option(self::OPTION);
    }
}
