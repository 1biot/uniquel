<?php

namespace FQL\Query;

use FQL\Parser\Sql;
use FQL\Results\InMemory;
use FQL\Results\ResultsProvider;
use FQL\Results\Stream;
use FQL\Stream\Json;
use FQL\Stream\JsonStream;
use FQL\Stream\Neon;
use FQL\Stream\Xml;
use FQL\Stream\Yaml;
use FQL\Traits\Helpers\StringOperations;

class Debugger
{
    private static ?float $lastSplitTime = null;

    public static function start(): void
    {
        if (!defined('DEBUGGER_START')) {
            define('DEBUGGER_START', microtime(true));
        }
    }

    public static function split(): void
    {
        if (!defined('DEBUGGER_START')) {
            return;
        }

        self::memoryDebug();

        $start = constant('DEBUGGER_START');
        $end = microtime(true);
        $lastSplitTime = self::$lastSplitTime ?? $start;

        $time = round(($end - $lastSplitTime) * 1e6); // µs
        self::echoLineNameValue('Execution time (s)', $time / 1000 / 1000);
        self::echoLineNameValue('Execution time (ms)', $time / 1000);
        self::echoLineNameValue('Execution time (µs)', $time);

        self::$lastSplitTime = $end;
    }

    public static function end(): void
    {
        self::dump('==============================');
        $start = constant('DEBUGGER_START');
        $end = microtime(true);

        $time = round(($end - $start) * 1e6); // µs
        self::memoryUsage();
        self::memoryPeakUsage();
        self::echoLineNameValue('Final execution time (s)', $time / 1000 / 1000, 2);
        self::echoLineNameValue('Final execution time (ms)', $time / 1000, 2);
        self::echoLineNameValue('Final execution time (µs)', $time, 2);
    }

    public static function memoryUsage(bool $realUsage = false): void
    {
        self::echoLineNameValue(
            'Memory usage',
            sprintf(
                '%sMB (%s)',
                round(memory_get_usage($realUsage) / 1024 / 1024, 4),
                $realUsage ? 'real' : 'emalloc'
            )
        );
    }

    public static function memoryPeakUsage(bool $realUsage = false): void
    {
        self::echoLineNameValue(
            'Memory peak usage',
            sprintf(
                '%sMB (%s)',
                round(memory_get_peak_usage($realUsage) / 1024 / 1024, 4),
                $realUsage ? 'real' : 'emalloc'
            )
        );
    }

    public static function memoryDebug(): void
    {
        self::dump('------------------------------');
        self::memoryUsage();
        self::memoryPeakUsage();
        self::dump('------------------------------');
    }

    public static function dump(mixed $var): void
    {
        dump($var);
    }

    public static function inspectQuery(Query $query, bool $listResults = false): void
    {
        self::echoSection('SQL query');
        self::queryToOutput($query->test());

        $results = $query->execute();
        self::echoSection('Results');
        self::echoLineNameValue('Result class', $results::class);
        self::echoLineNameValue('Result exists', $results->exists());
        self::echoLineNameValue('Result count', $results->count());
        if (!$results->exists()) {
            self::split();
            return;
        }

        if ($listResults) {
            self::dump('------------------');
            self::dump(iterator_to_array($results->fetchAll()));
        } else {
            self::echoSection('First row');
            self::dump($results->fetch());
        }

        self::split();
    }

    public static function inspectQuerySql(Xml|Json|JsonStream|Neon|Yaml $stream, string $sql): Query
    {
        self::echoSection('Original SQL query');
        self::queryToOutput($sql);

        $query = (new Sql())
            ->parse(trim($sql), $stream->query());

        self::inspectQuery($query);
        return $query;
    }

    public static function benchmarkQuery(Query $query, int $iterations = 2500): void
    {
        self::echoSection('Benchmark Query');
        self::echoLine(sprintf('%s iterations', number_format($iterations, 0, ',', ' ')));

        self::echoSection('SQL query');
        self::queryToOutput($query->test());

        self::benchmarkStream($query, $iterations);
        self::benchmarkProxy($query, $iterations);
    }

    private static function benchmarkStream(Query $query, int $iterations = 2500): void
    {
        $results = $query->execute(Stream::class);
        self::echoSection('STREAM BENCHMARK');
        self::echoLineNameValue('Size (KB)', round(strlen(serialize($results)) / 1024, 2));
        self::echoLineNameValue('Count', $results->count());

        self::iterateResults($results, $iterations);
        self::split();
    }

    private static function benchmarkProxy(Query $query, int $iterations = 2500): void
    {
        $results = $query->execute();
        self::echoSection('PROXY BENCHMARK');
        self::echoLineNameValue('Size (KB)', round(strlen(serialize($results)) / 1024, 2));
        self::echoLineNameValue('Count', $results->count());

        self::iterateResults($results, $iterations);
        self::split();
    }

