<?php
// public_html/src/controllers/AiController.php
// Claude-powered AI endpoints. All endpoints expect JSON, require login + CSRF.

declare(strict_types=1);

class AiController {

    public function apiStatus(): void {
        require_login();
        json_ok([
            'enabled' => ai_enabled(),
            'model'   => AI_DEFAULT_MODEL,
        ]);
    }

    /**
     * POST /api/ai/parse-ingredients
     * Body: { text: "shopping list dump" }
     * Returns: { items: [{name, qty, unit, category, in_stock}], added: [...] }
     * If `commit: true`, also writes parsed items into the pantry.
     */
    public function apiParseIngredients(): void {
        $uid  = require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $text = trim((string)($body['text'] ?? ''));
        $commit = !empty($body['commit']);
        $defaultInStock = array_key_exists('in_stock', $body) ? (bool)$body['in_stock'] : true;

        if ($text === '') json_err('text_required', 422);
        if (mb_strlen($text) > 8000) json_err('text_too_long', 422);

        $allowedCats = implode(', ', PANTRY_CATEGORIES);
        $system = "You are an ingredient parser for a personal pantry app. "
                . "The user pastes a list, recipe, fridge dump, or photo description. "
                . "Extract distinct ingredient items and return them as JSON. "
                . "For each item: name (concise, lowercase, singular noun like \"olive oil\" or \"yellow onion\"), "
                . "qty (number or null), unit (short string or empty), category "
                . "(one of: $allowedCats). Deduplicate. Skip non-ingredient lines.\n"
                . "Respond ONLY with a single JSON object: {\"items\": [...]}";

        try {
            $resp = ai_call(
                [['role' => 'user', 'content' => $text]],
                ['system' => $system, 'max_tokens' => 1500, 'temperature' => 0.0]
            );
        } catch (AiException $e) {
            json_err($e->getMessage(), 502);
        }

        $parsed = ai_extract_json(ai_text($resp));
        $items  = (is_array($parsed) && isset($parsed['items']) && is_array($parsed['items']))
            ? $parsed['items']
            : [];

        $clean = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $name = trim((string)($it['name'] ?? ''));
            if ($name === '') continue;
            $cat = (string)($it['category'] ?? 'Other');
            if (!in_array($cat, PANTRY_CATEGORIES, true)) $cat = pantry_categorize($name);
            $clean[] = [
                'name'     => mb_substr($name, 0, 128),
                'qty'      => isset($it['qty']) && $it['qty'] !== '' ? (string)$it['qty'] : null,
                'unit'     => mb_substr((string)($it['unit'] ?? ''), 0, 16),
                'category' => $cat,
            ];
        }

        $added = [];
        if ($commit && $clean) {
            foreach ($clean as $it) {
                try {
                    $row = Pantry::addOrUpdate($uid, $it['name'], [
                        'in_stock' => $defaultInStock,
                        'category' => $it['category'],
                        'qty'      => $it['qty'],
                        'unit'     => $it['unit'],
                    ]);
                    $added[] = $row;
                } catch (Throwable $e) {
                    // skip bad item
                }
            }
        }

