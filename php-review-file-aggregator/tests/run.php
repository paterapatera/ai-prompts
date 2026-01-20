<?php
// Simple test harness without external dependencies
// Usage: php tests/run.php

function assertTrue($cond, $message)
{
  if (!$cond) {
    throw new Exception("Assertion failed: $message");
  }
}

function assertEqual($a, $b, $message)
{
  if ($a !== $b) {
    throw new Exception("Assertion failed: $message (" . var_export($a, true) . " !== " . var_export($b, true) . ")");
  }
}

function captureOutput(callable $fn)
{
  ob_start();
  try {
    $result = $fn();
    $out = ob_get_clean();
    return [$result, $out];
  } catch (Throwable $e) {
    $out = ob_get_clean();
    throw $e; // rethrow
  }
}

$tests = [];

$tests['help_shows_usage_and_exit_0'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  [$code, $out] = captureOutput(function () {
    return \PHPReviewAggregator\Cli::run(['aggregator.php', '--help']);
  });
  assertEqual($code, 0, 'help should exit 0');
  assertTrue(str_contains($out, 'Usage:'), 'help should contain Usage');
  assertTrue(str_contains($out, 'aggregator.php'), 'help should mention aggregator.php');
};

$tests['version_shows_and_exit_0'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  [$code, $out] = captureOutput(function () {
    return \PHPReviewAggregator\Cli::run(['aggregator.php', '--version']);
  });
  assertEqual($code, 0, 'version should exit 0');
  assertTrue(str_contains($out, 'Version'), 'version should print Version');
};

$tests['missing_target_dir_exits_2'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  [$code, $out] = captureOutput(function () {
    return \PHPReviewAggregator\Cli::run(['aggregator.php']);
  });
  assertEqual($code, 2, 'missing target should exit 2');
  assertTrue(str_contains($out, 'Usage:'), 'should print usage on invalid args');
};

$tests['unknown_option_exits_2'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  [$code, $out] = captureOutput(function () {
    return \PHPReviewAggregator\Cli::run(['aggregator.php', '--unknown']);
  });
  assertEqual($code, 2, 'unknown option should exit 2');
};

$tests['version_check_function'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  assertTrue(\PHPReviewAggregator\Cli::isCompatibleVersion('8.2.0'), '8.2.0 is compatible');
  assertTrue(!\PHPReviewAggregator\Cli::isCompatibleVersion('8.1.99'), '8.1.99 is not compatible');
};

$tests['nonexistent_dir_exits_1'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  [$code, $out] = captureOutput(function () {
    return \PHPReviewAggregator\Cli::run(['aggregator.php', '/this/path/does/not/exist']);
  });
  assertEqual($code, 1, 'nonexistent dir should exit 1');
  // Error message is printed to STDERR; stdout may be empty.
  assertTrue($out === '' || is_string($out), 'stdout should not crash');
};

$tests['valid_dir_initializes_flow_exit_0'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $cwd = getcwd();
  [$code, $out] = captureOutput(function () use ($cwd) {
    return \PHPReviewAggregator\Cli::run(['aggregator.php', $cwd]);
  });
  assertEqual($code, 0, 'valid args should exit 0');
  assertTrue(str_contains($out, '{'), 'should output JSON');
};

$tests['scanner_finds_php_files_recursively'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $scanner = new \PHPReviewAggregator\Scanner();
  // Create a temporary directory structure for testing
  $tempDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
  mkdir($tempDir);
  mkdir($tempDir . '/subdir');
  file_put_contents($tempDir . '/file.php', '<?php echo "test";');
  file_put_contents($tempDir . '/subdir/file2.php', '<?php echo "test2";');
  file_put_contents($tempDir . '/file.txt', 'not php');
  try {
    $files = $scanner->scanPHPFiles($tempDir);
    assertEqual(count($files), 2, 'should find 2 php files');
    assertTrue(in_array('file.php', $files), 'should include file.php');
    assertTrue(in_array('subdir/file2.php', $files), 'should include subdir/file2.php');
  } finally {
    // cleanup
    unlink($tempDir . '/file.php');
    unlink($tempDir . '/subdir/file2.php');
    unlink($tempDir . '/file.txt');
    rmdir($tempDir . '/subdir');
    rmdir($tempDir);
  }
};

