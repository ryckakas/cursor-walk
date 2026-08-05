<?php

declare(strict_types=1);

/*
 * Fails the build when line coverage drops below MINIMUM_PERCENT.
 *
 * PHPUnit can report coverage but cannot enforce a floor, so the number in CI
 * was previously printed and then ignored — free to rot. This reads the Clover
 * report PHPUnit just wrote and exits non-zero if the ratio fell.
 *
 * Usage: php tools/check-coverage.php <path-to-clover.xml> [minimum-percent]
 */

const MINIMUM_PERCENT = 100.0;

$cloverPath = $argv[1] ?? null;
$minimum = isset($argv[2]) ? (float) $argv[2] : MINIMUM_PERCENT;

if ($cloverPath === null || !is_file($cloverPath)) {
    fwrite(STDERR, sprintf(
        "coverage gate: no Clover report at %s.\n"
        . "Run the tests with --coverage-clover first, and make sure a coverage driver is loaded.\n",
        $cloverPath ?? '<no path given>',
    ));

    exit(1);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, sprintf("coverage gate: %s is not readable XML.\n", $cloverPath));

    exit(1);
}

$metrics = $xml->xpath('/coverage/project/metrics');
$metric = $metrics === null || $metrics === [] ? null : reset($metrics);

if (!$metric instanceof SimpleXMLElement) {
    fwrite(STDERR, "coverage gate: no /coverage/project/metrics element in the Clover report.\n");

    exit(1);
}

// Attributes come back as SimpleXMLElement; (string) is how you read the value.
$statements = (int) (string) ($metric['statements'] ?? '0');
$covered = (int) (string) ($metric['coveredstatements'] ?? '0');

if ($statements === 0) {
    fwrite(STDERR, "coverage gate: the report counts zero statements, which means it measured nothing.\n");

    exit(1);
}

$percent = $covered / $statements * 100;

// Compare on the rounded figure that gets reported, so a build cannot fail on a
// difference the message does not show.
if (round($percent, 2) + 0.0001 < $minimum) {
    fwrite(STDERR, sprintf(
        "coverage gate FAILED: %.2f%% of lines covered (%d/%d), floor is %.2f%%.\n"
        . "Cover the new lines, or mark a genuinely unreachable branch with @codeCoverageIgnore and say why.\n",
        $percent,
        $covered,
        $statements,
        $minimum,
    ));

    exit(1);
}

printf("coverage gate passed: %.2f%% of lines covered (%d/%d), floor is %.2f%%.\n", $percent, $covered, $statements, $minimum);
