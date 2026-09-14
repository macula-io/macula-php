<?php

declare(strict_types=1);

// The shared nesting calibration shapes.

// S1: no control structure.
function flat(int $x): int
{
    return $x + 1;
}

// S2: one control structure.
function oneBranch(int $x): string
{
    if ($x === 0) {
        return 'zero';
    }
    return 'other';
}

// S3: a control structure inside one.
function branchInBranch(int $x, int $y): string
{
    if ($x === 0) {
        if ($y === 0) {
            return 'both';
        }
        return 'first_only';
    }
    return 'neither';
}

// S4: three control structures deep.
function threeDeep(int $x, int $y, int $z): string
{
    if ($x === 0) {
        if ($y === 0) {
            if ($z === 0) {
                return 'all';
            }
            return 'two';
        }
        return 'one';
    }
    return 'none';
}

// S5: a closure in the function body.
function closureInBody(array $xs): array
{
    return array_map(fn (int $x): int => $x + 1, $xs);
}

// S6: a closure inside a branch.
function closureInBranch(array $xs): array
{
    if ($xs !== []) {
        return array_map(fn (int $x): int => $x + 1, $xs);
    }
    return [];
}

// S7: a control structure inside a closure.
function branchInClosure(array $xs): array
{
    return array_map(function (int $x): string {
        if ($x === 0) {
            return 'zero';
        }
        return 'other';
    }, $xs);
}