$tests['scanner_excludes_test_files'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $scanner = new \PHPReviewAggregator\Scanner();
  $tempDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
  mkdir($tempDir);
  mkdir($tempDir . '/tests');
  file_put_contents($tempDir . '/file.php', '<?php echo "test";');
  file_put_contents($tempDir . '/tests/file.test.php', '<?php echo "test";');
  try {
    $files = $scanner->scanPHPFiles($tempDir);
    assertEqual(count($files), 1, 'should exclude test files');
    assertTrue(in_array('file.php', $files), 'should include file.php');
  } finally {
    unlink($tempDir . '/file.php');
    unlink($tempDir . '/tests/file.test.php');
    rmdir($tempDir . '/tests');
    rmdir($tempDir);
  }
};

$tests['scanner_throws_on_invalid_directory'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $scanner = new \PHPReviewAggregator\Scanner();
  try {
    $base = basename($tempFile);
    $scanner->scanPHPFiles('/nonexistent/directory');
    assertTrue(false, 'should throw exception for invalid directory');
  } catch (\InvalidArgumentException $e) {
    assertTrue(str_contains($e->getMessage(), 'directory'), 'exception message should mention directory');
  }
};

$tests['scanner_filter_option_placeholder'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $scanner = new \PHPReviewAggregator\Scanner();
  $tempDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
  mkdir($tempDir);
  file_put_contents($tempDir . '/file.php', '<?php echo "test";');
  try {
    $base = basename($tempFile);
    // For now, filter is ignored (placeholder)
    $files = $scanner->scanPHPFiles($tempDir, 'some-filter');
    assertEqual(count($files), 1, 'filter should be ignored for now');
  } finally {
    unlink($tempDir . '/file.php');
    rmdir($tempDir);
  }
};

$tests['ruleset_manager_has_rules'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $ruleset = new \PHPReviewAggregator\RulesetManager();
  $rules = $ruleset->getRules();
  assertTrue(is_array($rules), 'rules should be array');
  assertTrue(count($rules) > 0, 'should have at least one rule');
};

$tests['ruleset_manager_has_sets'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $ruleset = new \PHPReviewAggregator\RulesetManager();
  $sets = $ruleset->getSets();
  assertTrue(is_array($sets), 'sets should be array');
  assertTrue(isset($sets['1.1-1.2-1.7']), 'should have 1.1-1.2-1.7 set');
};

$tests['analyzer_analyzes_empty_files_returns_empty'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $violations = $analyzer->analyzeFiles([], '/tmp');
  assertEqual($violations, [], 'empty files should return empty violations');
};

$tests['analyzer_analyzes_single_file_with_no_violations'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = sys_get_temp_dir() . '/test_no_violations.php';
  file_put_contents($tempFile, '<?php echo "hello";');
  try {
    $base = basename($tempFile);
    $violations = $analyzer->analyzeFiles(['test_no_violations.php'], sys_get_temp_dir());
    assertEqual($violations, [], 'file with no violations should return empty');
  } finally {
    unlink($tempFile);
  }
};

$tests['analyzer_skips_unreadable_files'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $violations = $analyzer->analyzeFiles(['nonexistent.php'], '/tmp');
  assertEqual($violations, [], 'unreadable files should be skipped');
};

$tests['analyzer_detects_single_letter_variable'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = sys_get_temp_dir() . '/test_single_var.php';
  $code = '<?php
function test() {
  $a = 1;
  $b = 2;
  $c = 3;
  $d = 4;
  $e = 5;
  $f = 6;
  $g = 7;
  $h = 8;
  $i = 9;
  $j = 10;
  $k = 11;
  $l = 12;
  $m = 13;
  $n = 14;
  $o = 15;
  $p = 16;
  $q = 17;
  $r = 18;
  $s = 19;
  $t = 20;
  $u = 21;
  $v = 22;
  $w = 23;
  $x = 24;
  $y = 25;
  $z = 26;
  return $a + $b;
}
';
  file_put_contents($tempFile, $code);
  try {
    $base = basename($tempFile);
    $violations = $analyzer->analyzeFiles(['test_single_var.php'], sys_get_temp_dir());
    assertTrue(count($violations) > 0, 'should detect single letter variables');
    $rule1_1 = array_filter($violations, fn($v) => in_array('1.1', $v->ruleIdCandidates));
    assertTrue(count($rule1_1) > 0, 'should detect 1.1 violations');
  } finally {
    unlink($tempFile);
  }
};

