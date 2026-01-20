<?php

declare(strict_types=1);

namespace PHPReviewAggregator;

final class Cli
{
  public const NAME = 'php-review-file-aggregator';
  public const VERSION = '0.1.0';

  /**
   * Main entry for CLI. Returns exit code.
   *
   * Exit codes:
   * 0: success
   * 1: file/path error
   * 2: invalid args or incompatible environment
   */
  public static function run(array $argv): int
  {
    // Basic arg parsing
    $args = $argv;
    array_shift($args); // drop script name

    // Handle help/version first
    if (in_array('--help', $args, true)) {
      self::printHelp();
      return 0;
    }
    if (in_array('--version', $args, true)) {
      echo self::NAME . " Version: " . self::VERSION . "\n";
      return 0;
    }

    // Environment check (PHP 8.2+)
    if (!self::isCompatibleVersion(PHP_VERSION)) {
      fwrite(STDERR, "Error: PHP 8.2+ required. Current: " . PHP_VERSION . "\n");
      return 2;
    }

    // Parse options: --output <file>, --filter <pattern>
    $outputFile = null;
    $pos = array_search('--output', $args, true);
    if ($pos !== false) {
      if (!isset($args[$pos + 1])) {
        self::printUsageError('Missing value for --output');
        return 2;
      }
      $outputFile = (string)$args[$pos + 1];
      // remove these two entries
      array_splice($args, $pos, 2);
    }

    $filter = null;
    $pos = array_search('--filter', $args, true);
    if ($pos !== false) {
      if (!isset($args[$pos + 1])) {
        self::printUsageError('Missing value for --filter');
        return 2;
      }
      $filter = (string)$args[$pos + 1];
      // remove these two entries
      array_splice($args, $pos, 2);
    }

    // Any remaining unknown options starting with -- cause error
    foreach ($args as $a) {
      if (str_starts_with($a, '--')) {
        self::printUsageError('Unknown option: ' . $a);
        return 2;
      }
    }

    // Expect target_dir as first positional arg
    if (count($args) < 1) {
      self::printUsageError('Missing required <target_dir>');
      return 2;
    }
    $targetDir = (string)$args[0];

    // Validate directory
    if (!is_dir($targetDir) || !is_readable($targetDir)) {
      fwrite(STDERR, "Error: target directory not found or not readable: {$targetDir}\n");
      return 1;
    }

    // Initialize flow (Scanner/Analyzer/Aggregator/Reporter)
    $scanner = new Scanner();
    $analyzer = new Analyzer();
    $aggregator = new Aggregator();
    $reporter = new Reporter($outputFile);

    // Execute pipeline
    try {
      $files = $scanner->scanPHPFiles($targetDir, $filter);
      $rawViolations = $analyzer->analyzeFiles($files, $targetDir);
      $violations = $aggregator->aggregate($rawViolations);
      $json = $reporter->generateJSON($violations, $targetDir);
      $reporter->output($json);
    } catch (\Throwable $e) {
      fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
      return 1;
    }

    return 0;
  }

  public static function printHelp(): void
  {
    echo "Usage: php aggregator.php <target_dir> [OPTIONS]\n";
    echo "\nOPTIONS:\n";
    echo "  --output FILE   Output JSON file path (optional)\n";
    echo "  --filter PATTERN Filter files by pattern (not implemented yet)\n";
    echo "  --help          Show this help message\n";
    echo "  --version       Show version information\n";
    echo "\nExamples:\n";
    echo "  php aggregator.php /path/to/project\n";
    echo "  php aggregator.php /path/to/project --output violations.json\n";
  }

  private static function printUsageError(string $message): void
  {
    echo "Usage: php aggregator.php <target_dir> [OPTIONS]\n";
    echo "Error: {$message}\n";
  }

  public static function isCompatibleVersion(string $version): bool
  {
    return version_compare($version, '8.2.0', '>=');
  }
}

