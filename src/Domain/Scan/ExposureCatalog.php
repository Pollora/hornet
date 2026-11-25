<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Audit\Severity;

/**
 * What a WordPress site leaves reachable by mistake, most often: environment files, a repository, backups of the configuration
 * and of the database, a debug log, a listing of the uploads. Each is a fixed address on the site itself.
 */
final class ExposureCatalog
{
    /**
     * @return list<Exposure>
     */
    public static function all(): array
    {
        $env = '/^\s*(?:export\s+)?[A-Z][A-Z0-9_]*\s*=/mD';
        $config = '/DB_PASSWORD|DB_NAME|AUTH_KEY|table_prefix/';
        $sql = '/CREATE TABLE|INSERT INTO|DROP TABLE/i';
        $listing = '/<title>Index of|<h1>Index of/i';
        $log = '/^\[\d{1,2}-[A-Za-z]{3}-\d{4}[^\]]*\]\s+PHP /mD';

        $exposures = [];

        foreach (['.env', '.env.local', '.env.production', '.env.backup'] as $file) {
            $exposures[] = new Exposure(Exposure::SITE, $file, 'environment variables (passwords, keys)', Severity::High, $env);
        }

        $exposures[] = new Exposure(Exposure::SITE, '.git/HEAD', 'the Git repository (the source of the site)', Severity::High, '/^ref:\s*refs\//D');
        $exposures[] = new Exposure(Exposure::SITE, '.git/config', 'the Git repository (the source of the site)', Severity::High, '/\[core\]/');

        foreach (['wp-config.php.bak', 'wp-config.php~', 'wp-config.php.old', 'wp-config.php.save', 'wp-config.php.orig', 'wp-config.bak', 'wp-config.txt'] as $file) {
            $exposures[] = new Exposure(Exposure::SITE, $file, 'a copy of wp-config.php (database password, keys)', Severity::High, $config);
        }

        foreach (['backup.sql', 'dump.sql', 'db.sql', 'database.sql', 'site.sql'] as $file) {
            $exposures[] = new Exposure(Exposure::SITE, $file, 'a copy of the database', Severity::High, $sql);
        }

        foreach (['backup.zip', 'site.zip', 'wordpress.zip'] as $file) {
            $exposures[] = new Exposure(Exposure::SITE, $file, 'an archive of the site', Severity::High, '/^PK\x03\x04/D');
        }

        $exposures[] = new Exposure(Exposure::SITE, '.htpasswd', 'the password file of the web server', Severity::Medium, '/^[^:\s]+:(?:\$apr1\$|\$2[aby]\$|\{SHA\}|[A-Za-z0-9.\/]{13}\s*$)/mD');
        $exposures[] = new Exposure(Exposure::SITE, 'phpinfo.php', 'phpinfo() (the PHP and server configuration)', Severity::Medium, '/PHP Version|phpinfo\(\)/');
        $exposures[] = new Exposure(Exposure::SITE, 'info.php', 'phpinfo() (the PHP and server configuration)', Severity::Medium, '/PHP Version|phpinfo\(\)/');
        $exposures[] = new Exposure(Exposure::SITE, 'error_log', 'a PHP error log (paths, queries)', Severity::Medium, $log);
        $exposures[] = new Exposure(Exposure::SITE, 'composer.json', 'composer.json (the list of what the site runs)', Severity::Low, '/"require"\s*:/');

        $exposures[] = new Exposure(Exposure::CONTENT, 'debug.log', 'the WordPress debug log (paths, queries, sometimes data)', Severity::Medium, $log);
        $exposures[] = new Exposure(Exposure::CONTENT, 'uploads/', 'a listing of the uploads folder', Severity::Low, $listing);
        $exposures[] = new Exposure(Exposure::CONTENT, 'plugins/', 'a listing of the plugins folder', Severity::Low, $listing);

        return $exposures;
    }
}