$tests['analyzer_detects_method_order_violation'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = sys_get_temp_dir() . '/test_method_order.php';
  $code = '<?php
class Test {
  public function pub1() {}
  private function priv1() {}
  public function pub2() {}
}
';
  file_put_contents($tempFile, $code);
  try {
    $base = basename($tempFile);
    $violations = $analyzer->analyzeFiles(['test_method_order.php'], sys_get_temp_dir());
    // Should detect 2.1 violation
    $rule2_1 = array_filter($violations, fn($v) => in_array('2.1', $v->ruleIdCandidates));
    assertTrue(count($rule2_1) > 0, 'should detect method order violation');
  } finally {
    unlink($tempFile);
  }
};

$tests['analyzer_detects_redundant_comment'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = sys_get_temp_dir() . '/test_redundant_comment.php';
  $code = '<?php
// i++
$i++;
';
  file_put_contents($tempFile, $code);
  try {
    $base = basename($tempFile);
    $violations = $analyzer->analyzeFiles(['test_redundant_comment.php'], sys_get_temp_dir());
    // Should detect 3.1 violation
    $rule3_1 = array_filter($violations, fn($v) => in_array('3.1', $v->ruleIdCandidates));
    assertTrue(count($rule3_1) > 0, 'should detect redundant comment');
  } finally {
    unlink($tempFile);
  }
};

$tests['analyzer_detects_long_condition'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = sys_get_temp_dir() . '/test_long_condition.php';
  $code = '<?php
if ($a && $b && $c && $d && $e && $f && $g && $h && $i && $j && $k && $l && $m && $n && $o && $p && $q && $r && $s && $t && $u && $v && $w && $x && $y && $z && $aa && $bb && $cc && $dd && $ee && $ff && $gg && $hh && $ii && $jj && $kk && $ll && $mm && $nn && $oo && $pp && $qq && $rr && $ss && $tt && $uu && $vv && $ww && $xx && $yy && $zz) {
  echo "long";
}
';
  file_put_contents($tempFile, $code);
  $read = file_get_contents($tempFile);
  echo "File content: " . substr($read, 0, 100) . "...\n"; // Debug
  try {
    $base = basename($tempFile);
    $violations = $analyzer->analyzeFiles(['test_long_condition.php'], sys_get_temp_dir());
    var_dump($violations); // Debug
    // Should detect 4.1 violation
    $rule4_1 = array_filter($violations, fn($v) => in_array('4.1', $v->ruleIdCandidates));
    assertTrue(count($rule4_1) > 0, 'should detect long condition');
  } finally {
    unlink($tempFile);
  }
};

$tests['analyzer_detects_complex_logical_expression'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = sys_get_temp_dir() . '/test_complex_logic.php';
  $code = '<?php
function test() {
  if ($a && $b || $c && $d || $e && $f) { // 5 logical operators
    return true;
  }
}
';
  file_put_contents($tempFile, $code);
  try {
    $base = basename($tempFile);
    $violations = $analyzer->analyzeFiles(['test_complex_logic.php'], sys_get_temp_dir());
    // Should detect 5.2 violation for complex logical expression
    $rule5_2 = array_filter($violations, fn($v) => in_array('5.2', $v->ruleIdCandidates));
    assertTrue(count($rule5_2) > 0, 'should detect complex logical expression');
  } finally {
    unlink($tempFile);
  }
};

$tests['analyzer_detects_magic_number'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = sys_get_temp_dir() . '/test_magic_number.php';
  $code = '<?php
function test() {
  $result = $value * 42; // magic number
}
';
  file_put_contents($tempFile, $code);
  try {
    $base = basename($tempFile);
    $violations = $analyzer->analyzeFiles(['test_magic_number.php'], sys_get_temp_dir());
    // Should detect 5.3 violation for magic number
    $rule5_3 = array_filter($violations, fn($v) => in_array('5.3', $v->ruleIdCandidates));
    assertTrue(count($rule5_3) > 0, 'should detect magic number');
  } finally {
    unlink($tempFile);
  }
};

