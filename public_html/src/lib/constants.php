<?php
// Constants ported from project/data.jsx so PHP rendering matches the
// prototype palette / aisle vocabulary 1:1.

declare(strict_types=1);

/** Sticker color palette (recipe card backgrounds). bg + ink hex pairs. */
const STICKER_COLORS = [
    'mint'   => ['bg' => '#C8F0DC', 'ink' => '#0E5238'],
    'butter' => ['bg' => '#FFE9A8', 'ink' => '#5A4500'],
    'peach'  => ['bg' => '#FFCFB8', 'ink' => '#7A2E10'],
    'lilac'  => ['bg' => '#E2D2FF', 'ink' => '#3F1E84'],
    'sky'    => ['bg' => '#C7E6FF', 'ink' => '#0B3F73'],
    'blush'  => ['bg' => '#FFC9DC', 'ink' => '#7A1E48'],
    'lime'   => ['bg' => '#DDF3A2', 'ink' => '#3C5400'],
    'coral'  => ['bg' => '#FFB3B3', 'ink' => '#7A0E0E'],
];

/** Ingredient aisle vocabulary (matches the recipes.aisle ENUM). */
const AISLES = ['Produce', 'Pantry', 'Dairy', 'Meat & Fish', 'Bakery', 'Frozen', 'Spices', 'Other'];

/** Pantry category vocabulary (matches the pantry_items.category ENUM). */
const PANTRY_CATEGORIES = ['Produce', 'Dairy', 'Meat & Fish', 'Bakery', 'Pantry', 'Spices', 'Frozen', 'Other'];

const PANTRY_CATEGORY_GLYPHS = [
    'Produce'     => '🥕',
    'Dairy'       => '🧀',
    'Meat & Fish' => '🥩',
    'Bakery'      => '🍞',
    'Pantry'      => '🥫',
    'Spices'      => '🌶️',
    'Frozen'      => '🧊',
    'Other'       => '📦',
];

/** Day vocabulary for meal_plan.day. */
const PLAN_DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Weekday recipe-card pill colors for tag rotation (mirrors pages-a.jsx). */
const TAG_PILL_COLORS = ['pill-mint', 'pill-butter', 'pill-peach', 'pill-lilac', 'pill-sky', 'pill-blush'];


function app_base_path(): string {
    static $base = null;
    if ($base !== null) return $base;

    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    $dir = $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    return $base = $dir;
}

function url_for(string $path = '/'): string {
    $path = '/' . ltrim($path, '/');
    $base = app_base_path();
    return ($base === '' ? '' : $base) . $path;
}
