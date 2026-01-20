<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Cli.php';

$exitCode = \PHPReviewAggregator\Cli::run($argv);
exit($exitCode);