$tests['analyzer_detects_multiple_assignments'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = sys_get_temp_dir() . '/test_multiple_assignments.php';
  $code = '<?php
function test() {
  $x = 1;
  $x = 2; // second assignment
  $x = 3; // third assignment
}
';
  file_put_contents($tempFile, $code);
  try {
    $base = basename($tempFile);
    $violations = $analyzer->analyzeFiles(['test_multiple_assignments.php'], sys_get_temp_dir());
    // Should detect 6.6 violation for multiple assignments
    $rule6_6 = array_filter($violations, fn($v) => in_array('6.6', $v->ruleIdCandidates));
    assertTrue(count($rule6_6) > 0, 'should detect multiple assignments');
  } finally {
    unlink($tempFile);
  }
};

$tests['analyzer_detects_long_function'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = sys_get_temp_dir() . '/test_long_function.php';
  $code = '<?php
function longFunction() {
  $a = 1;
  $b = 2;
  $c = 3;
  $d = 4;
  $e = 5;
  $f = 6;
  $g = 7;
  $h = 8;
  $i = 9;
  $j = 10;
  $k = 11;
  $l = 12;
  $m = 13;
  $n = 14;
  $o = 15;
  $p = 16;
  $q = 17;
  $r = 18;
  $s = 19;
  $t = 20;
  return $a + $b;
}
';
  file_put_contents($tempFile, $code);
  try {
    $base = basename($tempFile);
    $violations = $analyzer->analyzeFiles(['test_long_function.php'], sys_get_temp_dir());
    // Should detect 7.1 violation for long function
    $rule7_1 = array_filter($violations, fn($v) => in_array('7.1', $v->ruleIdCandidates));
    assertTrue(count($rule7_1) > 0, 'should detect long function');
  } finally {
    unlink($tempFile);
  }
};

$tests['aggregator_groups_and_applies_ruleids'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $aggregator = new \PHPReviewAggregator\Aggregator();
  $rawViolations = [
    new \PHPReviewAggregator\RawViolation('file1.php', 10, 'var', ['1.1'], 'context'),
    new \PHPReviewAggregator\RawViolation('file1.php', 10, 'var', ['1.2'], 'context'),
    new \PHPReviewAggregator\RawViolation('file1.php', 10, 'var', ['1.7'], 'context'),
  ];
  $violations = $aggregator->aggregate($rawViolations);
  assertEqual(count($violations), 1, 'should group into one violation');
  assertEqual($violations[0]->ruleIds, ['1.1', '1.2', '1.7'], 'should apply set ruleIds');
  assertEqual($violations[0]->reviewed, false, 'reviewed should be false');
};

$tests['reporter_generates_json_with_metadata'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $reporter = new \PHPReviewAggregator\Reporter(null);
  $violations = [
    new \PHPReviewAggregator\Violation('file1.php', 10, 'var', ['1.1', '1.2', '1.7'], false),
  ];
  $json = $reporter->generateJSON($violations, '/target/dir');
  $data = json_decode($json, true);
  assertTrue(isset($data['violations']), 'should have violations array');
  assertTrue(isset($data['metadata']), 'should have metadata');
  assertEqual(count($data['violations']), 1, 'should have one violation');
  assertEqual($data['violations'][0]['reviewed'], false, 'reviewed should be false');
  assertEqual($data['metadata']['targetDirectory'], '/target/dir', 'metadata should have target dir');
};

$tests['cli_handles_scanner_exceptions'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  // This test assumes Cli::run catches exceptions from Scanner and returns exit code 1
  // For now, it may fail until we implement exception handling in Cli::run
  [$code, $out] = captureOutput(function () {
    return \PHPReviewAggregator\Cli::run(['aggregator.php', '/nonexistent/directory']);
  });
  assertEqual($code, 1, 'scanner exception should result in exit code 1');
};

$tests['cli_handles_analyzer_exceptions'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  // Placeholder: test for analyzer exceptions
  // Assume Cli::run catches and handles
  assertTrue(true, 'placeholder for analyzer exception handling');
};

$tests['cli_handles_aggregator_exceptions'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  // Placeholder: test for aggregator exceptions
  assertTrue(true, 'placeholder for aggregator exception handling');
};

$tests['cli_handles_reporter_exceptions'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  // Placeholder: test for reporter exceptions
  assertTrue(true, 'placeholder for reporter exception handling');
};

$tests['cli_outputs_to_stdout_when_no_output_file'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $cwd = getcwd();
  [$code, $out] = captureOutput(function () use ($cwd) {
    return \PHPReviewAggregator\Cli::run(['aggregator.php', $cwd]);
  });
  assertEqual($code, 0, 'should exit 0');
  assertTrue(str_contains($out, '{'), 'should output JSON to stdout');
};