        json_ok([
            'items' => $clean,
            'added' => $added,
            'count' => count($clean),
            'usage' => $resp['usage'] ?? null,
        ]);
    }

    /**
     * POST /api/ai/parse-recipe
     * Body: { text: "raw recipe text or URL paste" }
     * Returns a fully-structured recipe payload ready for the editor.
     */
    public function apiParseRecipe(): void {
        require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $text = trim((string)($body['text'] ?? ''));
        if ($text === '') json_err('text_required', 422);
        if (mb_strlen($text) > 16000) json_err('text_too_long', 422);

        $aisles = implode(', ', AISLES);
        $system = "You convert messy recipe text into structured JSON for a recipe app. "
                . "Output ONLY a JSON object with these keys:\n"
                . "  title (string, <=160 chars)\n"
                . "  cuisine (short, e.g. Italian, Thai, '' if unknown)\n"
                . "  summary (one-line tagline, <=200 chars)\n"
                . "  time_minutes (integer, total active+waiting; 30 if unsure)\n"
                . "  servings (integer >=1; 2 if unsure)\n"
                . "  difficulty (one of: Easy, Medium, Hard)\n"
                . "  glyph (single emoji that fits the dish)\n"
                . "  color (one of: mint,butter,peach,lilac,sky,blush,lime,coral)\n"
                . "  tags (array of short lowercase tags, e.g. weeknight, pasta)\n"
                . "  ingredients: array of {qty (string|null), unit (string), name (string), aisle ($aisles)}\n"
                . "  steps: array of strings (each = one method step)\n"
                . "Always include all keys. Never include extra prose.";

        try {
            $resp = ai_call(
                [['role' => 'user', 'content' => $text]],
                ['system' => $system, 'max_tokens' => 3000, 'temperature' => 0.2]
            );
        } catch (AiException $e) {
            json_err($e->getMessage(), 502);
        }
        $parsed = ai_extract_json(ai_text($resp));
        if (!is_array($parsed)) json_err('parse_failed', 502);

        json_ok(['recipe' => $parsed, 'usage' => $resp['usage'] ?? null]);
    }

    /**
     * POST /api/ai/recipe-suggestions
     * Body: { mode?: "pantry"|"new"|"weeknight", note?: string }
     * Returns an array of NEW recipe ideas (not in the user's library) tailored
     * to the pantry / preferences.
     */
    public function apiRecipeSuggestions(): void {
        $uid = require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $mode = (string)($body['mode'] ?? 'pantry');
        $note = trim((string)($body['note'] ?? ''));

        $context = ai_kitchen_context($uid);

        $modeHint = match ($mode) {
            'new'       => 'Suggest fresh, NEW recipe ideas the user has never made (avoid titles already in their library). Variety: try cuisines they don\'t have yet.',
            'weeknight' => 'Suggest fast 30-minute weeknight dinners using mostly in-stock items.',
            default     => 'Suggest meals that use mostly the in-stock pantry items. List up to 3 missing ingredients per recipe if needed.',
        };

        $system = "You are a culinary brain for a personal cookbook app.\n"
                . $context . "\n\n"
                . "When asked for suggestions, respond ONLY with JSON of shape:\n"
                . "  {\"suggestions\": [{title, cuisine, glyph, time_minutes, difficulty, summary, missing_ingredients: [string]}]}\n"
                . "Provide 5–8 ideas. Glyph is a single emoji. Keep titles concise.";

        $userMsg = $modeHint;
        if ($note !== '') $userMsg .= "\n\nUser note: " . $note;

        try {
            $resp = ai_call(
                [['role' => 'user', 'content' => $userMsg]],
                ['system' => $system, 'max_tokens' => 1800, 'temperature' => 0.8]
            );
        } catch (AiException $e) {
            json_err($e->getMessage(), 502);
        }

        $parsed = ai_extract_json(ai_text($resp));
        $items = (is_array($parsed) && isset($parsed['suggestions']) && is_array($parsed['suggestions']))
            ? $parsed['suggestions'] : [];
        json_ok(['suggestions' => $items, 'usage' => $resp['usage'] ?? null]);
    }

    /**
     * POST /api/ai/recipe-from-idea
     * Body: { title: "idea title", note?: string }
     * Returns a fully-fleshed recipe payload ready to save (same shape as parse-recipe).
     */
    public function apiRecipeFromIdea(): void {
        $uid = require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $title = trim((string)($body['title'] ?? ''));
        $note  = trim((string)($body['note'] ?? ''));
        if ($title === '') json_err('title_required', 422);

        $aisles = implode(', ', AISLES);
        $context = ai_kitchen_context($uid);
        $system = "You write reliable recipes for a home cook.\n"
                . $context . "\n\n"
                . "Given a recipe idea, return the full recipe as JSON with keys: "
                . "title, cuisine, summary, time_minutes (int), servings (int), "
                . "difficulty (Easy|Medium|Hard), glyph (emoji), "
                . "color (mint|butter|peach|lilac|sky|blush|lime|coral), "
                . "tags (array), ingredients (array of {qty,unit,name,aisle in [$aisles]}), "
                . "steps (array of strings). Prefer ingredients the user already has in stock. "
                . "Respond ONLY with the JSON object.";

        $userMsg = $title;
        if ($note !== '') $userMsg .= "\n\nNotes: " . $note;

        try {
            $resp = ai_call(
                [['role' => 'user', 'content' => $userMsg]],
                ['system' => $system, 'max_tokens' => 3000, 'temperature' => 0.6]
            );
        } catch (AiException $e) {
            json_err($e->getMessage(), 502);
        }
        $parsed = ai_extract_json(ai_text($resp));
        if (!is_array($parsed)) json_err('parse_failed', 502);
        json_ok(['recipe' => $parsed, 'usage' => $resp['usage'] ?? null]);
    }

    /**
     * POST /api/ai/chat
     * Body: { messages: [{role, content}], page?: string }
     * General-purpose conversational endpoint. Knows the user's pantry + library.
     */
    public function apiChat(): void {
        $uid = require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $messages = $body['messages'] ?? [];
        $page = (string)($body['page'] ?? '');
        if (!is_array($messages) || !$messages) json_err('messages_required', 422);

        $clean = [];
        foreach ($messages as $m) {
            if (!is_array($m)) continue;
            $role = $m['role'] ?? '';
            $content = $m['content'] ?? '';
            if (!in_array($role, ['user', 'assistant'], true)) continue;
            if (!is_string($content)) continue;
            $content = mb_substr($content, 0, 4000);
            if ($content === '') continue;
            $clean[] = ['role' => $role, 'content' => $content];
        }
        if (!$clean) json_err('messages_required', 422);
        $clean = array_slice($clean, -20);

        $context = ai_kitchen_context($uid);
        $system  = "You are the in-app assistant for a personal recipe + pantry app.\n"
                 . $context . "\n\n"
                 . "Be concise, friendly, and practical. When the user asks what to cook, "
                 . "prefer recipes already in their library or things they can make from "
                 . "in-stock items. When they ask to add things, give a clear step-by-step. "
                 . "When listing recipes use a short bulleted format. "
                 . "If they're on the '$page' page, gear advice toward that view.";

        try {
            $resp = ai_call($clean, [
                'system' => $system,
                'max_tokens' => 1200,
                'temperature' => 0.6,
            ]);
        } catch (AiException $e) {
            json_err($e->getMessage(), 502);
        }
        json_ok([
            'reply' => ai_text($resp),
            'usage' => $resp['usage'] ?? null,
        ]);
    }

    /**
     * POST /api/ai/categorize
     * Body: { name: "..." }  → { category: "..." }
     */
    public function apiCategorize(): void {
        require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') json_err('name_required', 422);
        $allowedCats = implode(', ', PANTRY_CATEGORIES);

        try {
            $resp = ai_call(
                [['role' => 'user', 'content' => "Ingredient: $name"]],
                [
                    'system' => "Reply with exactly one word — the category, chosen from: $allowedCats. No punctuation, no prose.",
                    'max_tokens' => 12,
                    'temperature' => 0.0,
                ]
            );
        } catch (AiException $e) {
            json_err($e->getMessage(), 502);
        }
        $cat = trim(ai_text($resp));
        if (!in_array($cat, PANTRY_CATEGORIES, true)) {
            $cat = pantry_categorize($name);
        }
        json_ok(['category' => $cat]);
    }

    /**
     * POST /api/ai/plan-week
     * Body: { note?: string, days?: ["Mon",..] }
     * Picks recipes (from the user's library when possible) for each day of the week.
     */
    public function apiPlanWeek(): void {
        $uid = require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $note = trim((string)($body['note'] ?? ''));
        $days = $body['days'] ?? PLAN_DAYS;
        if (!is_array($days) || !$days) $days = PLAN_DAYS;
        $days = array_values(array_intersect($days, PLAN_DAYS));

        $context = ai_kitchen_context($uid);
        $daysList = implode(', ', $days);
        $system = "You build weekly meal plans for a home cook.\n"
                . $context . "\n\n"
                . "Choose ONE meal for each requested day. Prefer recipes already in the "
                . "user's library when the title matches; otherwise propose a NEW idea. "
                . "Output ONLY JSON: {plan: {Mon: {title, summary, from_library (bool), glyph}, ...}}";

        $userMsg = "Days: $daysList. Build a balanced week (vary cuisines, mix quick and slow).";
        if ($note !== '') $userMsg .= "\n\nUser note: $note";

        try {
            $resp = ai_call(
                [['role' => 'user', 'content' => $userMsg]],
                ['system' => $system, 'max_tokens' => 2000, 'temperature' => 0.7]
            );
        } catch (AiException $e) {
            json_err($e->getMessage(), 502);
        }
        $parsed = ai_extract_json(ai_text($resp));
        $plan = (is_array($parsed) && isset($parsed['plan']) && is_array($parsed['plan']))
            ? $parsed['plan'] : [];
        json_ok(['plan' => $plan, 'usage' => $resp['usage'] ?? null]);
    }

    private static function readJson(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