    private static function iterateResults(ResultsProvider|InMemory $results, int $iterations = 2500): void
    {
        $counter = 0;
        for ($i = 0; $i < $iterations; $i++) {
            foreach ($results->getIterator() as $row) {
                $counter++;
            }
        }
        self::echoLineNameValue('Iterated results', number_format($counter, 0, ',', ' '));
    }

    public static function echoSection(string $text): void
    {
        $text = '*** ' . $text . ': ***';
        self::dump(str_repeat('=', strlen($text)));
        self::dump($text);
        self::dump(str_repeat('=', strlen($text)));
    }

    public static function echoLineNameValue(string $name, mixed $value, int $beginCharRepeat = 1): void
    {
        self::echoLine(sprintf('%s: %s', $name, $value), $beginCharRepeat);
    }

    public static function echoLine(string $text, int $beginCharRepeat = 1): void
    {
        echo sprintf('%s %s', str_repeat('>', $beginCharRepeat), $text) . PHP_EOL;
    }

    private static function queryToOutput(string $query): void
    {
        echo '> ' . str_replace(PHP_EOL, PHP_EOL . '> ', self::highlightSQL($query)) . PHP_EOL;
    }

    public static function highlightSQL(string $sql): string
    {
        $keywords = [
            'SELECT', 'FROM', 'WHERE', 'ORDER', 'GROUP', 'BY', 'HAVING',
            'LIMIT', 'OFFSET', 'JOIN', 'ON', 'AS', 'AND', 'OR', 'DESC',
            'ASC', 'IN', 'IS', 'NOT', 'NULL', 'SHUFFLE', 'NATURAL', 'LEFT', 'INNER'
        ];

        // Function: Uppercase letters, numbers and underscores, at least 2 characters, cannot start/end with underscore
        $functionPattern = '/\b([A-Z0-9_]{2,})(?<!_)\\((.*?)\\)/';

        // Tokenization with respect to brackets, quotes and multi-line input
        $regex = '/
        \b([A-Z0-9_]{2,})(?<!_)\\((.*?)\\) # function
        |(\'[^\']*\' # simple quoted string
        | "[^"]*" # double quotes
        | [(),] # bracket or comma
        | \b(AND|OR|SELECT|FROM|WHERE|GROUP|HAVING|ORDER|BY|AS|DESC|ASC|IN|ON|LIMIT|OFFSET|SHUFFLE|NATURAL|LEFT|INNER)\b
        | [^\s\'"(),]+ # other than spaces and quoteshe
        )/xi';

        // Preserving multi-line structure
        $lines = explode(PHP_EOL, $sql);
        $highlightedLines = [];
        foreach ($lines as $line) {
            // Extract initial spaces or tabs
            preg_match('/^(\s*)/', $line, $matches);
            $indentation = $matches[1] ?? '';

            preg_match_all($regex, $line, $matches);
            $tokens = $matches[0];

            $highlightedTokens = array_map(function ($token) use ($keywords, $functionPattern) {
                // Keywords
                if (in_array(strtoupper($token), $keywords)) {
                    return "\033[1;34m{$token}\033[0m"; // Blue
                }

                // Functions
                if (preg_match($functionPattern, $token, $matches)) {
                    $functionName = $matches[1];
                    $innerContent = $matches[2];

                    // Highlight parameters in content
                    $highlightedContent = implode(
                        ', ',
                        array_map(
                            fn ($param) => self::isQuoted(trim($param))
                                ? "\033[0;32m" . trim($param) . "\033[0m"
                                : trim($param),
                            explode(',', $innerContent)
                        )
                    );

                    return "\033[0;35m{$functionName}\033[0m({$highlightedContent})";
                }

                // Numbers
                if (is_numeric($token)) {
                    return "\033[0;33m{$token}\033[0m"; // Yellow
                }

                // Strings
                if (preg_match("/^['\"].*['\"]$/", $token)) {
                    return "\033[0;32m{$token}\033[0m"; // Cyan
                }

                // Operators
                if (preg_match('/^(>=|<=|<>|!=|=|<|>|!==|==)$/', $token)) {
                    return "\033[4;36m{$token}\033[0m"; // Azure
                }

                // Return token unchanged
                return $token;
            }, $tokens);

            $highlightedLines[] = $indentation . implode(' ', $highlightedTokens);
        }

        return str_replace([" ," . PHP_EOL, ' , '], [',' . PHP_EOL, ', '], implode(PHP_EOL, $highlightedLines));
    }

    private static function isQuoted(string $input): bool
    {
        return preg_match('/^".*"$/', $input) === 1 || preg_match('/^\'.*\'$/', $input) === 1;
    }
}
