<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Exceptions\UnknownCheckException;
use Throwable;

/**
 * Runs the checks against the site and sets aside, on written reasons, the findings the owner chose to live with.
 *
 * It is the one engine: the dashboard, WP-CLI, Site Health and `pollora:doctor` all read its report.
 */
final readonly class Auditor
{
    /** @var array<string, Check> */
    private array $checks;

    /**
     * @param  iterable<Check>  $checks
     * @param  list<string>  $disabled  ids of the checks that do not run
     * @param  array<string, string>  $ignored  id of a check => why its finding is set aside
     *
     * @throws UnknownCheckException when a setting names a check that does not exist
     */
    public function __construct(iterable $checks, array $disabled = [], private array $ignored = [])
    {
        $byId = [];

        foreach ($checks as $check) {
            $byId[$check->id()] = $check;
        }

        foreach ([...$disabled, ...array_keys($ignored)] as $id) {
            if (! isset($byId[$id])) {
                throw UnknownCheckException::named($id, array_keys($byId));
            }
        }

        $this->checks = array_diff_key($byId, array_flip($disabled));
    }

    /**
     * @return list<Check>
     */
    public function checks(): array
    {
        return array_values($this->checks);
    }

    public function run(Environment $environment): Report
    {
        $findings = [];

        foreach ($this->checks as $id => $check) {
            $finding = $this->runOne($check, $environment);
            $findings[] = isset($this->ignored[$id]) ? $finding->ignoredFor($this->ignored[$id]) : $finding;
        }

        return new Report($findings);
    }

    /**
     * A check that fails to run is reported, never skipped silently: an audit that hides a crash would look cleaner than the site is.
     */
    private function runOne(Check $check, Environment $environment): Finding
    {
        try {
            return $check->run($environment);
        } catch (Throwable $throwable) {
            return Finding::unknown($check->id(), sprintf('The check could not run: %s', $throwable::class));
        }
    }
}
