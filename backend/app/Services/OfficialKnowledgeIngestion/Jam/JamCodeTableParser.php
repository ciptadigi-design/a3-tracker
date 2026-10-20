<?php

namespace App\Services\OfficialKnowledgeIngestion\Jam;

use RuntimeException;

/**
 * "K Troubleshooting > 1. Jam indication > 1.1 Jam code list": a five-column table
 * (Classification, JAM Code, Cause, Resulting operation, Correction).
 *
 * Cells are assigned by the true x coordinate of each text run, never by character offsets.
 * A run may carry several cells separated by runs of 2+ spaces; that is only trusted where the
 * layout makes it unambiguous (classification + code + cause on a row's first line, and
 * resulting operation + correction starting in the resulting-operation column). Anything else
 * quarantines the whole row - nothing is guessed, merged or invented.
 */
final class JamCodeTableParser
{
    public const SECTION = '1.1';

    /** x of each column's text start, in points. The classification column is everything left of CLASS_MAX_X. */
    public const COLUMNS = ['code' => 54.0, 'cause' => 105.0, 'result' => 283.6, 'correction' => 385.6];

    private const CLASS_MAX_X = 50.0;

    private const TOLERANCE = 1.5;

    private const CLASS_LINE_PITCH = 11.0;

    private const CODE = '/^J-\d{4}$/';

