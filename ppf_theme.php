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
                'description' => 'Our original neon coastline palette.',
                'preview' => ['#05070d', '#0ea5e9', '#22d3a2'],
                'variables' => [
                    '--bg' => '#05070d',
                    '--bg-alt' => '#03040a',
                    '--surface' => 'rgba(9, 14, 28, 0.92)',
                    '--surface-alt' => 'rgba(15, 23, 42, 0.78)',
                    '--surface-soft' => 'rgba(15, 23, 42, 0.65)',
                    '--surface-strong' => 'rgba(11, 16, 32, 0.94)',
                    '--panel' => 'rgba(9, 14, 28, 0.92)',
                    '--border' => 'rgba(148, 163, 184, 0.18)',
                    '--border-strong' => 'rgba(56, 189, 248, 0.35)',
                    '--primary' => '#6ee7b7',
                    '--primary-strong' => '#22d3a2',
                    '--accent' => '#38bdf8',
                    '--accent-soft' => 'rgba(56, 189, 248, 0.16)',
                    '--brand' => '#38bdf8',
                    '--brand-strong' => '#0ea5e9',
                    '--text' => '#f8fafc',
                    '--muted' => 'rgba(203, 213, 225, 0.78)',
                    '--muted-soft' => 'rgba(148, 163, 184, 0.72)',
                    '--danger' => '#f87171',
                    '--warning' => '#fbbf24',
                    '--success' => '#34d399',
                    '--shadow' => '0 30px 70px rgba(2, 6, 23, 0.55)',
                    '--line' => 'rgba(148, 163, 184, 0.18)',
                    '--ok' => '#34d399',
                    '--warn' => '#f97316',
                ],
            ],
            'black_white' => [
                'name' => 'Black & White',
                'category' => 'Colors',
                'description' => 'Monochrome sheen with crisp contrast.',
                'preview' => ['#040404', '#131313', '#f0f0f0'],
                'variables' => [
                    '--bg' => '#040404',
                    '--bg-alt' => '#0d0d0d',
                    '--surface' => 'rgba(12, 12, 12, 0.94)',
                    '--surface-alt' => 'rgba(24, 24, 24, 0.86)',
                    '--surface-soft' => 'rgba(38, 38, 38, 0.6)',
                    '--surface-strong' => 'rgba(10, 10, 10, 0.96)',
                    '--panel' => 'rgba(12, 12, 12, 0.94)',
                    '--border' => 'rgba(255, 255, 255, 0.12)',
                    '--border-strong' => 'rgba(255, 255, 255, 0.32)',
                    '--primary' => '#f4f4f5',
                    '--primary-strong' => '#ffffff',
                    '--accent' => '#e5e7eb',
                    '--accent-soft' => 'rgba(229, 231, 235, 0.18)',
                    '--brand' => '#e5e7eb',
                    '--brand-strong' => '#f9fafb',
                    '--text' => '#f8fafc',
                    '--muted' => 'rgba(214, 214, 214, 0.78)',
                    '--muted-soft' => 'rgba(168, 168, 168, 0.72)',
                    '--danger' => '#ef4444',
                    '--warning' => '#facc15',
                    '--success' => '#4ade80',
                    '--shadow' => '0 40px 80px rgba(0, 0, 0, 0.65)',
                    '--line' => 'rgba(255, 255, 255, 0.12)',
                    '--ok' => '#4ade80',
                    '--warn' => '#f97316',
                ],
            ],
            'black_gray' => [
                'name' => 'Black & Gray',
                'category' => 'Colors',
                'description' => 'Charcoal gradients with smoky highlights.',
                'preview' => ['#080b10', '#1f2937', '#4b5563'],
                'variables' => [
                    '--bg' => '#080b10',
                    '--bg-alt' => '#0e141d',
                    '--surface' => 'rgba(15, 23, 42, 0.94)',
                    '--surface-alt' => 'rgba(30, 41, 59, 0.82)',
                    '--surface-soft' => 'rgba(51, 65, 85, 0.55)',
                    '--surface-strong' => 'rgba(13, 19, 32, 0.95)',
                    '--panel' => 'rgba(17, 24, 39, 0.92)',
                    '--border' => 'rgba(148, 163, 184, 0.22)',
                    '--border-strong' => 'rgba(99, 102, 241, 0.35)',
                    '--primary' => '#a5b4fc',
                    '--primary-strong' => '#6366f1',
                    '--accent' => '#818cf8',
                    '--accent-soft' => 'rgba(129, 140, 248, 0.18)',
                    '--brand' => '#818cf8',
                    '--brand-strong' => '#6366f1',
                    '--text' => '#f9fafb',
                    '--muted' => 'rgba(203, 213, 225, 0.82)',
                    '--muted-soft' => 'rgba(148, 163, 184, 0.72)',
                    '--danger' => '#f87171',
                    '--warning' => '#fbbf24',
                    '--success' => '#34d399',
                    '--shadow' => '0 34px 70px rgba(2, 6, 23, 0.6)',
                    '--line' => 'rgba(148, 163, 184, 0.22)',
                    '--ok' => '#34d399',
                    '--warn' => '#f97316',
                ],
            ],
            'black_blue' => [
                'name' => 'Black & Blue',
                'category' => 'Colors',
                'description' => 'Deep midnight blues with electric edges.',
                'preview' => ['#030712', '#0f172a', '#38bdf8'],
                'variables' => [
                    '--bg' => '#030712',
                    '--bg-alt' => '#050b1b',
                    '--surface' => 'rgba(7, 13, 28, 0.94)',
                    '--surface-alt' => 'rgba(12, 22, 45, 0.84)',
                    '--surface-soft' => 'rgba(16, 36, 66, 0.55)',
                    '--surface-strong' => 'rgba(7, 16, 32, 0.96)',
                    '--panel' => 'rgba(10, 21, 44, 0.9)',
                    '--border' => 'rgba(56, 189, 248, 0.26)',
                    '--border-strong' => 'rgba(14, 165, 233, 0.45)',
                    '--primary' => '#38bdf8',
                    '--primary-strong' => '#0ea5e9',
                    '--accent' => '#60a5fa',
                    '--accent-soft' => 'rgba(96, 165, 250, 0.2)',
                    '--brand' => '#38bdf8',
                    '--brand-strong' => '#0ea5e9',
                    '--text' => '#eff6ff',
                    '--muted' => 'rgba(191, 219, 254, 0.84)',
                    '--muted-soft' => 'rgba(148, 163, 184, 0.72)',
                    '--danger' => '#f87171',
                    '--warning' => '#facc15',
                    '--success' => '#4ade80',
                    '--shadow' => '0 34px 76px rgba(2, 6, 23, 0.65)',
                    '--line' => 'rgba(56, 189, 248, 0.26)',
                    '--ok' => '#22c55e',
                    '--warn' => '#f97316',
                ],
            ],
            'blue_cyan' => [
                'name' => 'Blue & Cyan',
                'category' => 'Colors',
                'description' => 'Glacial blues and cyan auroras.',
                'preview' => ['#04111f', '#0369a1', '#0ea5e9'],
                'variables' => [
                    '--bg' => '#04111f',
                    '--bg-alt' => '#07192c',
                    '--surface' => 'rgba(8, 31, 53, 0.92)',
                    '--surface-alt' => 'rgba(12, 44, 72, 0.82)',
                    '--surface-soft' => 'rgba(15, 73, 110, 0.55)',
                    '--surface-strong' => 'rgba(6, 23, 40, 0.95)',
                    '--panel' => 'rgba(6, 27, 46, 0.9)',
                    '--border' => 'rgba(14, 165, 233, 0.25)',
                    '--border-strong' => 'rgba(6, 182, 212, 0.45)',
                    '--primary' => '#22d3ee',
                    '--primary-strong' => '#0891b2',
                    '--accent' => '#38bdf8',
                    '--accent-soft' => 'rgba(56, 189, 248, 0.2)',
                    '--brand' => '#38bdf8',
                    '--brand-strong' => '#06b6d4',
                    '--text' => '#f0f9ff',
                    '--muted' => 'rgba(191, 219, 254, 0.85)',
                    '--muted-soft' => 'rgba(148, 197, 233, 0.72)',
                    '--danger' => '#fb7185',
                    '--warning' => '#f59e0b',
                    '--success' => '#22c55e',
                    '--shadow' => '0 30px 72px rgba(3, 12, 24, 0.6)',
                    '--line' => 'rgba(14, 165, 233, 0.25)',
                    '--ok' => '#22c55e',
                    '--warn' => '#f59e0b',
                ],
            ],
            'blue_yellow' => [
                'name' => 'Blue & Yellow',
                'category' => 'Colors',
                'description' => 'Navy twilight with sunrise gold accents.',
                'preview' => ['#020817', '#0f172a', '#fbbf24'],
                'variables' => [
                    '--bg' => '#020817',
                    '--bg-alt' => '#061022',
                    '--surface' => 'rgba(9, 20, 40, 0.93)',
                    '--surface-alt' => 'rgba(15, 30, 52, 0.82)',
                    '--surface-soft' => 'rgba(33, 48, 76, 0.55)',
                    '--surface-strong' => 'rgba(8, 18, 35, 0.96)',
                    '--panel' => 'rgba(11, 23, 46, 0.92)',
                    '--border' => 'rgba(248, 196, 40, 0.22)',
                    '--border-strong' => 'rgba(234, 179, 8, 0.45)',
                    '--primary' => '#fde68a',
                    '--primary-strong' => '#fbbf24',
                    '--accent' => '#f59e0b',
                    '--accent-soft' => 'rgba(251, 191, 36, 0.25)',
                    '--brand' => '#fbbf24',
                    '--brand-strong' => '#f59e0b',
                    '--text' => '#fef9c3',
                    '--muted' => 'rgba(253, 224, 71, 0.85)',
                    '--muted-soft' => 'rgba(250, 204, 21, 0.72)',
                    '--danger' => '#f97316',
                    '--warning' => '#facc15',
                    '--success' => '#34d399',
                    '--shadow' => '0 32px 72px rgba(2, 8, 23, 0.6)',
                    '--line' => 'rgba(248, 196, 40, 0.22)',
                    '--ok' => '#34d399',
                    '--warn' => '#f59e0b',
                ],
            ],
            'violet_twilight' => [
                'name' => 'Violet Twilight',
                'category' => 'Colors',
                'description' => 'Violet haze with magenta glows.',
                'preview' => ['#120414', '#312e81', '#f472b6'],
                'variables' => [
                    '--bg' => '#120414',
                    '--bg-alt' => '#1a0930',
                    '--surface' => 'rgba(24, 7, 48, 0.94)',
                    '--surface-alt' => 'rgba(49, 28, 90, 0.82)',
                    '--surface-soft' => 'rgba(76, 29, 149, 0.55)',
                    '--surface-strong' => 'rgba(18, 6, 36, 0.96)',
                    '--panel' => 'rgba(35, 16, 70, 0.92)',
                    '--border' => 'rgba(217, 70, 239, 0.25)',
                    '--border-strong' => 'rgba(244, 114, 182, 0.45)',
                    '--primary' => '#c084fc',
                    '--primary-strong' => '#a855f7',
                    '--accent' => '#f472b6',
                    '--accent-soft' => 'rgba(244, 114, 182, 0.25)',
                    '--brand' => '#c084fc',
                    '--brand-strong' => '#a855f7',
                    '--text' => '#fdf4ff',
                    '--muted' => 'rgba(240, 171, 252, 0.85)',
                    '--muted-soft' => 'rgba(196, 181, 253, 0.72)',
                    '--danger' => '#fb7185',
                    '--warning' => '#facc15',
                    '--success' => '#4ade80',
                    '--shadow' => '0 36px 80px rgba(44, 5, 62, 0.65)',
                    '--line' => 'rgba(217, 70, 239, 0.25)',
                    '--ok' => '#4ade80',
                    '--warn' => '#f59e0b',
                ],
            ],
            'winter' => [
                'name' => 'Winter',
                'category' => 'Seasons',
                'description' => 'Frosted blues with snow-lit highlights.',
                'preview' => ['#071520', '#0c4a6e', '#e0f2fe'],
                'variables' => [
                    '--bg' => '#071520',
                    '--bg-alt' => '#0a1f32',
                    '--surface' => 'rgba(9, 32, 49, 0.94)',
                    '--surface-alt' => 'rgba(15, 52, 75, 0.82)',
                    '--surface-soft' => 'rgba(30, 64, 90, 0.55)',
                    '--surface-strong' => 'rgba(7, 24, 40, 0.96)',
                    '--panel' => 'rgba(11, 34, 51, 0.92)',
                    '--border' => 'rgba(148, 197, 233, 0.3)',
                    '--border-strong' => 'rgba(191, 219, 254, 0.45)',
                    '--primary' => '#bae6fd',
                    '--primary-strong' => '#38bdf8',
                    '--accent' => '#0ea5e9',
                    '--accent-soft' => 'rgba(14, 165, 233, 0.25)',
                    '--brand' => '#38bdf8',
                    '--brand-strong' => '#0ea5e9',
                    '--text' => '#e0f2fe',
                    '--muted' => 'rgba(148, 197, 233, 0.82)',
                    '--muted-soft' => 'rgba(125, 211, 252, 0.72)',
                    '--danger' => '#f87171',
                    '--warning' => '#facc15',
                    '--success' => '#4ade80',
                    '--shadow' => '0 36px 78px rgba(5, 18, 32, 0.62)',
                    '--line' => 'rgba(148, 197, 233, 0.3)',
                    '--ok' => '#22c55e',
                    '--warn' => '#f59e0b',
                ],
            ],
            'spring' => [
                'name' => 'Spring',
                'category' => 'Seasons',
                'description' => 'Soft greens and cherry blossoms.',
                'preview' => ['#05140f', '#22c55e', '#fbcfe8'],
                'variables' => [
                    '--bg' => '#05140f',
                    '--bg-alt' => '#0a241b',
                    '--surface' => 'rgba(10, 31, 24, 0.92)',
                    '--surface-alt' => 'rgba(22, 63, 48, 0.8)',
                    '--surface-soft' => 'rgba(45, 106, 79, 0.55)',
                    '--surface-strong' => 'rgba(9, 26, 20, 0.95)',
                    '--panel' => 'rgba(14, 40, 32, 0.9)',
                    '--border' => 'rgba(34, 197, 94, 0.28)',
                    '--border-strong' => 'rgba(74, 222, 128, 0.45)',
                    '--primary' => '#bef264',
                    '--primary-strong' => '#22c55e',
                    '--accent' => '#f9a8d4',
                    '--accent-soft' => 'rgba(249, 168, 212, 0.25)',
                    '--brand' => '#4ade80',
                    '--brand-strong' => '#22c55e',
                    '--text' => '#ecfdf5',
                    '--muted' => 'rgba(167, 243, 208, 0.82)',
                    '--muted-soft' => 'rgba(74, 222, 128, 0.65)',
                    '--danger' => '#fb7185',
                    '--warning' => '#fbbf24',
                    '--success' => '#22c55e',
                    '--shadow' => '0 34px 74px rgba(5, 18, 14, 0.6)',
                    '--line' => 'rgba(34, 197, 94, 0.28)',
                    '--ok' => '#22c55e',
                    '--warn' => '#fbbf24',
                ],
            ],
            'summer' => [
                'name' => 'Summer',
                'category' => 'Seasons',
                'description' => 'Tropical corals and ocean teal.',
                'preview' => ['#0b1a1f', '#0d9488', '#fb7185'],
                'variables' => [
                    '--bg' => '#0b1a1f',
                    '--bg-alt' => '#10242b',
                    '--surface' => 'rgba(12, 36, 42, 0.92)',
                    '--surface-alt' => 'rgba(20, 56, 62, 0.82)',
                    '--surface-soft' => 'rgba(34, 94, 103, 0.55)',
                    '--surface-strong' => 'rgba(10, 28, 34, 0.95)',
                    '--panel' => 'rgba(12, 42, 48, 0.92)',
                    '--border' => 'rgba(13, 148, 136, 0.28)',
                    '--border-strong' => 'rgba(45, 212, 191, 0.45)',
                    '--primary' => '#f97316',
                    '--primary-strong' => '#fb7185',
                    '--accent' => '#22d3ee',
                    '--accent-soft' => 'rgba(34, 211, 238, 0.25)',
                    '--brand' => '#2dd4bf',
                    '--brand-strong' => '#0d9488',
                    '--text' => '#f0fdfa',
                    '--muted' => 'rgba(128, 222, 208, 0.82)',
                    '--muted-soft' => 'rgba(45, 212, 191, 0.65)',
                    '--danger' => '#fb7185',
                    '--warning' => '#fbbf24',
                    '--success' => '#22c55e',
                    '--shadow' => '0 32px 74px rgba(5, 18, 20, 0.6)',
                    '--line' => 'rgba(13, 148, 136, 0.28)',
                    '--ok' => '#22c55e',
                    '--warn' => '#f97316',
                ],
            ],
            'fall' => [
                'name' => 'Fall',
                'category' => 'Seasons',
                'description' => 'Harvest oranges with ember embers.',
                'preview' => ['#140805', '#9a3412', '#fde68a'],
                'variables' => [
                    '--bg' => '#140805',
                    '--bg-alt' => '#1f0f07',
                    '--surface' => 'rgba(31, 15, 7, 0.94)',
                    '--surface-alt' => 'rgba(64, 28, 12, 0.82)',
                    '--surface-soft' => 'rgba(124, 45, 18, 0.55)',
                    '--surface-strong' => 'rgba(24, 11, 6, 0.96)',
                    '--panel' => 'rgba(40, 20, 10, 0.92)',
                    '--border' => 'rgba(234, 179, 8, 0.32)',
                    '--border-strong' => 'rgba(249, 115, 22, 0.45)',
                    '--primary' => '#f97316',
                    '--primary-strong' => '#ea580c',
                    '--accent' => '#fde68a',
                    '--accent-soft' => 'rgba(253, 230, 138, 0.3)',
                    '--brand' => '#f97316',
                    '--brand-strong' => '#ea580c',
                    '--text' => '#fff7ed',
                    '--muted' => 'rgba(253, 186, 116, 0.82)',
                    '--muted-soft' => 'rgba(251, 146, 60, 0.72)',
                    '--danger' => '#fb7185',
                    '--warning' => '#facc15',
                    '--success' => '#22c55e',
                    '--shadow' => '0 34px 78px rgba(20, 8, 5, 0.65)',
                    '--line' => 'rgba(234, 179, 8, 0.32)',
                    '--ok' => '#22c55e',
                    '--warn' => '#f59e0b',
                ],
            ],
            'st_patricks' => [
                'name' => "St. Patrick's",
                'category' => 'Festive',
                'description' => 'Clover greens with gilded trim.',
                'preview' => ['#04120a', '#15803d', '#facc15'],
                'variables' => [
                    '--bg' => '#04120a',
                    '--bg-alt' => '#072012',
                    '--surface' => 'rgba(6, 32, 18, 0.94)',
                    '--surface-alt' => 'rgba(17, 63, 38, 0.82)',
                    '--surface-soft' => 'rgba(34, 94, 62, 0.55)',
                    '--surface-strong' => 'rgba(6, 24, 15, 0.96)',
                    '--panel' => 'rgba(10, 40, 24, 0.92)',
                    '--border' => 'rgba(34, 197, 94, 0.28)',
                    '--border-strong' => 'rgba(250, 204, 21, 0.45)',
                    '--primary' => '#22c55e',
                    '--primary-strong' => '#16a34a',
                    '--accent' => '#facc15',
                    '--accent-soft' => 'rgba(250, 204, 21, 0.25)',
                    '--brand' => '#22c55e',
                    '--brand-strong' => '#16a34a',
                    '--text' => '#ecfdf5',
                    '--muted' => 'rgba(187, 247, 208, 0.82)',
                    '--muted-soft' => 'rgba(34, 197, 94, 0.65)',
                    '--danger' => '#fb7185',
                    '--warning' => '#facc15',
                    '--success' => '#22c55e',
                    '--shadow' => '0 32px 74px rgba(4, 18, 10, 0.62)',
                    '--line' => 'rgba(34, 197, 94, 0.28)',
                    '--ok' => '#22c55e',
                    '--warn' => '#facc15',
                ],
            ],
            'usa' => [
                'name' => 'USA (4th of July)',
                'category' => 'Festive',
                'description' => 'Night-sky navy with patriotic red and white.',
                'preview' => ['#030712', '#1d4ed8', '#ef4444'],
                'variables' => [
                    '--bg' => '#030712',
                    '--bg-alt' => '#061227',
                    '--surface' => 'rgba(8, 20, 45, 0.94)',
                    '--surface-alt' => 'rgba(17, 40, 82, 0.82)',
                    '--surface-soft' => 'rgba(37, 99, 235, 0.4)',
                    '--surface-strong' => 'rgba(6, 16, 32, 0.96)',
                    '--panel' => 'rgba(9, 24, 52, 0.92)',
                    '--border' => 'rgba(96, 165, 250, 0.26)',
                    '--border-strong' => 'rgba(239, 68, 68, 0.45)',
                    '--primary' => '#ef4444',
                    '--primary-strong' => '#dc2626',
                    '--accent' => '#60a5fa',
                    '--accent-soft' => 'rgba(96, 165, 250, 0.25)',
                    '--brand' => '#60a5fa',
                    '--brand-strong' => '#3b82f6',
                    '--text' => '#f8fafc',
                    '--muted' => 'rgba(191, 219, 254, 0.82)',
                    '--muted-soft' => 'rgba(148, 163, 184, 0.72)',
                    '--danger' => '#f87171',
                    '--warning' => '#fbbf24',
                    '--success' => '#34d399',
                    '--shadow' => '0 36px 80px rgba(3, 7, 18, 0.65)',
                    '--line' => 'rgba(96, 165, 250, 0.26)',
                    '--ok' => '#34d399',
                    '--warn' => '#f97316',
                ],
            ],
            'halloween' => [
                'name' => 'Halloween',
                'category' => 'Festive',
                'description' => 'Wicked purple dusk with candy corn sparks.',
                'preview' => ['#0b0314', '#581c87', '#f97316'],
                'variables' => [
                    '--bg' => '#0b0314',
                    '--bg-alt' => '#15052b',
                    '--surface' => 'rgba(22, 5, 43, 0.94)',
                    '--surface-alt' => 'rgba(48, 18, 80, 0.82)',
                    '--surface-soft' => 'rgba(88, 28, 135, 0.55)',
                    '--surface-strong' => 'rgba(14, 4, 32, 0.96)',
                    '--panel' => 'rgba(32, 12, 60, 0.92)',
                    '--border' => 'rgba(147, 51, 234, 0.3)',
                    '--border-strong' => 'rgba(249, 115, 22, 0.45)',
                    '--primary' => '#f97316',
                    '--primary-strong' => '#ea580c',
                    '--accent' => '#a855f7',
                    '--accent-soft' => 'rgba(168, 85, 247, 0.25)',
                    '--brand' => '#c084fc',
                    '--brand-strong' => '#a855f7',
                    '--text' => '#fdf4ff',
                    '--muted' => 'rgba(221, 214, 254, 0.82)',
                    '--muted-soft' => 'rgba(196, 181, 253, 0.72)',
                    '--danger' => '#fb7185',
                    '--warning' => '#facc15',
                    '--success' => '#22c55e',
                    '--shadow' => '0 34px 78px rgba(16, 4, 32, 0.65)',
                    '--line' => 'rgba(147, 51, 234, 0.3)',
                    '--ok' => '#22c55e',
                    '--warn' => '#f97316',
                ],
            ],
            'thanksgiving' => [
                'name' => 'Thanksgiving',
                'category' => 'Festive',
                'description' => 'Buttery gold and cranberry warmth.',
                'preview' => ['#120705', '#92400e', '#fde68a'],
                'variables' => [
                    '--bg' => '#120705',
                    '--bg-alt' => '#1b0c07',
                    '--surface' => 'rgba(34, 13, 6, 0.94)',
                    '--surface-alt' => 'rgba(74, 29, 14, 0.82)',
                    '--surface-soft' => 'rgba(146, 64, 14, 0.55)',
                    '--surface-strong' => 'rgba(24, 10, 5, 0.96)',
                    '--panel' => 'rgba(45, 18, 9, 0.92)',
                    '--border' => 'rgba(250, 204, 21, 0.3)',
                    '--border-strong' => 'rgba(248, 113, 113, 0.38)',
                    '--primary' => '#fbbf24',
                    '--primary-strong' => '#f59e0b',
                    '--accent' => '#f97316',
                    '--accent-soft' => 'rgba(249, 115, 22, 0.3)',
                    '--brand' => '#f97316',
                    '--brand-strong' => '#ea580c',
                    '--text' => '#fffbeb',
                    '--muted' => 'rgba(254, 215, 170, 0.82)',
                    '--muted-soft' => 'rgba(253, 186, 116, 0.72)',
                    '--danger' => '#fb7185',
                    '--warning' => '#facc15',
                    '--success' => '#22c55e',
                    '--shadow' => '0 34px 76px rgba(18, 6, 4, 0.65)',
                    '--line' => 'rgba(250, 204, 21, 0.3)',
                    '--ok' => '#22c55e',
                    '--warn' => '#f59e0b',
                ],
            ],
            'christmas' => [
                'name' => 'Christmas',
                'category' => 'Festive',
                'description' => 'Evergreen spruce with crimson ribbons.',
                'preview' => ['#04100a', '#14532d', '#dc2626'],
                'variables' => [
                    '--bg' => '#04100a',
                    '--bg-alt' => '#092015',
                    '--surface' => 'rgba(9, 39, 25, 0.94)',
                    '--surface-alt' => 'rgba(20, 72, 45, 0.82)',
                    '--surface-soft' => 'rgba(30, 102, 63, 0.55)',
                    '--surface-strong' => 'rgba(7, 30, 18, 0.96)',
                    '--panel' => 'rgba(12, 47, 30, 0.92)',
                    '--border' => 'rgba(76, 222, 128, 0.28)',
                    '--border-strong' => 'rgba(220, 38, 38, 0.45)',
                    '--primary' => '#dc2626',
                    '--primary-strong' => '#b91c1c',
                    '--accent' => '#22c55e',
                    '--accent-soft' => 'rgba(34, 197, 94, 0.25)',
                    '--brand' => '#22c55e',
                    '--brand-strong' => '#16a34a',
                    '--text' => '#f0fdf4',
                    '--muted' => 'rgba(187, 247, 208, 0.82)',
                    '--muted-soft' => 'rgba(74, 222, 128, 0.65)',
                    '--danger' => '#fb7185',
                    '--warning' => '#facc15',
                    '--success' => '#22c55e',
                    '--shadow' => '0 32px 74px rgba(4, 18, 10, 0.64)',
                    '--line' => 'rgba(76, 222, 128, 0.28)',
                    '--ok' => '#22c55e',
                    '--warn' => '#facc15',
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