final class Scanner
{
  /**
   * Scan for PHP files recursively, excluding test/spec directories and *.test.php files.
   * Returns array of relative paths from the target directory.
   * @param string $targetDir
   * @param string|null $filter Placeholder for future filter implementation
   * @return string[]
   */
  public function scanPHPFiles(string $targetDir, ?string $filter = null): array
  {
    if (!is_dir($targetDir)) {
      throw new \InvalidArgumentException("Target directory does not exist: {$targetDir}");
    }
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($targetDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if ($file->isFile() && $file->getExtension() === 'php') {
        $relativePath = str_replace($targetDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
        // Exclude files in tests/ or spec/ directories, or *.test.php
        if (!$this->shouldExclude($relativePath)) {
          // TODO: Apply filter if provided
          $files[] = $relativePath;
        }
      }
    }
    return $files;
  }

  private function shouldExclude(string $path): bool
  {
    // Exclude if path starts with tests/ or spec/, or ends with .test.php
    return str_starts_with($path, 'tests' . DIRECTORY_SEPARATOR) ||
      str_starts_with($path, 'spec' . DIRECTORY_SEPARATOR) ||
      str_ends_with($path, '.test.php');
  }
}
final class Analyzer
{
  /**
   * Analyze PHP files for violations.
   * @param string[] $files Array of relative file paths
   * @param string $targetDir Base directory for resolving full paths
   * @return RawViolation[]
   */
  public function analyzeFiles(array $files, string $targetDir): array
  {
    $violations = [];
    foreach ($files as $file) {
      $fullPath = $targetDir . DIRECTORY_SEPARATOR . $file;
      if (!is_readable($fullPath)) {
        continue; // Skip unreadable files
      }
      $content = file_get_contents($fullPath);
      if ($content === false) {
        continue;
      }
      $tokens = token_get_all($content);
      $fileViolations = $this->analyzeTokens($tokens, $file);
      $violations = array_merge($violations, $fileViolations);
    }
    return $violations;
  }