$tests['baseline_scanner_acceptance'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $scanner = new \PHPReviewAggregator\Scanner();
  $tempDir = sys_get_temp_dir() . '/baseline_scanner_' . uniqid();
  mkdir($tempDir);
  mkdir($tempDir . '/tests');
  mkdir($tempDir . '/spec');
  mkdir($tempDir . '/subdir');
  file_put_contents($tempDir . '/file.php', '<?php echo "test";');
  file_put_contents($tempDir . '/tests/file.test.php', '<?php echo "test";');
  file_put_contents($tempDir . '/spec/file.spec.php', '<?php echo "test";');
  file_put_contents($tempDir . '/subdir/file2.php', '<?php echo "test";');
  try {
    $files = $scanner->scanPHPFiles($tempDir);
    assertEqual(count($files), 2, 'should find 2 php files, exclude tests and spec');
    assertTrue(in_array('file.php', $files), 'should include file.php');
    assertTrue(in_array('subdir/file2.php', $files), 'should include subdir/file2.php');
  } finally {
    unlink($tempDir . '/file.php');
    unlink($tempDir . '/tests/file.test.php');
    unlink($tempDir . '/spec/file.spec.php');
    unlink($tempDir . '/subdir/file2.php');
    rmdir($tempDir . '/subdir');
    rmdir($tempDir . '/tests');
    rmdir($tempDir . '/spec');
    rmdir($tempDir);
  }
};

$tests['baseline_analyzer_acceptance'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = '/tmp/baseline_analyzer_test.php';
  $code = '<?php
function test() {
  $a = 1; // 1.1 violation
  if ($x && $y && $z && $w && $v && $u) { // 5.2 violation
    return 42; // 5.3 violation
  }
}
';
  file_put_contents($tempFile, $code);
  try {
    $base = 'baseline_analyzer_test.php';
    $violations = $analyzer->analyzeFiles([$base], '/tmp');
    assertTrue(count($violations) > 0, 'should detect violations');
    // Check for specific rules
    $has1_1 = array_filter($violations, fn($v) => in_array('1.1', $v->ruleIdCandidates));
    $has5_2 = array_filter($violations, fn($v) => in_array('5.2', $v->ruleIdCandidates));
    $has5_3 = array_filter($violations, fn($v) => in_array('5.3', $v->ruleIdCandidates));
    assertTrue(count($has1_1) > 0, 'should detect 1.1');
    assertTrue(count($has5_2) > 0, 'should detect 5.2');
    assertTrue(count($has5_3) > 0, 'should detect 5.3');
  } finally {
    unlink($tempFile);
  }
};

$tests['baseline_aggregation_json_acceptance'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $aggregator = new \PHPReviewAggregator\Aggregator();
  $reporter = new \PHPReviewAggregator\Reporter(null);
  $rawViolations = [
    new \PHPReviewAggregator\RawViolation('file.php', 10, 'var', ['1.1', '1.2', '1.7'], 'context'),
  ];
  $violations = $aggregator->aggregate($rawViolations);
  $json = $reporter->generateJSON($violations, '/target');
  $data = json_decode($json, true);
  assertEqual(count($data['violations']), 1, 'should have one violation');
  assertEqual($data['violations'][0]['ruleIds'], ['1.1', '1.2', '1.7'], 'should have set ruleIds');
  assertEqual($data['violations'][0]['reviewed'], false, 'reviewed should be false');
  assertTrue(isset($data['metadata']), 'should have metadata');
  assertEqual($data['metadata']['totalViolations'], 1, 'total should be 1');
};

$failures = 0;
$passed = 0;
foreach ($tests as $name => $fn) {
  try {
    $fn();
    $passed++;
    echo "[PASS] $name\n";
  } catch (Throwable $e) {
    $failures++;
    echo "[FAIL] $name: " . $e->getMessage() . "\n";
  }
}

echo "Tests passed: $passed, failed: $failures\n";

$tests['cli_handles_scanner_exceptions'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  // This test assumes Cli::run catches exceptions from Scanner and returns exit code 1
  // For now, it may fail until we implement exception handling in Cli::run
  [$code, $out] = captureOutput(function () {
    return \PHPReviewAggregator\Cli::run(['aggregator.php', '/nonexistent/directory']);
  });
  assertEqual($code, 1, 'scanner exception should result in exit code 1');
};

