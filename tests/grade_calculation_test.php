<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/functions.php';

$cases = [
    [95.0, 'A', 4.0],
    [87.0, 'A-', 3.7],
    [82.0, 'B+', 3.3],
    [76.0, 'B', 3.0],
    [71.0, 'B-', 2.7],
    [66.0, 'C+', 2.3],
    [61.0, 'C', 2.0],
    [56.0, 'C-', 1.7],
    [51.0, 'D', 1.0],
    [49.0, 'F', 0.0],
];

foreach ($cases as [$percentage, $expectedLetter, $expectedPoints]) {
    $letter = grade_letter($percentage);
    $points = grade_points($letter);
    if ($letter !== $expectedLetter || abs($points - $expectedPoints) > 0.001) {
        fwrite(STDERR, "Failed for {$percentage}: expected {$expectedLetter}/{$expectedPoints}, got {$letter}/{$points}\n");
        exit(1);
    }
}

if (csv_safe('=2+2') !== "'=2+2" || csv_safe('ordinary') !== 'ordinary') {
    fwrite(STDERR, "CSV formula-injection protection failed.\n");
    exit(1);
}

echo "Grade calculation tests passed.\n";
