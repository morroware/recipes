<?php
// public_html/src/controllers/AiController.php
// Claude-powered AI endpoints. All endpoints expect JSON, require login + CSRF.

declare(strict_types=1);

class AiController {

    public function page(): void {
        $uid = require_login();
        render('chat/page.php', [
            'title'         => 'Kitchen brain',
            'active'        => 'chat',
            'memories'      => Memory::listForUser($uid, 80),
            'conversations' => Conversation::listForUser($uid, 50),
            'recent_cooks'  => CookingLog::recent($uid, 10),
        ]);
    }

    public function apiStatus(): void {
        $uid = require_login();
        $memCount = 0;
        try {
            $memCount = count(Memory::listForUser($uid));
        } catch (Throwable $e) {}
        json_ok([
            'enabled'      => ai_enabled(),
            'model'        => AI_DEFAULT_MODEL,
            'memory_count' => $memCount,
        ]);
    }

    // ---- Memories ----------------------------------------------------------

    public function apiMemoriesList(): void {
        $uid = require_login();
        json_ok(['memories' => Memory::listForUser($uid)]);
    }

    public function apiMemoriesCreate(): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        $fact = trim((string)($body['fact'] ?? ''));
        if ($fact === '') json_err('fact_required', 422);
        $row = Memory::add(
            $uid,
            $fact,
            (string)($body['category'] ?? 'other'),
            'user',
            isset($body['weight']) ? (int)$body['weight'] : 7,
            !empty($body['pinned'])
        );
        if (!$row) json_err('invalid', 422);
        json_ok(['memory' => $row]);
    }

    public function apiMemoriesUpdate(string $id): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        $row = Memory::update($uid, (int)$id, $body);
        if (!$row) json_err('not_found', 404);
        json_ok(['memory' => $row]);
    }

    public function apiMemoriesDelete(string $id): void {
        $uid = require_login();
        csrf_require();
        $ok = Memory::delete($uid, (int)$id);
        json_ok(['deleted' => $ok]);
    }

    public function apiMemoriesClear(): void {
        $uid = require_login();
        csrf_require();
        json_ok(['removed' => Memory::deleteAll($uid)]);
    }

    // ---- Conversations -----------------------------------------------------

    public function apiConvList(): void {
        $uid = require_login();
        json_ok(['conversations' => Conversation::listForUser($uid)]);
    }

    public function apiConvCreate(): void {
        $uid = require_login();
        csrf_require();
        $body  = self::readJson();
        $title = trim((string)($body['title'] ?? 'New conversation'));
        $conv  = Conversation::create($uid, $title);
        json_ok(['conversation' => $conv]);
    }

    public function apiConvShow(string $id): void {
        $uid = require_login();
        $conv = Conversation::findById($uid, (int)$id);
        if (!$conv) json_err('not_found', 404);
        $msgs = Conversation::messages((int)$id);
        json_ok(['conversation' => $conv, 'messages' => $msgs]);
    }

    public function apiConvRename(string $id): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        $conv = Conversation::rename($uid, (int)$id, (string)($body['title'] ?? ''));
        if (!$conv) json_err('not_found', 404);
        json_ok(['conversation' => $conv]);
    }

    public function apiConvDelete(string $id): void {
        $uid = require_login();
        csrf_require();
        $ok = Conversation::delete($uid, (int)$id);
        json_ok(['deleted' => $ok]);
    }

    // ---- Cooking log -------------------------------------------------------

    public function apiCookingList(): void {
        $uid = require_login();
        json_ok([
            'recent'     => CookingLog::recent($uid, 30),
            'highlights' => CookingLog::highlights($uid, 8),
        ]);
    }

    public function apiCookingCreate(): void {
        $uid = require_login();
        csrf_require();
        $body = self::readJson();
        $title = trim((string)($body['recipe_title'] ?? ''));
        $rid   = isset($body['recipe_id']) && $body['recipe_id'] !== '' ? (int)$body['recipe_id'] : null;
        if ($title === '' && !$rid) json_err('recipe_required', 422);
        $row = CookingLog::add(
            $uid,
            $rid,
            $title,
            isset($body['rating']) && $body['rating'] !== '' ? (int)$body['rating'] : null,
            isset($body['notes']) ? (string)$body['notes'] : null
        );
        json_ok(['entry' => $row]);
    }

    public function apiCookingDelete(string $id): void {
        $uid = require_login();
        csrf_require();
        $ok = CookingLog::delete($uid, (int)$id);
        json_ok(['deleted' => $ok]);
    }

    // ---- Bulk ingredient parser -------------------------------------------

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

    // ---- Recipe parser -----------------------------------------------------

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

    // ---- Recipe suggestions -----------------------------------------------

    public function apiRecipeSuggestions(): void {
        $uid = require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $mode = (string)($body['mode'] ?? 'pantry');
        $note = trim((string)($body['note'] ?? ''));

        $context = ai_full_context($uid);

        $modeHint = match ($mode) {
            'new'       => 'Suggest fresh, NEW recipe ideas the user has never made (avoid titles already in their library or cooking history). Variety: try cuisines they don\'t have yet.',
            'weeknight' => 'Suggest fast 30-minute weeknight dinners using mostly in-stock items.',
            'favorite' => 'Suggest meals similar to their highest-rated cooked dishes — riff on flavors and techniques they already love.',
            default     => 'Suggest meals that use mostly the in-stock pantry items. List up to 3 missing ingredients per recipe if needed.',
        };

        $system = "You are a culinary brain for a personal cookbook app. "
                . "Strictly respect the user's remembered preferences (diet, allergies, dislikes).\n\n"
                . $context . "\n\n"
                . "When asked for suggestions, respond ONLY with JSON of shape:\n"
                . "  {\"suggestions\": [{title, cuisine, glyph, time_minutes, difficulty, summary, missing_ingredients: [string], reason: string}]}\n"
                . "The `reason` should be ONE short sentence tying the idea to the user's known preferences or pantry. "
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

    public function apiRecipeFromIdea(): void {
        $uid = require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $title = trim((string)($body['title'] ?? ''));
        $note  = trim((string)($body['note'] ?? ''));
        if ($title === '') json_err('title_required', 422);

        $aisles = implode(', ', AISLES);
        $context = ai_full_context($uid);
        $system = "You write reliable recipes for a home cook. "
                . "Strictly respect their dietary needs and allergies.\n\n"
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

    // ---- Chat (persistent + tool use + memory extraction) -----------------

    public function apiChat(): void {
        $uid = require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $message = trim((string)($body['message'] ?? ''));
        $page    = (string)($body['page'] ?? '');
        $convId  = isset($body['conversation_id']) && $body['conversation_id'] !== ''
            ? (int)$body['conversation_id'] : null;
        if ($message === '') json_err('message_required', 422);
        if (mb_strlen($message) > 6000) $message = mb_substr($message, 0, 6000);

        // Resolve / create conversation.
        if ($convId) {
            $conv = Conversation::findById($uid, $convId);
            if (!$conv) json_err('not_found', 404);
        } else {
            $conv = Conversation::create($uid, mb_substr($message, 0, 60));
            $convId = (int)$conv['id'];
        }

        // Save user message.
        Conversation::addMessage($convId, 'user', $message);

        // Build messages list for the model from the conversation history.
        $history = Conversation::messages($convId, 30);
        $apiMessages = [];
        foreach ($history as $m) {
            if (!in_array($m['role'], ['user', 'assistant'], true)) continue;
            $apiMessages[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        $context = ai_full_context($uid);
        $allowedCats = implode(', ', PANTRY_CATEGORIES);
        $system  = "You are Sprout, the cheerful kitchen sidekick inside a personal recipe + pantry app. "
                 . "Your whole world is food: recipes, ingredients, cooking technique, pantry stocking, meal planning, shopping, and the occasional taste-bud pep talk. "
                 . "You have persistent memory of the user's preferences and cooking history.\n\n"
                 . $context . "\n\n"
                 . "Personality:\n"
                 . "- Warm, upbeat, playful. Use the occasional food emoji (🥕✨🍳) — don't overdo it.\n"
                 . "- Talk like a friendly cook, not a corporate bot. Short sentences. Bullet lists for options.\n"
                 . "- Celebrate small wins (\"ooh, great pantry haul!\"), but never sycophantic.\n\n"
                 . "Strict topic rules:\n"
                 . "- ONLY help with food, cooking, ingredients, recipes, pantry, meal planning, shopping lists, kitchen equipment, and cooking history.\n"
                 . "- If asked about anything off-topic (politics, coding, general trivia, personal advice unrelated to food, etc.), cheerfully redirect: acknowledge briefly, then steer back to the kitchen with a concrete suggestion (\"that's outside my apron — but speaking of dinner, want me to find something using your pantry?\"). Do NOT answer the off-topic question.\n\n"
                 . "Tool behaviour:\n"
                 . "- When you learn a stable preference (diet, allergy, dislike, equipment, schedule, household), call `remember_preference`. Don't ask permission — just remember it. Skip transient state like \"wants tacos tonight\".\n"
                 . "- When the user explicitly tells you to forget something, call `forget_preference` with the matching memory id.\n"
                 . "- When the user pastes a list, recipe, fridge dump, grocery haul, or photo description and wants it stocked: (1) call `bulk_add_to_pantry` with confirm=false to preview the cleaned items. Strip out instructions/headers/prose — keep ONLY ingredient names. Normalise to lowercase singular (\"yellow onion\", \"olive oil\"). Assign each a category from: $allowedCats. Default in_stock=true unless they say otherwise. (2) Show the user the parsed list as a friendly bullet list and ask them to confirm (\"want me to add these? 🥕\"). (3) ONLY after they say yes, call the tool again with the SAME items and confirm=true. If they want to tweak the list first, parse their edits and re-preview before committing.\n"
                 . "- When they ask to add to shopping or set a meal-plan day, use the matching tool.\n"
                 . "- When they say they cooked / made / tried a dish, call `log_cooked_recipe`.\n"
                 . "- Prefer recipes already in the library when relevant.\n"
                 . ($page ? "- The user is on the '$page' page; gear advice toward that view.\n" : '');

        $tools = ai_chat_tools();

        // Tool-use loop. Allow up to 4 hops so the model can save a memory,
        // add a shopping item, etc., before producing the final reply.
        $totalUsage = ['input_tokens' => 0, 'output_tokens' => 0];
        $actions = [];
        $finalText = '';

        for ($hop = 0; $hop < 5; $hop++) {
            try {
                $resp = ai_call($apiMessages, [
                    'system' => $system,
                    'tools'  => $tools,
                    'max_tokens' => 1400,
                    'temperature' => 0.6,
                ]);
            } catch (AiException $e) {
                Conversation::addMessage($convId, 'assistant', '⚠️ ' . $e->getMessage());
                json_err($e->getMessage(), 502);
            }

            $u = ai_usage($resp);
            $totalUsage['input_tokens']  += $u['input_tokens'];
            $totalUsage['output_tokens'] += $u['output_tokens'];

            $stop = $resp['stop_reason'] ?? '';
            $toolUses = ai_tool_uses($resp);

            // Always preserve the assistant turn so tool_result blocks line up.
            $apiMessages[] = ['role' => 'assistant', 'content' => $resp['content'] ?? []];

            if ($stop !== 'tool_use' || !$toolUses) {
                $finalText = ai_text($resp);
                break;
            }

            // Execute tools and feed results back.
            $resultBlocks = [];
            foreach ($toolUses as $tu) {
                $name  = (string)($tu['name'] ?? '');
                $input = is_array($tu['input'] ?? null) ? $tu['input'] : [];
                $resultPayload = $this->executeTool($uid, $name, $input);
                $actions[] = ['tool' => $name, 'input' => $input, 'result' => $resultPayload];
                $resultBlocks[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $tu['id'] ?? '',
                    'content'     => json_encode($resultPayload, JSON_UNESCAPED_UNICODE),
                ];
            }
            $apiMessages[] = ['role' => 'user', 'content' => $resultBlocks];
        }

        if ($finalText === '') $finalText = '(no reply)';
        Conversation::addMessage($convId, 'assistant', $finalText, $totalUsage['input_tokens'], $totalUsage['output_tokens']);
        Conversation::touch($uid, $convId);

        json_ok([
            'reply'           => $finalText,
            'conversation_id' => $convId,
            'actions'         => $actions,
            'usage'           => $totalUsage,
        ]);
    }

    /** Execute a tool the chat model requested. Always returns a JSON-serialisable array. */
    private function executeTool(int $uid, string $name, array $input): array {
        try {
            switch ($name) {
                case 'remember_preference':
                    $row = Memory::add(
                        $uid,
                        (string)($input['fact'] ?? ''),
                        (string)($input['category'] ?? 'other'),
                        'assistant',
                        isset($input['weight']) ? (int)$input['weight'] : 6
                    );
                    return $row
                        ? ['ok' => true, 'memory_id' => (int)$row['id'], 'fact' => $row['fact']]
                        : ['ok' => false, 'error' => 'invalid_fact'];

                case 'forget_preference':
                    $ok = Memory::delete($uid, (int)($input['id'] ?? 0));
                    return ['ok' => $ok];

                case 'add_to_shopping_list':
                    $row = Shopping::add($uid, [
                        'name' => (string)($input['name'] ?? ''),
                        'qty'  => $input['qty']  ?? null,
                        'unit' => $input['unit'] ?? '',
                        'source_label' => 'assistant',
                    ]);
                    return ['ok' => true, 'shopping_id' => (int)$row['id']];

                case 'bulk_add_to_pantry':
                    $items   = is_array($input['items'] ?? null) ? $input['items'] : [];
                    $confirm = !empty($input['confirm']);
                    $clean = [];
                    foreach ($items as $it) {
                        if (!is_array($it)) continue;
                        $name = trim((string)($it['name'] ?? ''));
                        if ($name === '') continue;
                        $cat = (string)($it['category'] ?? '');
                        if (!in_array($cat, PANTRY_CATEGORIES, true)) {
                            $cat = pantry_categorize($name);
                        }
                        $clean[] = [
                            'name'     => mb_substr($name, 0, 128),
                            'qty'      => isset($it['qty']) && $it['qty'] !== '' ? (string)$it['qty'] : null,
                            'unit'     => mb_substr((string)($it['unit'] ?? ''), 0, 16),
                            'category' => $cat,
                            'in_stock' => array_key_exists('in_stock', $it) ? (bool)$it['in_stock'] : true,
                        ];
                    }
                    if (!$confirm) {
                        return [
                            'ok'           => true,
                            'preview'      => true,
                            'preview_count'=> count($clean),
                            'items'        => $clean,
                            'note'         => 'Preview only — nothing was written. Show the user the parsed list and ask them to confirm before calling this tool again with confirm=true.',
                        ];
                    }
                    $added  = [];
                    $failed = [];
                    foreach ($clean as $it) {
                        try {
                            $row = Pantry::addOrUpdate($uid, $it['name'], [
                                'in_stock' => $it['in_stock'],
                                'category' => $it['category'],
                                'qty'      => $it['qty'],
                                'unit'     => $it['unit'],
                            ]);
                            $added[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'category' => $row['category']];
                        } catch (Throwable $e) {
                            $failed[] = ['name' => $it['name'], 'error' => $e->getMessage()];
                        }
                    }
                    return [
                        'ok'           => true,
                        'committed'    => true,
                        'added_count'  => count($added),
                        'failed_count' => count($failed),
                        'added'        => $added,
                        'failed'       => $failed,
                    ];

                case 'set_meal_plan_day':
                    $day = (string)($input['day'] ?? '');
                    $rid = (int)($input['recipe_id'] ?? 0);
                    if (!in_array($day, PLAN_DAYS, true)) return ['ok' => false, 'error' => 'bad_day'];
                    $r = Recipe::findById($uid, $rid);
                    if (!$r) return ['ok' => false, 'error' => 'recipe_not_found'];
                    Plan::setDay($uid, $day, $rid);
                    return ['ok' => true, 'day' => $day, 'recipe_id' => $rid];

                case 'log_cooked_recipe':
                    $row = CookingLog::add(
                        $uid,
                        isset($input['recipe_id']) && $input['recipe_id'] !== '' ? (int)$input['recipe_id'] : null,
                        (string)($input['recipe_title'] ?? ''),
                        isset($input['rating']) ? (int)$input['rating'] : null,
                        isset($input['notes']) ? (string)$input['notes'] : null
                    );
                    return ['ok' => true, 'log_id' => (int)$row['id']];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        return ['ok' => false, 'error' => 'unknown_tool'];
    }

    // ---- Categorize (single ingredient) -----------------------------------

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

    // ---- Plan a week ------------------------------------------------------

    public function apiPlanWeek(): void {
        $uid = require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $note = trim((string)($body['note'] ?? ''));
        $days = $body['days'] ?? PLAN_DAYS;
        if (!is_array($days) || !$days) $days = PLAN_DAYS;
        $days = array_values(array_intersect($days, PLAN_DAYS));

        $context = ai_full_context($uid);
        $daysList = implode(', ', $days);
        $system = "You build weekly meal plans for a home cook. "
                . "Strictly respect their dietary needs and allergies.\n\n"
                . $context . "\n\n"
                . "Choose ONE meal for each requested day. Prefer recipes already in the "
                . "user's library when the title matches; otherwise propose a NEW idea. "
                . "Output ONLY JSON: {plan: {Mon: {title, summary, from_library (bool), glyph, reason}, ...}} "
                . "Each `reason` is one short sentence tying the choice to a known preference or pantry item.";

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

    // ---- Memory extraction (analyse a conversation) -----------------------

    /**
     * POST /api/ai/extract-memories
     * Body: { conversation_id?: int, text?: string }
     * Pulls durable preference facts out of the recent conversation (or a
     * pasted text block) and stores them as ai_memories rows. Returns the
     * list of memories added.
     */
    public function apiExtractMemories(): void {
        $uid = require_login();
        csrf_require();
        if (!ai_enabled()) json_err('ai_disabled', 503);

        $body = self::readJson();
        $convId = isset($body['conversation_id']) && $body['conversation_id'] !== ''
            ? (int)$body['conversation_id'] : null;
        $text = trim((string)($body['text'] ?? ''));

        if ($convId) {
            $conv = Conversation::findById($uid, $convId);
            if (!$conv) json_err('not_found', 404);
            $msgs = Conversation::messages($convId, 40);
            $lines = [];
            foreach ($msgs as $m) {
                $lines[] = strtoupper($m['role']) . ': ' . $m['content'];
            }
            $text = implode("\n", $lines);
        }
        if ($text === '') json_err('text_required', 422);

        $cats = implode('|', Memory::CATEGORIES);
        $existing = Memory::listForUser($uid, 60);
        $existingFacts = array_map(fn($m) => $m['fact'], $existing);
        $existingBlock = $existingFacts
            ? "Already-known memories (do not duplicate):\n- " . implode("\n- ", array_slice($existingFacts, 0, 60))
            : 'No existing memories yet.';

        $system = "You extract durable user preferences from a conversation. "
                . "Output ONLY JSON of shape: {\"memories\": [{\"category\": \"$cats\", \"fact\": \"...\", \"weight\": 1-10}]}.\n"
                . "Rules:\n"
                . "- Only include STABLE preferences (diet, allergies, dislikes, household, equipment, skill, goals, schedule, favorite cuisines).\n"
                . "- Skip transient state ('wants pasta tonight').\n"
                . "- Each fact: short, durable statement, lowercase if natural.\n"
                . "- Weight 8–10 = strong (allergies, hard dietary rules); 4–7 = preference; 1–3 = mild.\n"
                . "- Return [] if there is nothing new.\n\n"
                . $existingBlock;

        try {
            $resp = ai_call(
                [['role' => 'user', 'content' => $text]],
                ['system' => $system, 'max_tokens' => 800, 'temperature' => 0.0]
            );
        } catch (AiException $e) {
            json_err($e->getMessage(), 502);
        }
        $parsed = ai_extract_json(ai_text($resp));
        $list = (is_array($parsed) && isset($parsed['memories']) && is_array($parsed['memories']))
            ? $parsed['memories'] : [];

        $added = [];
        foreach ($list as $m) {
            if (!is_array($m)) continue;
            $row = Memory::add(
                $uid,
                (string)($m['fact'] ?? ''),
                (string)($m['category'] ?? 'other'),
                'assistant',
                isset($m['weight']) ? (int)$m['weight'] : 5
            );
            if ($row) $added[] = $row;
        }
        json_ok(['added' => $added, 'count' => count($added)]);
    }

    private static function readJson(): array {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