  /**
   * Analyze tokens for violations.
   * @param array $tokens
   * @param string $filePath
   * @return RawViolation[]
   */
  private function analyzeTokens(array $tokens, string $filePath): array
  {
    $violations = [];
    $line = 1;
    $inFunction = false;
    $functionStartLine = 0;
    $variables = [];
    $variableAssignments = [];
    $controlStructures = 0;
    $inClass = false;
    $classMethods = [];
    $currentVisibility = null;
    $braceDepth = 0;
    $classBraceDepth = 0;
    $inCondition = false;
    $conditionStart = 0;
    $conditionTokens = [];

    foreach ($tokens as $i => $token) {
      if ($inCondition) {
        if ($token === '(') {
          $conditionParenDepth++;
        } elseif ($token === ')') {
          $conditionParenDepth--;
          if ($conditionParenDepth == 0) {
            $conditionStr = '';
            foreach ($conditionTokens as $ct) {
              $conditionStr .= is_array($ct) ? $ct[1] : $ct;
            }
            $logicalOps = substr_count($conditionStr, '&&') + substr_count($conditionStr, '||');
            if (strlen($conditionStr) >= 100 || $logicalOps >= 3) {
              $ruleIds = [];
              if (strlen($conditionStr) >= 100) {
                $ruleIds[] = '4.1';
              }
              if ($logicalOps >= 3) {
                $ruleIds[] = '5.2';
              }
              $violations[] = new RawViolation(
                $filePath,
                $tokenLine,
                'condition',
                $ruleIds,
                "Complex condition expression: " . substr($conditionStr, 0, 50) . "..."
              );
            }
            $inCondition = false;
            $conditionTokens = [];
          }
        } else {
          $conditionTokens[] = $token;
        }
      }
      if (is_array($token)) {
        $tokenType = $token[0];
        $tokenValue = $token[1];
        $tokenLine = $token[2];

        // Track line numbers
        $line = $tokenLine;

        if ($tokenType === T_CLASS) {
          $inClass = true;
          $classMethods = [];
          $classBraceDepth = $braceDepth;
        } elseif ($inClass && ($tokenType === T_PUBLIC || $tokenType === T_PROTECTED || $tokenType === T_PRIVATE)) {
          $visibility = match ($tokenType) {
            T_PUBLIC => 'public',
            T_PROTECTED => 'protected',
            T_PRIVATE => 'private',
          };
          $currentVisibility = $visibility;
        } elseif ($tokenType === T_FUNCTION) {
          if ($inClass) {
            // Record method with visibility
            $classMethods[] = ['visibility' => $currentVisibility ?? 'public', 'line' => $tokenLine];
            $currentVisibility = null; // reset for next
          } else {
            $inFunction = true;
            $functionStartLine = $tokenLine;
            $variables = []; // Reset variables for new function
            $variableAssignments = []; // Reset assignments for new function
            $controlStructures = 0; // Reset control structures for new function
          }
        } elseif ($tokenType === T_IF || $tokenType === T_WHILE || $tokenType === T_FOR || $tokenType === T_FOREACH) {
          if ($inFunction) {
            $controlStructures++;
          }
          $inCondition = true;
          $conditionTokens = [];
          $conditionParenDepth = 0;
        } elseif ($tokenType === T_COMMENT || $tokenType === T_DOC_COMMENT) {
          $commentText = trim($tokenValue);
          if (str_starts_with($commentText, '//')) {
            $commentText = trim(substr($commentText, 2));
          } elseif (str_starts_with($commentText, '/*')) {
            $commentText = trim(substr($commentText, 2, -2));
          }
          // Find next non-whitespace token
          $nextCode = '';
          for ($j = $i + 1; $j < count($tokens); $j++) {
            $next = $tokens[$j];
            if (is_array($next)) {
              $nextType = $next[0];
              $nextValue = $next[1];
              if ($nextType !== T_WHITESPACE && $nextType !== T_COMMENT && $nextType !== T_DOC_COMMENT) {
                $nextCode = $nextValue;
                // collect until ;
                for ($k = $j + 1; $k < count($tokens); $k++) {
                  $n = $tokens[$k];
                  if (is_array($n)) {
                    $nt = $n[0];
                    $nv = $n[1];
                    if ($nt === T_WHITESPACE) continue;
                    $nextCode .= $nv;
                    if ($nv === ';') break;
                  } elseif ($n === ';') {
                    $nextCode .= ';';
                    break;
                  }
                }
                break;
              }
            } elseif ($next !== '' && $next !== ' ' && $next !== "\n" && $next !== "\t") {
              $nextCode = $next;
              break;
            }
          }
          if ($nextCode !== '' && str_contains($nextCode, $commentText)) {
            $violations[] = new RawViolation(
              $filePath,
              $tokenLine,
              'comment',
              ['3.1'],
              "Redundant comment: $commentText"
            );
          }
        } elseif ($tokenType === T_VARIABLE) {
          if ($inFunction) {
            $varName = $tokenValue;
            if (strlen($varName) === 2 && $varName[1] >= 'a' && $varName[1] <= 'z') { // $a to $z
              $variables[$varName] = $tokenLine;
              // Detect 1.1: single letter variable
              $violations[] = new RawViolation(
                $filePath,
                $tokenLine,
                $varName,
                ['1.1'],
                "Single letter variable: $varName"
              );
            }
          }
        } elseif ($tokenType === T_LNUMBER) {
          // Detect magic numbers (numeric literals)
          $violations[] = new RawViolation(
            $filePath,
            $tokenLine,
            $tokenValue,
            ['5.3'],
            "Magic number: $tokenValue"
          );
        }
      } elseif ($token === '=') {
        // Check if previous non-whitespace token is a variable
        $prevIndex = $i - 1;
        while ($prevIndex >= 0 && is_array($tokens[$prevIndex]) && $tokens[$prevIndex][0] === T_WHITESPACE) {
          $prevIndex--;
        }
        if ($prevIndex >= 0 && is_array($tokens[$prevIndex]) && $tokens[$prevIndex][0] === T_VARIABLE) {
          $varName = $tokens[$prevIndex][1];
          if (!isset($variableAssignments[$varName])) {
            $variableAssignments[$varName] = 0;
          }
          $variableAssignments[$varName]++;
        }
      } elseif ($token === '{') {
        $braceDepth++;
      } elseif ($token === '}') {
        $braceDepth--;
        // Exiting block
        if ($inFunction) {
          $functionEndLine = $line;
          $scopeLines = $functionEndLine - $functionStartLine + 1;
          if ($scopeLines >= 10) {
            foreach ($variables as $var => $varLine) {
              $violations[] = new RawViolation(
                $filePath,
                $varLine,
                $var,
                ['1.1'],
                "Single letter variable in long scope"
              );
            }
          }          // Check for long or complex function (7.1)
          if ($scopeLines >= 20 || $controlStructures >= 5) {
            $violations[] = new RawViolation(
              $filePath,
              $functionStartLine,
              'function',
              ['7.1'],
              "Function too long or complex: $scopeLines lines, $controlStructures control structures"
            );
          }
          // Check for multiple assignments (6.6)
          foreach ($variableAssignments as $var => $count) {
            if ($count > 1) {
              $violations[] = new RawViolation(
                $filePath,
                $functionStartLine,
                $var,
                ['6.6'],
                "Variable assigned multiple times: $count assignments"
              );
            }
          }
          $inFunction = false;
          $variables = [];
          $variableAssignments = [];
          $controlStructures = 0;
        } elseif ($inClass && $braceDepth == $classBraceDepth) {
          // End of class, check method order
          $this->checkMethodOrder($classMethods, $filePath, $violations);
          $inClass = false;
          $classMethods = [];
        }
      } elseif ($token === '(') {
        if ($inCondition) {
          $conditionParenDepth = ($conditionParenDepth ?? 0) + 1;
        }
      } elseif ($token === ')') {
        if ($inCondition) {
          $conditionParenDepth--;
          if ($conditionParenDepth == 0) {
            $conditionStr = '';
            foreach ($conditionTokens as $ct) {
              $conditionStr .= is_array($ct) ? $ct[1] : $ct;
            }
            $logicalOps = substr_count($conditionStr, '&&') + substr_count($conditionStr, '||');
            if (strlen($conditionStr) >= 100 || $logicalOps >= 3) {
              $ruleIds = [];
              if (strlen($conditionStr) >= 100) {
                $ruleIds[] = '4.1';
              }
              if ($logicalOps >= 3) {
                $ruleIds[] = '5.2';
              }
              $violations[] = new RawViolation(
                $filePath,
                $tokenLine,
                'condition',
                $ruleIds,
                "Complex condition expression: " . substr($conditionStr, 0, 50) . "..."
              );
            }
            $inCondition = false;
            $conditionTokens = [];
          }
        }
      } else {
        if ($inCondition) {
          $conditionTokens[] = $token;
        }
      }
    }

    return $violations;
  }

