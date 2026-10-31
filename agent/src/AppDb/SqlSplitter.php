<?php

declare(strict_types=1);

namespace Dodo\AppDb;

/**
 * Splits a MySQL script into single statements, the way the mysql client does.
 *
 * Handles quoted strings and identifiers, comments and the client-side DELIMITER
 * directive. Comments are dropped from the output (except executable comments like
 * "/*!50003 ... *\/"), because PDO's emulated prepares would treat a "?" or ":name"
 * inside one as a placeholder.
 */
final class SqlSplitter
{
    /** Strips a UTF-8 BOM and turns CRLF/CR into LF, so checksums match across platforms. */
    public static function normalize(string $sql): string
    {
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }

        return str_replace(["\r\n", "\r"], "\n", $sql);
    }

    /** @return list<array{sql: string, line: int}> Statements without their delimiter, with their starting line. */
    public static function split(string $sql): array
    {
        $sql = self::normalize($sql);
        $length = strlen($sql);
        $delimiter = ';';
        $statements = [];
        $current = '';
        $startLine = null;
        $line = 1;
        $i = 0;

        $flush = function () use (&$statements, &$current, &$startLine): void {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = ['sql' => $trimmed, 'line' => $startLine];
            }
            $current = '';
            $startLine = null;
        };

        while ($i < $length) {
            $char = $sql[$i];

            // DELIMITER is only recognised at the start of a line, between statements.
            if (trim($current) === '' && ($i === 0 || $sql[$i - 1] === "\n")
                && preg_match('/\GDELIMITER[ \t]+(\S+)[^\n]*/Ai', $sql, $match, 0, $i)) {
                $delimiter = $match[1];
                $current = '';
                $startLine = null;
                $i += strlen($match[0]);
                continue;
            }

            if ($char === "\n") {
                $line++;
                $current .= $char;
                $i++;
                continue;
            }

            // Comments: "-- " (needs whitespace after), "#" and "/* */". "/*!" is MySQL code, so keep it.
            if (($char === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $length || ctype_space($sql[$i + 2])))
                || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*' && ($sql[$i + 2] ?? '') !== '!') {
                $end = strpos($sql, '*/', $i + 2);
                $comment = $end === false ? substr($sql, $i) : substr($sql, $i, $end + 2 - $i);
                $line += substr_count($comment, "\n");
                $current .= ' ';
                $i += strlen($comment);
                continue;
            }

            if (!ctype_space($char)) {
                $startLine ??= $line;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = self::quoteEnd($sql, $i, $char);
                $quoted = substr($sql, $i, $end - $i);
                $line += substr_count($quoted, "\n");
                $current .= $quoted;
                $i = $end;
                continue;
            }

            if (substr_compare($sql, $delimiter, $i, strlen($delimiter)) === 0) {
                $flush();
                $i += strlen($delimiter);
                continue;
            }

            $current .= $char;
            $i++;
        }

        $flush();

        return $statements;
    }

    /** Offset just past the closing quote of the string or identifier starting at $start. */
    private static function quoteEnd(string $sql, int $start, string $quote): int
    {
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            $char = $sql[$i];
            if ($char === '\\' && $quote !== '`') {
                $i += 2;
                continue;
            }
            if ($char === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2; // doubled quote is an escaped quote
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }

        return $length; // unterminated: MySQL will report the error
    }
}
