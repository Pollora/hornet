<?php

declare(strict_types=1);

/*
 * The part of pollora/framework's doctor that Hornet implements, reduced to its contract.
 *
 * The package does not depend on the framework (ARCH-06): PHPStan reads this file, and the unit tests load it,
 * so that the bridge can be written and tested without installing the framework. The real classes are in
 * Pollora\Doctor of pollora/framework (src/Doctor/Domain): keep the signatures the same.
 */

namespace Pollora\Doctor\Domain\Enums {
    enum RunContext: string
    {
        case Console = 'console';
        case Http = 'http';
    }

    enum CheckStatus: string
    {
        case Ok = 'ok';
        case Warning = 'warning';
        case Error = 'error';
        case Skipped = 'skipped';
    }
}

namespace Pollora\Doctor\Domain\Models {
    use Pollora\Doctor\Domain\Enums\CheckStatus;

    final readonly class CheckResult
    {
        /**
         * @param  list<string>  $details
         */
        private function __construct(
            public CheckStatus $status,
            public string $summary,
            public array $details = [],
            public ?string $fix = null,
        ) {}

        /**
         * @param  list<string>  $details
         */
        public static function ok(string $summary, array $details = []): self
        {
            return new self(CheckStatus::Ok, $summary, $details);
        }

        /**
         * @param  list<string>  $details
         */
        public static function warning(string $summary, array $details = [], ?string $fix = null): self
        {
            return new self(CheckStatus::Warning, $summary, $details, $fix);
        }

        /**
         * @param  list<string>  $details
         */
        public static function error(string $summary, array $details = [], ?string $fix = null): self
        {
            return new self(CheckStatus::Error, $summary, $details, $fix);
        }

        public static function skipped(string $reason): self
        {
            return new self(CheckStatus::Skipped, $reason);
        }
    }
}

namespace Pollora\Doctor\Domain\Contracts {
    use Pollora\Doctor\Domain\Enums\RunContext;
    use Pollora\Doctor\Domain\Models\CheckResult;

    interface CheckInterface
    {
        public function id(): string;

        public function label(): string;

        /**
         * @return list<RunContext>
         */
        public function runsIn(): array;

        public function run(RunContext $context): CheckResult;
    }
}
