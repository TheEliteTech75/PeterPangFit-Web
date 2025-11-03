<?php
// ppf_theme.php — central theme catalog + helpers

if (!function_exists('ppf_theme_alpha')) {
    /**
     * Convert a hex color to an rgba() string using the supplied alpha value.
     * Accepts #rgb and #rrggbb; falls back to a teal accent when parsing fails.
     */
    function ppf_theme_alpha(string $hex, float $alpha): string {
        $hex = trim($hex);
        if ($hex === '') {
            return 'rgba(56, 189, 248, ' . max(0, min(1, $alpha)) . ')';
        }

        if ($hex[0] === '#') {
            $hex = substr($hex, 1);
        }

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || preg_match('/[^0-9a-f]/i', $hex)) {
            return 'rgba(56, 189, 248, ' . max(0, min(1, $alpha)) . ')';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $alpha = max(0, min(1, $alpha));
        return 'rgba(' . $r . ', ' . $g . ', ' . $b . ', ' . $alpha . ')';
    }
}

if (!function_exists('ppf_theme_catalog')) {
    /**
     * Returns the available themes keyed by slug.
     * Each theme contains: name, category, preview (array of colors), variables (CSS custom properties).
     */
    function ppf_theme_catalog(): array {
        static $themes = null;
        if ($themes !== null) {
            return $themes;
        }

        $themes = [
            'default' => [
                'name' => 'Default',
                'category' => 'Default',
                'description' => 'Our signature midnight navy with teal highlights.',
                'preview' => ['#05070d', '#38bdf8', '#22d3a2'],
                // Palette mirrored from the legacy :root declarations in index.php and login.php
                // so choosing the "Default" theme reproduces the original landing/auth styling.
                'variables' => [
                    '--bg' => '#05070d',
                    '--bg-alt' => '#03040a',
                    '--surface' => 'rgba(9, 14, 28, 0.92)',
                    '--surface-alt' => 'rgba(15, 23, 42, 0.88)',
                    '--surface-soft' => 'rgba(30, 41, 59, 0.35)',
                    '--surface-strong' => 'rgba(15, 23, 42, 0.94)',
                    '--panel' => 'rgba(9, 14, 28, 0.92)',
                    '--border' => 'rgba(148, 163, 184, 0.26)',
                    '--border-strong' => 'rgba(56, 189, 248, 0.55)',
                    '--primary' => '#6ee7b7',
                    '--primary-strong' => '#22d3a2',
                    '--accent' => '#38bdf8',
                    '--accent-soft' => 'rgba(56, 189, 248, 0.18)',
                    '--brand' => '#38bdf8',
                    '--brand-strong' => '#0ea5e9',
                    '--text' => '#f8fafc',
                    '--muted' => '#9ba4c2',
                    '--muted-soft' => '#cbd5f5',
                    '--muted-strong' => '#cbd5f5',
                    '--danger' => '#f87171',
                    '--warning' => '#d97706',
                    '--success' => '#16a34a',
                    '--shadow' => '0 34px 60px rgba(2, 6, 23, 0.55)',
                    '--line' => 'rgba(148, 163, 184, 0.26)',
                    '--ok' => '#22d3a2',
                    '--warn' => '#d97706',
                ],
            ],
            'black_white' => [
                'name' => 'Black & White',
                'category' => 'Colors',
                'description' => 'Monochrome sheen with crisp contrast.',
                'preview' => ['#050505', '#1b1b1b', '#d4d4d8'],
                'variables' => [
                    '--bg' => '#050505',
                    '--bg-alt' => '#0b0b0b',
                    '--surface' => 'rgba(14, 14, 14, 0.94)',
                    '--surface-alt' => 'rgba(26, 26, 26, 0.86)',
                    '--surface-soft' => 'rgba(40, 40, 40, 0.6)',
                    '--surface-strong' => 'rgba(10, 10, 10, 0.96)',
                    '--panel' => 'rgba(14, 14, 14, 0.94)',
                    '--border' => 'rgba(229, 231, 235, 0.12)',
                    '--border-strong' => 'rgba(212, 212, 216, 0.28)',
                    '--primary' => '#d4d4d8',
                    '--primary-strong' => '#e5e7eb',
                    '--accent' => '#9ca3af',
                    '--accent-soft' => 'rgba(156, 163, 175, 0.16)',
                    '--brand' => '#d4d4d8',
                    '--brand-strong' => '#f4f4f5',
                    '--text' => '#f5f5f5',
                    '--muted' => 'rgba(212, 212, 216, 0.72)',
                    '--muted-soft' => 'rgba(161, 161, 170, 0.68)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#d97706',
                    '--success' => '#15803d',
                    '--shadow' => '0 40px 80px rgba(0, 0, 0, 0.65)',
                    '--line' => 'rgba(229, 231, 235, 0.12)',
                    '--ok' => '#15803d',
                    '--warn' => '#c2410c',
                ],
            ],
            'black_gray' => [
                'name' => 'Black & Gray',
                'category' => 'Colors',
                'description' => 'Charcoal gradients with smoky highlights.',
                'preview' => ['#080b10', '#1f2937', '#475569'],
                'variables' => [
                    '--bg' => '#080b10',
                    '--bg-alt' => '#0e141d',
                    '--surface' => 'rgba(15, 23, 42, 0.94)',
                    '--surface-alt' => 'rgba(30, 41, 59, 0.82)',
                    '--surface-soft' => 'rgba(51, 65, 85, 0.55)',
                    '--surface-strong' => 'rgba(13, 19, 32, 0.95)',
                    '--panel' => 'rgba(17, 24, 39, 0.92)',
                    '--border' => 'rgba(148, 163, 184, 0.2)',
                    '--border-strong' => 'rgba(76, 81, 191, 0.28)',
                    '--primary' => '#94a3b8',
                    '--primary-strong' => '#64748b',
                    '--accent' => '#4c51bf',
                    '--accent-soft' => 'rgba(76, 81, 191, 0.18)',
                    '--brand' => '#4c51bf',
                    '--brand-strong' => '#4338ca',
                    '--text' => '#f9fafb',
                    '--muted' => 'rgba(203, 213, 225, 0.78)',
                    '--muted-soft' => 'rgba(148, 163, 184, 0.68)',
                    '--danger' => '#dc2626',
                    '--warning' => '#c2410c',
                    '--success' => '#16a34a',
                    '--shadow' => '0 34px 70px rgba(2, 6, 23, 0.6)',
                    '--line' => 'rgba(148, 163, 184, 0.2)',
                    '--ok' => '#16a34a',
                    '--warn' => '#c2410c',
                ],
            ],
            'black_blue' => [
                'name' => 'Black & Blue',
                'category' => 'Colors',
                'description' => 'Deep midnight blues with electric edges.',
                'preview' => ['#030712', '#1e3a8a', '#2563eb'],
                'variables' => [
                    '--bg' => '#030712',
                    '--bg-alt' => '#050b1b',
                    '--surface' => 'rgba(7, 13, 28, 0.94)',
                    '--surface-alt' => 'rgba(12, 22, 45, 0.84)',
                    '--surface-soft' => 'rgba(16, 36, 66, 0.55)',
                    '--surface-strong' => 'rgba(7, 16, 32, 0.96)',
                    '--panel' => 'rgba(10, 21, 44, 0.9)',
                    '--border' => 'rgba(37, 99, 235, 0.24)',
                    '--border-strong' => 'rgba(29, 78, 216, 0.35)',
                    '--primary' => '#2563eb',
                    '--primary-strong' => '#1d4ed8',
                    '--accent' => '#1d4ed8',
                    '--accent-soft' => 'rgba(37, 99, 235, 0.18)',
                    '--brand' => '#2563eb',
                    '--brand-strong' => '#1d4ed8',
                    '--text' => '#e2e8f0',
                    '--muted' => 'rgba(191, 219, 254, 0.8)',
                    '--muted-soft' => 'rgba(148, 163, 184, 0.7)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#c2410c',
                    '--success' => '#15803d',
                    '--shadow' => '0 34px 76px rgba(2, 6, 23, 0.65)',
                    '--line' => 'rgba(37, 99, 235, 0.24)',
                    '--ok' => '#15803d',
                    '--warn' => '#c2410c',
                ],
            ],
            'blue_cyan' => [
                'name' => 'Blue & Cyan',
                'category' => 'Colors',
                'description' => 'Glacial blues and cyan auroras.',
                'preview' => ['#04111f', '#0f4c81', '#0ea5e9'],
                'variables' => [
                    '--bg' => '#04111f',
                    '--bg-alt' => '#07192c',
                    '--surface' => 'rgba(8, 31, 53, 0.92)',
                    '--surface-alt' => 'rgba(12, 44, 72, 0.82)',
                    '--surface-soft' => 'rgba(15, 73, 110, 0.55)',
                    '--surface-strong' => 'rgba(6, 23, 40, 0.95)',
                    '--panel' => 'rgba(6, 27, 46, 0.9)',
                    '--border' => 'rgba(37, 99, 235, 0.22)',
                    '--border-strong' => 'rgba(14, 165, 233, 0.35)',
                    '--primary' => '#0ea5e9',
                    '--primary-strong' => '#0369a1',
                    '--accent' => '#2563eb',
                    '--accent-soft' => 'rgba(37, 99, 235, 0.18)',
                    '--brand' => '#0ea5e9',
                    '--brand-strong' => '#0369a1',
                    '--text' => '#f0f9ff',
                    '--muted' => 'rgba(148, 197, 233, 0.8)',
                    '--muted-soft' => 'rgba(125, 211, 252, 0.7)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#c2410c',
                    '--success' => '#15803d',
                    '--shadow' => '0 30px 72px rgba(3, 12, 24, 0.6)',
                    '--line' => 'rgba(37, 99, 235, 0.22)',
                    '--ok' => '#15803d',
                    '--warn' => '#c2410c',
                ],
            ],
            'digital_blue_neon' => [
                'name' => 'Digital Blue Neon',
                'category' => 'Colors',
                'description' => 'Circuit-deep midnight with electric cyan lightlines.',
                'preview' => ['#02030a', '#0f1b3d', '#22d3ee'],
                'variables' => [
                    '--bg' => '#02030a',
                    '--bg-alt' => '#030716',
                    '--surface' => 'rgba(4, 12, 28, 0.92)',
                    '--surface-alt' => 'rgba(8, 22, 46, 0.86)',
                    '--surface-soft' => 'rgba(16, 48, 92, 0.55)',
                    '--surface-strong' => 'rgba(6, 18, 44, 0.96)',
                    '--panel' => 'rgba(6, 18, 44, 0.92)',
                    '--border' => 'rgba(59, 130, 246, 0.28)',
                    '--border-strong' => 'rgba(34, 211, 238, 0.38)',
                    '--primary' => '#22d3ee',
                    '--primary-strong' => '#0ea5e9',
                    '--accent' => '#38bdf8',
                    '--accent-soft' => 'rgba(56, 189, 248, 0.22)',
                    '--brand' => '#0ea5e9',
                    '--brand-strong' => '#0891b2',
                    '--text' => '#e0f2fe',
                    '--muted' => 'rgba(148, 197, 255, 0.78)',
                    '--muted-soft' => 'rgba(125, 211, 252, 0.72)',
                    '--danger' => '#f87171',
                    '--warning' => '#facc15',
                    '--success' => '#34d399',
                    '--shadow' => '0 40px 90px rgba(2, 6, 23, 0.7)',
                    '--line' => 'rgba(59, 130, 246, 0.28)',
                    '--ok' => '#34d399',
                    '--warn' => '#f59e0b',
                ],
            ],
            'digital_red_neon' => [
                'name' => 'Digital Red Neon',
                'category' => 'Colors',
                'description' => 'Synthwave crimson beams over a jet-black grid.',
                'preview' => ['#0a0103', '#3a0d16', '#f43f5e'],
                'variables' => [
                    '--bg' => '#0a0103',
                    '--bg-alt' => '#140205',
                    '--surface' => 'rgba(24, 6, 12, 0.94)',
                    '--surface-alt' => 'rgba(48, 9, 18, 0.86)',
                    '--surface-soft' => 'rgba(74, 13, 28, 0.6)',
                    '--surface-strong' => 'rgba(18, 4, 9, 0.96)',
                    '--panel' => 'rgba(28, 6, 14, 0.92)',
                    '--border' => 'rgba(248, 113, 113, 0.28)',
                    '--border-strong' => 'rgba(244, 63, 94, 0.38)',
                    '--primary' => '#fb7185',
                    '--primary-strong' => '#f43f5e',
                    '--accent' => '#ef4444',
                    '--accent-soft' => 'rgba(239, 68, 68, 0.22)',
                    '--brand' => '#f87171',
                    '--brand-strong' => '#ef4444',
                    '--text' => '#fee2e2',
                    '--muted' => 'rgba(254, 202, 202, 0.8)',
                    '--muted-soft' => 'rgba(248, 113, 113, 0.7)',
                    '--danger' => '#ef4444',
                    '--warning' => '#f97316',
                    '--success' => '#22c55e',
                    '--shadow' => '0 42px 88px rgba(8, 0, 0, 0.75)',
                    '--line' => 'rgba(248, 113, 113, 0.28)',
                    '--ok' => '#22c55e',
                    '--warn' => '#f97316',
                ],
            ],
            'blue_yellow' => [
                'name' => 'Blue & Yellow',
                'category' => 'Colors',
                'description' => 'Navy twilight with sunrise gold accents.',
                'preview' => ['#020817', '#1e3a8a', '#d4a017'],
                'variables' => [
                    '--bg' => '#020817',
                    '--bg-alt' => '#061022',
                    '--surface' => 'rgba(9, 20, 40, 0.93)',
                    '--surface-alt' => 'rgba(15, 30, 52, 0.82)',
                    '--surface-soft' => 'rgba(33, 48, 76, 0.55)',
                    '--surface-strong' => 'rgba(8, 18, 35, 0.96)',
                    '--panel' => 'rgba(11, 23, 46, 0.92)',
                    '--border' => 'rgba(234, 179, 8, 0.2)',
                    '--border-strong' => 'rgba(212, 163, 5, 0.35)',
                    '--primary' => '#eab308',
                    '--primary-strong' => '#d97706',
                    '--accent' => '#c2410c',
                    '--accent-soft' => 'rgba(194, 65, 12, 0.22)',
                    '--brand' => '#eab308',
                    '--brand-strong' => '#d97706',
                    '--text' => '#fef9c3',
                    '--muted' => 'rgba(253, 224, 71, 0.8)',
                    '--muted-soft' => 'rgba(234, 179, 8, 0.7)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#d97706',
                    '--success' => '#16a34a',
                    '--shadow' => '0 32px 72px rgba(2, 8, 23, 0.6)',
                    '--line' => 'rgba(234, 179, 8, 0.2)',
                    '--ok' => '#16a34a',
                    '--warn' => '#c2410c',
                ],
            ],
            'violet_twilight' => [
                'name' => 'Violet Twilight',
                'category' => 'Colors',
                'description' => 'Violet haze with magenta glows.',
                'preview' => ['#120414', '#312e81', '#8b5cf6'],
                'variables' => [
                    '--bg' => '#120414',
                    '--bg-alt' => '#1a0930',
                    '--surface' => 'rgba(24, 7, 48, 0.94)',
                    '--surface-alt' => 'rgba(49, 28, 90, 0.82)',
                    '--surface-soft' => 'rgba(76, 29, 149, 0.55)',
                    '--surface-strong' => 'rgba(18, 6, 36, 0.96)',
                    '--panel' => 'rgba(35, 16, 70, 0.92)',
                    '--border' => 'rgba(168, 85, 247, 0.24)',
                    '--border-strong' => 'rgba(217, 70, 239, 0.35)',
                    '--primary' => '#8b5cf6',
                    '--primary-strong' => '#6d28d9',
                    '--accent' => '#c084fc',
                    '--accent-soft' => 'rgba(192, 132, 252, 0.22)',
                    '--brand' => '#8b5cf6',
                    '--brand-strong' => '#6d28d9',
                    '--text' => '#f5f3ff',
                    '--muted' => 'rgba(221, 214, 254, 0.78)',
                    '--muted-soft' => 'rgba(196, 181, 253, 0.68)',
                    '--danger' => '#be123c',
                    '--warning' => '#d97706',
                    '--success' => '#16a34a',
                    '--shadow' => '0 36px 80px rgba(44, 5, 62, 0.65)',
                    '--line' => 'rgba(168, 85, 247, 0.24)',
                    '--ok' => '#16a34a',
                    '--warn' => '#d97706',
                ],
            ],
            'winter' => [
                'name' => 'Winter',
                'category' => 'Seasons',
                'description' => 'Frosted blues with snow-lit highlights.',
                'preview' => ['#071520', '#0f4c75', '#dbeafe'],
                'variables' => [
                    '--bg' => '#071520',
                    '--bg-alt' => '#0a1f32',
                    '--surface' => 'rgba(9, 32, 49, 0.94)',
                    '--surface-alt' => 'rgba(15, 52, 75, 0.82)',
                    '--surface-soft' => 'rgba(30, 64, 90, 0.55)',
                    '--surface-strong' => 'rgba(7, 24, 40, 0.96)',
                    '--panel' => 'rgba(11, 34, 51, 0.92)',
                    '--border' => 'rgba(148, 197, 233, 0.26)',
                    '--border-strong' => 'rgba(191, 219, 254, 0.38)',
                    '--primary' => '#93c5fd',
                    '--primary-strong' => '#2563eb',
                    '--accent' => '#1d4ed8',
                    '--accent-soft' => 'rgba(29, 78, 216, 0.2)',
                    '--brand' => '#2563eb',
                    '--brand-strong' => '#1d4ed8',
                    '--text' => '#e2f2fd',
                    '--muted' => 'rgba(148, 197, 233, 0.78)',
                    '--muted-soft' => 'rgba(125, 211, 252, 0.68)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#c2410c',
                    '--success' => '#15803d',
                    '--shadow' => '0 36px 78px rgba(5, 18, 32, 0.62)',
                    '--line' => 'rgba(148, 197, 233, 0.26)',
                    '--ok' => '#15803d',
                    '--warn' => '#c2410c',
                ],
            ],
            'spring' => [
                'name' => 'Spring',
                'category' => 'Seasons',
                'description' => 'Soft greens and cherry blossoms.',
                'preview' => ['#05140f', '#1f8a5f', '#f3bfdc'],
                'variables' => [
                    '--bg' => '#05140f',
                    '--bg-alt' => '#0a241b',
                    '--surface' => 'rgba(10, 31, 24, 0.92)',
                    '--surface-alt' => 'rgba(22, 63, 48, 0.8)',
                    '--surface-soft' => 'rgba(45, 106, 79, 0.55)',
                    '--surface-strong' => 'rgba(9, 26, 20, 0.95)',
                    '--panel' => 'rgba(14, 40, 32, 0.9)',
                    '--border' => 'rgba(34, 197, 94, 0.24)',
                    '--border-strong' => 'rgba(74, 222, 128, 0.35)',
                    '--primary' => '#86efac',
                    '--primary-strong' => '#16a34a',
                    '--accent' => '#f4accb',
                    '--accent-soft' => 'rgba(244, 172, 203, 0.22)',
                    '--brand' => '#34d399',
                    '--brand-strong' => '#15803d',
                    '--text' => '#ecfdf5',
                    '--muted' => 'rgba(167, 243, 208, 0.76)',
                    '--muted-soft' => 'rgba(74, 222, 128, 0.6)',
                    '--danger' => '#dc2626',
                    '--warning' => '#d97706',
                    '--success' => '#15803d',
                    '--shadow' => '0 34px 74px rgba(5, 18, 14, 0.6)',
                    '--line' => 'rgba(34, 197, 94, 0.24)',
                    '--ok' => '#15803d',
                    '--warn' => '#d97706',
                ],
            ],
            'summer' => [
                'name' => 'Summer',
                'category' => 'Seasons',
                'description' => 'Tropical corals and ocean teal.',
                'preview' => ['#0b1a1f', '#0d9488', '#fb923c'],
                'variables' => [
                    '--bg' => '#0b1a1f',
                    '--bg-alt' => '#10242b',
                    '--surface' => 'rgba(12, 36, 42, 0.92)',
                    '--surface-alt' => 'rgba(20, 56, 62, 0.82)',
                    '--surface-soft' => 'rgba(34, 94, 103, 0.55)',
                    '--surface-strong' => 'rgba(10, 28, 34, 0.95)',
                    '--panel' => 'rgba(12, 42, 48, 0.92)',
                    '--border' => 'rgba(13, 148, 136, 0.24)',
                    '--border-strong' => 'rgba(45, 212, 191, 0.35)',
                    '--primary' => '#fb923c',
                    '--primary-strong' => '#c2410c',
                    '--accent' => '#0891b2',
                    '--accent-soft' => 'rgba(8, 145, 178, 0.22)',
                    '--brand' => '#14b8a6',
                    '--brand-strong' => '#0f766e',
                    '--text' => '#f0fdfa',
                    '--muted' => 'rgba(128, 222, 208, 0.76)',
                    '--muted-soft' => 'rgba(45, 212, 191, 0.6)',
                    '--danger' => '#dc2626',
                    '--warning' => '#d97706',
                    '--success' => '#15803d',
                    '--shadow' => '0 32px 74px rgba(5, 18, 20, 0.6)',
                    '--line' => 'rgba(13, 148, 136, 0.24)',
                    '--ok' => '#15803d',
                    '--warn' => '#d97706',
                ],
            ],
            'fall' => [
                'name' => 'Fall',
                'category' => 'Seasons',
                'description' => 'Harvest oranges with ember embers.',
                'preview' => ['#140805', '#b45309', '#f3d08f'],
                'variables' => [
                    '--bg' => '#140805',
                    '--bg-alt' => '#1f0f07',
                    '--surface' => 'rgba(31, 15, 7, 0.94)',
                    '--surface-alt' => 'rgba(64, 28, 12, 0.82)',
                    '--surface-soft' => 'rgba(124, 45, 18, 0.55)',
                    '--surface-strong' => 'rgba(24, 11, 6, 0.96)',
                    '--panel' => 'rgba(40, 20, 10, 0.92)',
                    '--border' => 'rgba(234, 179, 8, 0.26)',
                    '--border-strong' => 'rgba(217, 119, 6, 0.38)',
                    '--primary' => '#d97706',
                    '--primary-strong' => '#b45309',
                    '--accent' => '#f3d08f',
                    '--accent-soft' => 'rgba(243, 208, 143, 0.26)',
                    '--brand' => '#d97706',
                    '--brand-strong' => '#b45309',
                    '--text' => '#fff7ed',
                    '--muted' => 'rgba(253, 186, 116, 0.76)',
                    '--muted-soft' => 'rgba(217, 119, 6, 0.68)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#d97706',
                    '--success' => '#15803d',
                    '--shadow' => '0 34px 78px rgba(20, 8, 5, 0.65)',
                    '--line' => 'rgba(234, 179, 8, 0.26)',
                    '--ok' => '#15803d',
                    '--warn' => '#c2410c',
                ],
            ],
            'st_patricks' => [
                'name' => "St. Patrick's",
                'category' => 'Festive',
                'description' => 'Clover greens with gilded trim.',
                'preview' => ['#04120a', '#166534', '#d4a017'],
                'variables' => [
                    '--bg' => '#04120a',
                    '--bg-alt' => '#072012',
                    '--surface' => 'rgba(6, 32, 18, 0.94)',
                    '--surface-alt' => 'rgba(17, 63, 38, 0.82)',
                    '--surface-soft' => 'rgba(34, 94, 62, 0.55)',
                    '--surface-strong' => 'rgba(6, 24, 15, 0.96)',
                    '--panel' => 'rgba(10, 40, 24, 0.92)',
                    '--border' => 'rgba(34, 197, 94, 0.24)',
                    '--border-strong' => 'rgba(212, 163, 5, 0.35)',
                    '--primary' => '#15803d',
                    '--primary-strong' => '#14532d',
                    '--accent' => '#d4a017',
                    '--accent-soft' => 'rgba(212, 163, 5, 0.22)',
                    '--brand' => '#15803d',
                    '--brand-strong' => '#166534',
                    '--text' => '#ecfdf5',
                    '--muted' => 'rgba(187, 247, 208, 0.76)',
                    '--muted-soft' => 'rgba(34, 197, 94, 0.6)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#d97706',
                    '--success' => '#15803d',
                    '--shadow' => '0 32px 74px rgba(4, 18, 10, 0.62)',
                    '--line' => 'rgba(34, 197, 94, 0.24)',
                    '--ok' => '#15803d',
                    '--warn' => '#d4a017',
                ],
            ],
            'usa' => [
                'name' => 'USA (4th of July)',
                'category' => 'Festive',
                'description' => 'Night-sky navy with patriotic red and white.',
                'preview' => ['#030712', '#1d4ed8', '#b91c1c'],
                'variables' => [
                    '--bg' => '#030712',
                    '--bg-alt' => '#061227',
                    '--surface' => 'rgba(8, 20, 45, 0.94)',
                    '--surface-alt' => 'rgba(17, 40, 82, 0.82)',
                    '--surface-soft' => 'rgba(29, 78, 216, 0.35)',
                    '--surface-strong' => 'rgba(6, 16, 32, 0.96)',
                    '--panel' => 'rgba(9, 24, 52, 0.92)',
                    '--border' => 'rgba(239, 68, 68, 0.32)',
                    '--border-strong' => 'rgba(220, 38, 38, 0.48)',
                    '--primary' => '#b91c1c',
                    '--primary-strong' => '#991b1b',
                    '--accent' => '#3b82f6',
                    '--accent-soft' => 'rgba(59, 130, 246, 0.2)',
                    '--brand' => '#ef4444',
                    '--brand-strong' => '#dc2626',
                    '--text' => '#f5f5f5',
                    '--muted' => 'rgba(191, 219, 254, 0.8)',
                    '--muted-soft' => 'rgba(148, 163, 184, 0.7)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#d97706',
                    '--success' => '#15803d',
                    '--shadow' => '0 36px 80px rgba(3, 7, 18, 0.65)',
                    '--line' => 'rgba(239, 68, 68, 0.32)',
                    '--ok' => '#15803d',
                    '--warn' => '#d97706',
                    '--header-text' => '#f87171',
                ],
            ],
            'halloween' => [
                'name' => 'Halloween',
                'category' => 'Festive',
                'description' => 'Wicked purple dusk with candy corn sparks.',
                'preview' => ['#0b0314', '#6b21a8', '#d97706'],
                'variables' => [
                    '--bg' => '#0b0314',
                    '--bg-alt' => '#15052b',
                    '--surface' => 'rgba(22, 5, 43, 0.94)',
                    '--surface-alt' => 'rgba(48, 18, 80, 0.82)',
                    '--surface-soft' => 'rgba(88, 28, 135, 0.55)',
                    '--surface-strong' => 'rgba(14, 4, 32, 0.96)',
                    '--panel' => 'rgba(32, 12, 60, 0.92)',
                    '--border' => 'rgba(249, 115, 22, 0.4)',
                    '--border-strong' => 'rgba(234, 88, 12, 0.52)',
                    '--primary' => '#d97706',
                    '--primary-strong' => '#b45309',
                    '--accent' => '#8b5cf6',
                    '--accent-soft' => 'rgba(139, 92, 246, 0.22)',
                    '--brand' => '#f97316',
                    '--brand-strong' => '#ea580c',
                    '--text' => '#fdf4ff',
                    '--muted' => 'rgba(221, 214, 254, 0.78)',
                    '--muted-soft' => 'rgba(196, 181, 253, 0.68)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#d97706',
                    '--success' => '#15803d',
                    '--shadow' => '0 34px 78px rgba(16, 4, 32, 0.65)',
                    '--line' => 'rgba(249, 115, 22, 0.4)',
                    '--ok' => '#15803d',
                    '--warn' => '#d97706',
                    '--header-text' => '#fb923c',
                ],
            ],
            'thanksgiving' => [
                'name' => 'Thanksgiving',
                'category' => 'Festive',
                'description' => 'Buttery gold and cranberry warmth.',
                'preview' => ['#120705', '#b45309', '#e8c26b'],
                'variables' => [
                    '--bg' => '#120705',
                    '--bg-alt' => '#1b0c07',
                    '--surface' => 'rgba(34, 13, 6, 0.94)',
                    '--surface-alt' => 'rgba(74, 29, 14, 0.82)',
                    '--surface-soft' => 'rgba(146, 64, 14, 0.55)',
                    '--surface-strong' => 'rgba(24, 10, 5, 0.96)',
                    '--panel' => 'rgba(45, 18, 9, 0.92)',
                    '--border' => 'rgba(212, 163, 5, 0.24)',
                    '--border-strong' => 'rgba(194, 65, 12, 0.32)',
                    '--primary' => '#e8c26b',
                    '--primary-strong' => '#ca8a04',
                    '--accent' => '#c2410c',
                    '--accent-soft' => 'rgba(194, 65, 12, 0.22)',
                    '--brand' => '#d97706',
                    '--brand-strong' => '#b45309',
                    '--text' => '#fffbeb',
                    '--muted' => 'rgba(254, 215, 170, 0.76)',
                    '--muted-soft' => 'rgba(217, 119, 6, 0.68)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#d97706',
                    '--success' => '#15803d',
                    '--shadow' => '0 34px 76px rgba(18, 6, 4, 0.65)',
                    '--line' => 'rgba(212, 163, 5, 0.24)',
                    '--ok' => '#15803d',
                    '--warn' => '#c2410c',
                ],
            ],
            'christmas' => [
                'name' => 'Christmas',
                'category' => 'Festive',
                'description' => 'Evergreen spruce with crimson ribbons.',
                'preview' => ['#04100a', '#166534', '#b91c1c'],
                'variables' => [
                    '--bg' => '#04100a',
                    '--bg-alt' => '#092015',
                    '--surface' => 'rgba(9, 39, 25, 0.94)',
                    '--surface-alt' => 'rgba(20, 72, 45, 0.82)',
                    '--surface-soft' => 'rgba(30, 102, 63, 0.52)',
                    '--surface-strong' => 'rgba(7, 30, 18, 0.96)',
                    '--panel' => 'rgba(12, 47, 30, 0.92)',
                    '--border' => 'rgba(239, 68, 68, 0.34)',
                    '--border-strong' => 'rgba(220, 38, 38, 0.5)',
                    '--primary' => '#b91c1c',
                    '--primary-strong' => '#991b1b',
                    '--accent' => '#15803d',
                    '--accent-soft' => 'rgba(21, 128, 61, 0.24)',
                    '--brand' => '#ef4444',
                    '--brand-strong' => '#dc2626',
                    '--text' => '#f0fdf4',
                    '--muted' => 'rgba(187, 247, 208, 0.76)',
                    '--muted-soft' => 'rgba(74, 222, 128, 0.6)',
                    '--danger' => '#b91c1c',
                    '--warning' => '#d97706',
                    '--success' => '#15803d',
                    '--shadow' => '0 32px 74px rgba(4, 18, 10, 0.64)',
                    '--line' => 'rgba(239, 68, 68, 0.34)',
                    '--ok' => '#15803d',
                    '--warn' => '#d97706',
                    '--header-text' => '#f87171',
                ],
            ],
        ];

        return $themes;
    }

    function ppf_theme_default_key(): string {
        return 'default';
    }

    function ppf_theme_exists(string $key): bool {
        $themes = ppf_theme_catalog();
        return isset($themes[$key]);
    }

    function ppf_theme_sanitize_key(string $key): string {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9_-]/i', '', $key)));
        return $slug !== '' ? $slug : ppf_theme_default_key();
    }

    function ppf_theme_resolve(string $key): string {
        $slug = ppf_theme_sanitize_key($key);
        return ppf_theme_exists($slug) ? $slug : ppf_theme_default_key();
    }

    function ppf_theme_variables(string $key): array {
        $themes = ppf_theme_catalog();
        $resolved = ppf_theme_resolve($key);
        return $themes[$resolved]['variables'];
    }

    function ppf_theme_preview_gradient(array $theme): string {
        $colors = $theme['preview'] ?? [];
        if (!is_array($colors) || count($colors) < 2) {
            $vars = $theme['variables'] ?? [];
            $fallback = array_values(array_filter([
                $vars['--bg'] ?? null,
                $vars['--accent'] ?? null,
                $vars['--primary'] ?? null,
            ]));
            $colors = count($fallback) >= 2 ? $fallback : ['#05070d', '#38bdf8'];
        }
        $stops = array_map(fn($c) => (string)$c, array_slice($colors, 0, 4));
        return 'linear-gradient(135deg, ' . implode(', ', $stops) . ')';
    }

    function ppf_theme_grouped_catalog(): array {
        $grouped = [];
        foreach (ppf_theme_catalog() as $key => $theme) {
            $category = $theme['category'] ?? 'Other';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][$key] = $theme;
        }
        return $grouped;
    }

    function ppf_theme_enrich_variables(array $theme): array {
        $vars = $theme['variables'] ?? [];
        $preview = $theme['preview'] ?? [];

        $candidates = [];
        foreach ($preview as $color) {
            if ($color !== null && $color !== '') {
                $candidates[] = (string)$color;
            }
        }

        foreach (['--bg', '--accent', '--primary', '--brand', '--primary-strong'] as $varName) {
            if (!empty($vars[$varName])) {
                $candidates[] = (string)$vars[$varName];
            }
        }

        $candidates[] = '#05070d';
        $candidates = array_values(array_unique($candidates));

        for ($i = 0; $i < 3; $i++) {
            $vars['--theme-swatch-' . ($i + 1)] = $candidates[$i] ?? '#05070d';
        }

        $bg     = $vars['--bg'] ?? '#05070d';
        $bgAlt  = $vars['--bg-alt'] ?? '#03040a';
        $surface    = $vars['--surface'] ?? 'rgba(9, 14, 28, 0.92)';
        $surfaceAlt = $vars['--surface-alt'] ?? 'rgba(15, 23, 42, 0.78)';
        $border     = $vars['--border'] ?? 'rgba(148, 163, 184, 0.18)';
        $accent     = $vars['--accent'] ?? '#38bdf8';
        $primary    = $vars['--primary'] ?? '#6ee7b7';
        $brand      = $vars['--brand'] ?? $accent;

        if (!isset($vars['--accent-soft'])) {
            $vars['--accent-soft'] = ppf_theme_alpha($accent, 0.18);
        }

        if (!isset($vars['--page-canvas'])) {
            $vars['--page-canvas'] = 'radial-gradient(circle at top left, ' . ppf_theme_alpha($accent, 0.22) . ' 0%, transparent 55%),'
                . ' radial-gradient(circle at bottom right, ' . ppf_theme_alpha($primary, 0.16) . ' 0%, transparent 60%),'
                . ' linear-gradient(155deg, ' . $bg . ', ' . $bgAlt . ')';
        }

        if (!isset($vars['--panel-elevated'])) {
            $vars['--panel-elevated'] = $vars['--panel'] ?? $surface;
        }

        if (!isset($vars['--panel-muted'])) {
            $vars['--panel-muted'] = $surfaceAlt;
        }

        if (!isset($vars['--chip-bg'])) {
            $vars['--chip-bg'] = 'color-mix(in srgb, ' . $surfaceAlt . ' 86%, ' . $accent . ' 14%)';
        }

        if (!isset($vars['--chip-border'])) {
            $vars['--chip-border'] = 'color-mix(in srgb, ' . $border . ' 74%, ' . $accent . ' 26%)';
        }

        if (!isset($vars['--chip'])) {
            $vars['--chip'] = 'color-mix(in srgb, ' . $surface . ' 78%, ' . $primary . ' 22%)';
        }

        if (!isset($vars['--card-border'])) {
            $vars['--card-border'] = $border;
        }

        if (!isset($vars['--card-border-subtle'])) {
            $vars['--card-border-subtle'] = 'color-mix(in srgb, ' . $border . ' 65%, transparent 35%)';
        }

        if (!isset($vars['--card-border-hover'])) {
            $vars['--card-border-hover'] = 'color-mix(in srgb, ' . $border . ' 55%, ' . $accent . ' 45%)';
        }

        if (!isset($vars['--card-shadow'])) {
            $vars['--card-shadow'] = '0 24px 48px ' . ppf_theme_alpha($bg, 0.55);
        }

        if (!isset($vars['--heading-accent'])) {
            $vars['--heading-accent'] = 'linear-gradient(135deg, ' . $accent . ', ' . $brand . ')';
        }

        if (!isset($vars['--badge-muted'])) {
            $vars['--badge-muted'] = ppf_theme_alpha($accent, 0.24);
        }

        if (!isset($vars['--input-bg'])) {
            $vars['--input-bg'] = 'color-mix(in srgb, ' . $surface . ' 92%, rgba(255, 255, 255, 0.04) 8%)';
        }

        if (!isset($vars['--input-border'])) {
            $vars['--input-border'] = 'color-mix(in srgb, ' . $border . ' 78%, ' . $accent . ' 22%)';
        }

        if (!isset($vars['--line'])) {
            $vars['--line'] = $border;
        }

        return $vars;
    }
    function ppf_theme_render_style_block(): string {
        $themes = ppf_theme_catalog();
        $defaultTheme = $themes[ppf_theme_default_key()] ?? reset($themes);
        $defaultVars = ppf_theme_enrich_variables($defaultTheme);
        $css = [
            ':root {' . ppf_theme_build_css_vars($defaultVars) . '}'
        ];
        foreach ($themes as $key => $theme) {
            $vars = ppf_theme_enrich_variables($theme);
            $css[] = ':root[data-theme="' . $key . '"] {' . ppf_theme_build_css_vars($vars) . '}';
        }
        return "<style>\n" . implode("\n", $css) . "\n</style>";
    }

    function ppf_theme_build_css_vars(array $vars): string {
        $lines = [];
        foreach ($vars as $name => $value) {
            $lines[] = "    {$name}: {$value};";
        }
        return "\n" . implode("\n", $lines) . "\n";
    }

    function ppf_theme_ensure_column(mysqli $conn): void {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            $sql = "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'theme'";
            if ($res = $conn->query($sql)) {
                $row = $res->fetch_assoc();
                $res->close();
                if ((int)($row['c'] ?? 0) > 0) {
                    return;
                }
            }
            @$conn->query("ALTER TABLE users ADD COLUMN theme VARCHAR(64) NULL DEFAULT NULL");
        } catch (Throwable $e) {
            // ignore — themes will fall back to default
        }
    }
}