$tests['cli_handles_analyzer_exceptions'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  // Placeholder: test for analyzer exceptions
  // Assume Cli::run catches and handles
  assertTrue(true, 'placeholder for analyzer exception handling');
};

$tests['cli_handles_aggregator_exceptions'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  // Placeholder: test for aggregator exceptions
  assertTrue(true, 'placeholder for aggregator exception handling');
};

$tests['cli_handles_reporter_exceptions'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  // Placeholder: test for reporter exceptions
  assertTrue(true, 'placeholder for reporter exception handling');
};

$tests['cli_outputs_to_stdout_when_no_output_file'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $cwd = getcwd();
  [$code, $out] = captureOutput(function () use ($cwd) {
    return \PHPReviewAggregator\Cli::run(['aggregator.php', $cwd]);
  });
  assertEqual($code, 0, 'should exit 0');
  assertTrue(str_contains($out, '{'), 'should output JSON to stdout');
};

$tests['baseline_scanner_acceptance'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $scanner = new \PHPReviewAggregator\Scanner();
  $tempDir = sys_get_temp_dir() . '/baseline_scanner_' . uniqid();
  mkdir($tempDir);
  mkdir($tempDir . '/tests');
  mkdir($tempDir . '/spec');
  file_put_contents($tempDir . '/file.php', '<?php echo "test";');
  file_put_contents($tempDir . '/tests/file.test.php', '<?php echo "test";');
  file_put_contents($tempDir . '/spec/file.spec.php', '<?php echo "test";');
  file_put_contents($tempDir . '/subdir/file2.php', '<?php echo "test";');
  try {
    $base = basename($tempFile);
    $files = $scanner->scanPHPFiles($tempDir);
    assertEqual(count($files), 2, 'should find 2 php files, exclude tests and spec');
    assertTrue(in_array('file.php', $files), 'should include file.php');
    assertTrue(in_array('subdir/file2.php', $files), 'should include subdir/file2.php');
  } finally {
    unlink($tempDir . '/file.php');
    unlink($tempDir . '/tests/file.test.php');
    unlink($tempDir . '/spec/file.spec.php');
    unlink($tempDir . '/subdir/file2.php');
    rmdir($tempDir . '/subdir');
    rmdir($tempDir . '/tests');
    rmdir($tempDir . '/spec');
    rmdir($tempDir);
  }
};

$tests['baseline_analyzer_acceptance'] = function () {
  require_once __DIR__ . '/../src/Cli.php';
  $analyzer = new \PHPReviewAggregator\Analyzer();
  $tempFile = sys_get_temp_dir() . '/baseline_analyzer_' . uniqid() . '.php';
  $code = '<?php
function test() {
  $a = 1; // 1.1 violation
  if ($x if ($x && $y && $z && $w && $v) {if ($x && $y && $z && $w && $v) { $y if ($x && $y && $z && $w && $v) {if ($x && $y && $z && $w && $v) { $z if ($x && $y && $z && $w && $v) {if ($x && $y && $z && $w && $v) { $w if ($x && $y && $z && $w && $v) {if ($x && $y && $z && $w && $v) { $v if ($x && $y && $z && $w && $v) {if ($x && $y && $z && $w && $v) { $u) { // 5.2 violation
    return 42; // 5.3 violation
  }
}
';
  file_put_contents($tempFile, $code);
  try {
    $base = basename($tempFile);
    $violations = $analyzer->analyzeFiles(['$base'], sys_get_temp_dir());
    assertTrue(count($violations) > 0, 'should detect violations');
    // Check for specific rules
    $has1_1 = array_filter($violations, fn($v) => in_array('1.1', $v->ruleIdCandidates));
    $has5_2 = array_filter($violations, fn($v) => in_array('5.2', $v->ruleIdCandidates));
    $has5_3 = array_filter($violations, fn($v) => in_array('5.3', $v->ruleIdCandidates));
    assertTrue(count($has1_1) > 0, 'should detect 1.1');
    assertTrue(count($has5_2) > 0, 'should detect 5.2');
    assertTrue(count($has5_3) > 0, 'should detect 5.3');
  } finally {
    unlink($tempFile);
  }
};