  private function checkMethodOrder(array $methods, string $filePath, array &$violations): void
  {
    $lastVis = null;
    foreach ($methods as $method) {
      $vis = $method['visibility'];
      if ($lastVis === 'private' && $vis === 'public') {
        $violations[] = new RawViolation(
          $filePath,
          $method['line'],
          'method',
          ['2.1'],
          "Method visibility order violation: private before public"
        );
      }
      $lastVis = $vis;
    }
  }
}
final class Aggregator
{
  public function __construct(private RulesetManager $rulesetManager = new RulesetManager()) {}

  /**
   * Aggregate raw violations into structured violations with ruleIds and reviewed flag.
   * @param RawViolation[] $rawViolations
   * @return Violation[]
   */
  public function aggregate(array $rawViolations): array
  {
    $grouped = [];
    foreach ($rawViolations as $violation) {
      $key = $violation->filePath . ':' . $violation->line . ':' . $violation->element;
      if (!isset($grouped[$key])) {
        $grouped[$key] = [
          'filePath' => $violation->filePath,
          'line' => $violation->line,
          'element' => $violation->element,
          'ruleIdCandidates' => [],
        ];
      }
      $grouped[$key]['ruleIdCandidates'] = array_merge($grouped[$key]['ruleIdCandidates'], $violation->ruleIdCandidates);
    }

    $sets = $this->rulesetManager->getSets();
    $violations = [];
    foreach ($grouped as $group) {
      $ruleIds = $this->applySets($group['ruleIdCandidates'], $sets);
      $violations[] = new Violation(
        $group['filePath'],
        $group['line'],
        $group['element'],
        $ruleIds,
        false
      );
    }

    // Sort by filePath, then line
    usort($violations, fn($a, $b) => $a->filePath <=> $b->filePath ?: $a->line <=> $b->line);

    return $violations;
  }

