<?php
// public_html/src/models/Settings.php
// Tweaks / user_settings persistence. One row per user (PK = user_id).

declare(strict_types=1);

class Settings {

    /** Whitelist of allowed values per column, mirrors user_settings ENUMs. */
    public static function allowedValues(): array {
        return [
            'density'    => ['compact','cozy','airy'],
            'theme'      => ['rainbow','sunset','ocean','garden'],
            'mode'       => ['light','dark'],
            'font_pair'  => ['default','serif','mono','rounded'],
            'radius'     => ['sharp','default','round'],
            'card_style' => ['mix','photo-only','glyph-only'],
            'units'      => ['metric','imperial'],
        ];
    }

    public static function defaults(): array {
        return [
            'density'        => 'cozy',
            'theme'          => 'rainbow',
            'mode'           => 'light',
            'font_pair'      => 'default',
            'radius'         => 'default',
            'card_style'     => 'mix',
            'sticker_rotate' => 1,
            'dot_grid'       => 1,
            'units'          => 'metric',
        ];
    }

    public static function forUser(int $user_id): array {
        $stmt = db()->prepare(
            'SELECT density, theme, mode, font_pair, radius, card_style,
                    sticker_rotate, dot_grid, units
               FROM user_settings WHERE user_id = ?'
        );
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        if (!$row) return self::defaults();
        return [
            'density'        => $row['density'],
            'theme'          => $row['theme'],
            'mode'           => $row['mode'],
            'font_pair'      => $row['font_pair'],
            'radius'         => $row['radius'],
            'card_style'     => $row['card_style'],
            'sticker_rotate' => (int)$row['sticker_rotate'],
            'dot_grid'       => (int)$row['dot_grid'],
            'units'          => $row['units'],
        ];
    }

    /**
     * Update one or more settings. Unknown keys/values are silently ignored.
     * Returns the resulting full settings row.
     */
    public static function update(int $user_id, array $patch): array {
        $allowed = self::allowedValues();
        $current = self::forUser($user_id);
        foreach ($patch as $k => $v) {
            if (!array_key_exists($k, $current)) continue;
            if (in_array($k, ['sticker_rotate', 'dot_grid'], true)) {
                $current[$k] = ($v === true || $v === 1 || $v === '1' || $v === 'on') ? 1 : 0;
                continue;
            }
            if (isset($allowed[$k]) && in_array($v, $allowed[$k], true)) {
                $current[$k] = $v;
            }
        }

        // Ensure user_settings row exists, then UPDATE.
        $stmt = db()->prepare(
            'INSERT INTO user_settings
                (user_id, density, theme, mode, font_pair, radius, card_style,
                 sticker_rotate, dot_grid, units)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 density        = VALUES(density),
                 theme          = VALUES(theme),
                 mode           = VALUES(mode),
                 font_pair      = VALUES(font_pair),
                 radius         = VALUES(radius),
                 card_style     = VALUES(card_style),
                 sticker_rotate = VALUES(sticker_rotate),
                 dot_grid       = VALUES(dot_grid),
                 units          = VALUES(units)'
        );
        $stmt->execute([
            $user_id,
            $current['density'], $current['theme'], $current['mode'],
            $current['font_pair'], $current['radius'], $current['card_style'],
            $current['sticker_rotate'], $current['dot_grid'], $current['units'],
        ]);
        return $current;
    }
}