    /**
     * @param  array<int, list<array{0: float, 1: float, 2: string}>>  $pages
     */
    public function parse(array $pages): JamCodeParseResult
    {
        ksort($pages);
        $rows = [];
        $groups = [];
        $current = null;
        $lastClass = null; // [page, ty]

        foreach ($pages as $page => $segments) {
            $header = null;
            foreach ($segments as $segment) {
                if (trim($segment[2]) === 'JAM Code') {
                    $header = $segment;
                    break;
                }
            }
            if ($header === null) {
                throw new RuntimeException("Jam code table header not found on physical page {$page}.");
            }
            $lines = [];
            foreach ($segments as $segment) {
                if ($segment[1] >= $header[1] - 6.0 || preg_match('/^K-\d+$/', trim($segment[2])) === 1) {
                    continue;
                }
                $lines[(string) $segment[1]][] = $segment;
            }
            uksort($lines, static fn ($a, $b): int => (float) $b <=> (float) $a);

            foreach ($lines as $ty => $line) {
                $ty = (float) $ty;
                usort($line, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
                $hasCode = false;
                foreach ($line as $segment) {
                    foreach (self::pieces($segment[2]) as $piece) {
                        $hasCode = $hasCode || preg_match(self::CODE, $piece) === 1;
                    }
                }

                if ($hasCode) {
                    $row = ['code' => null, 'group' => null, 'page_start' => $page, 'page_end' => $page, 'cause' => [], 'result' => [], 'correction' => [], 'quarantine' => null];
                    $classLabel = null;
                    $others = [];
                    foreach ($line as $segment) {
                        $column = self::column($segment[0]);
                        $pieces = self::pieces($segment[2]);
                        $codeAt = null;
                        foreach ($pieces as $index => $piece) {
                            if (preg_match(self::CODE, $piece) === 1) {
                                $codeAt = $index;
                                break;
                            }
                        }
                        if ($codeAt !== null) {
                            $before = array_slice($pieces, 0, $codeAt);
                            $after = array_slice($pieces, $codeAt + 1);
                            if ($row['code'] !== null || ! in_array($column, ['class', 'code'], true) || count($before) > 1 || ($column === 'code' && $before !== []) || count($after) !== 1) {
                                $row['quarantine'] ??= 'ambiguous_merged_cells';
                            }
                            $row['code'] ??= $pieces[$codeAt];
                            if ($before !== []) {
                                $classLabel = $before[0];
                            }
                            if (count($after) >= 1) {
                                $row['cause'][] = $after[0];
                            }
                        } elseif ($column === 'class') {
                            count($pieces) === 1 ? $classLabel = $pieces[0] : $row['quarantine'] ??= 'ambiguous_classification';
                        } elseif ($column === null) {
                            $row['quarantine'] ??= 'unrecognized_column';
                        } else {
                            $others[] = [$column, $pieces];
                        }
                    }
                    $row['code'] ??= 'UNKNOWN';
                    if ($classLabel !== null) {
                        $groups[] = [$classLabel];
                    } elseif ($groups === []) {
                        $row['quarantine'] ??= 'missing_classification';
                    }
                    if ($classLabel !== null) {
                        $lastClass = [$page, $ty];
                    }
                    $row['group'] = $groups === [] ? null : count($groups) - 1;
                    foreach ($others as [$column, $pieces]) {
                        self::assign($row, $column, $pieces);
                    }
                    $rows[] = $row;
                    $current = count($rows) - 1;

                    continue;
                }

                foreach ($line as $segment) {
                    if ($current === null) {
                        throw new RuntimeException("Jam table text precedes the first code on physical page {$page}.");
                    }
                    $column = self::column($segment[0]);
                    $pieces = self::pieces($segment[2]);
                    $rows[$current]['page_end'] = $page;
                    if ($column === null) {
                        $rows[$current]['quarantine'] ??= 'unrecognized_column';
                    } elseif ($column === 'class') {
                        // A classification label wrapped onto the next line (e.g. "PF-602m/" + "707").
                        if (count($pieces) === 1 && $lastClass !== null && $lastClass[0] === $page && $lastClass[1] - $ty <= self::CLASS_LINE_PITCH && $groups !== []) {
                            $groups[count($groups) - 1][] = $pieces[0];
                            $lastClass = [$page, $ty];
                        } else {
                            $rows[$current]['quarantine'] ??= 'ambiguous_classification';
                        }
                    } else {
                        self::assign($rows[$current], $column, $pieces);
                    }
                }
            }
        }

        return $this->finish($rows, $groups);
    }

    /** @return list<string> */
    private static function pieces(string $text): array
    {
        return array_values(array_filter(preg_split('/\s{2,}/', trim($text)) ?: [], static fn (string $piece): bool => $piece !== ''));
    }

    private static function column(float $tx): ?string
    {
        if ($tx < self::CLASS_MAX_X) {
            return 'class';
        }
        foreach (self::COLUMNS as $name => $x) {
            if (abs($tx - $x) <= self::TOLERANCE) {
                return $name;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row @param list<string> $pieces */
    private static function assign(array &$row, string $column, array $pieces): void
    {
        $row['quarantine'] ??= null;
        if ($column === 'cause' && count($pieces) === 1) {
            $row['cause'][] = $pieces[0];
        } elseif ($column === 'result' && count($pieces) === 1) {
            $row['result'][] = $pieces[0];
        } elseif ($column === 'result' && count($pieces) === 2) {
            $row['result'][] = $pieces[0];
            $row['correction'][] = $pieces[1];
        } elseif ($column === 'correction' && count($pieces) === 1) {
            $row['correction'][] = $pieces[0];
        } else {
            $row['quarantine'] ??= 'ambiguous_merged_cells';
        }
    }

    /** @param list<string> $lines */
    public static function joinLines(array $lines): string
    {
        $text = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($text === '') {
                $text = $line;
            } elseif (str_ends_with($text, '/') || (str_ends_with($text, '-') && preg_match('/^\p{Ll}/u', $line) === 1)) {
                $text .= $line; // wrapped inside a hyphenated word ("pre-" + "registration") or after a slash ("PF-602m/" + "707")
            } else {
                $text .= ' '.$line;
            }
        }

        return $text;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<list<string>>  $groups
     */
    private function finish(array $rows, array $groups): JamCodeParseResult
    {
        $accepted = [];
        $quarantined = [];
        $identities = [];
        foreach ($rows as $row) {
            $class = $row['group'] === null ? '' : self::joinLines($groups[$row['group']]);
            $cause = self::joinLines($row['cause']);
            $result = self::joinLines($row['result']);
            $correction = self::joinLines($row['correction']);
            $reason = $row['quarantine'];
            if ($reason === null && ($class === '' || $cause === '' || $correction === '')) {
                $reason = 'missing_required_cell';
            }
            if ($reason === null) {
                $identity = $row['code']."\0".strtoupper($class);
                if (isset($identities[$identity])) {
                    $reason = 'duplicate_identity';
                }
                $identities[$identity] = true;
            }
            if ($reason !== null) {
                $quarantined[] = ['code' => $row['code'], 'classification' => $class, 'page_start' => $row['page_start'], 'page_end' => $row['page_end'], 'reason' => $reason];

                continue;
            }
            $accepted[] = new JamCodeRow(
                $row['code'], $class, $cause, in_array($result, ['', '-'], true) ? null : $result, $correction,
                $row['page_start'], $row['page_end'],
            );
        }

        return new JamCodeParseResult($accepted, $quarantined, count($rows));
    }
}