  /**
   * Apply set rules to ruleIdCandidates.
   * @param string[] $candidates
   * @param array<string, string[]> $sets
   * @return string[]
   */
  private function applySets(array $candidates, array $sets): array
  {
    $candidates = array_unique($candidates);
    foreach ($sets as $setName => $setRules) {
      $hasAll = true;
      foreach ($setRules as $rule) {
        if (!in_array($rule, $candidates)) {
          $hasAll = false;
          break;
        }
      }
      if ($hasAll) {
        return $setRules;
      }
    }
    return $candidates;
  }
}
final class Reporter
{
  public function __construct(public ?string $outputFile) {}

  /**
   * Generate JSON string from violations with metadata.
   * @param Violation[] $violations
   * @param string $targetDir
   * @return string
   */
  public function generateJSON(array $violations, string $targetDir): string
  {
    $data = [
      'violations' => array_map(fn($v) => [
        'filePath' => $v->filePath,
        'line' => $v->line,
        'element' => $v->element,
        'ruleIds' => $v->ruleIds,
        'reviewed' => $v->reviewed,
      ], $violations),
      'metadata' => [
        'generatedAt' => date('c'),
        'targetDirectory' => $targetDir,
        'totalViolations' => count($violations),
        'appliedRulesets' => ['1.1-1.2-1.7', '3.1-3.3', '5.1-5.2-5.3-6.1', '7.1-7.2'], // from RulesetManager
      ],
    ];
    return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
  }

  /**
   * Output the JSON to file or stdout.
   * @param string $json
   * @return void
   */
  public function output(string $json): void
  {
    if ($this->outputFile !== null) {
      $dir = dirname($this->outputFile);
      if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
          throw new \RuntimeException("Failed to create directory: {$dir}");
        }
      }
      if (file_put_contents($this->outputFile, $json) === false) {
        throw new \RuntimeException("Failed to write to file: {$this->outputFile}");
      }
    } else {
      echo $json;
    }
  }
}
final class RulesetManager
{
  /**
   * Get all rule definitions.
   * @return array<string, array>
   */
  public function getRules(): array
  {
    return [
      // Placeholder for rule definitions
      '1.1' => ['pattern' => '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*=/', 'description' => 'Single letter variable'],
      // More rules to be added
    ];
  }

  /**
   * Get set mappings.
   * @return array<string, string[]>
   */
  public function getSets(): array
  {
    return [
      '1.1-1.2-1.7' => ['1.1', '1.2', '1.7'],
      '3.1-3.3' => ['3.1', '3.3'],
      '5.1-5.2-5.3-6.1' => ['5.1', '5.2', '5.3', '6.1'],
      '7.1-7.2' => ['7.1', '7.2'],
    ];
  }
}

/**
 * Raw violation data structure.
 */
final class RawViolation
{
  public function __construct(
    public string $filePath,
    public int $line,
    public string $element,
    public array $ruleIdCandidates,
    public string $context
  ) {}
}

/**
 * Aggregated violation data structure.
 */
final class Violation
{
  public function __construct(
    public string $filePath,
    public int $line,
    public string $element,
    public array $ruleIds,
    public bool $reviewed
  ) {}
}
