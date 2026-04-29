<?php
// public_html/src/lib/pantry_helpers.php
// Port of project/pantry-data.jsx: normalizeName(), categorize(), timeAgo()
// Same rule order, same first-match-wins semantics.

declare(strict_types=1);

function pantry_normalize(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^a-z0-9\s]/', '', $s) ?? '';
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return trim($s);
}

function pantry_category_rules(): array {
    static $rules = null;
    if ($rules !== null) return $rules;
    $rules = [
        ['Spices', ['salt','pepper','cumin','paprika','cinnamon','nutmeg','turmeric','curry','chili powder','chilli powder','oregano','thyme','rosemary','basil','bay leaf','cardamom','clove','coriander seed','mustard seed','fennel','sumac',"za'atar",'zaatar','gochugaru','sichuan pepper','star anise','allspice','saffron','vanilla','dried','spice','herbs de provence','garam masala','five spice','everything bagel']],
        ['Produce', ['onion','garlic','tomato','potato','carrot','celery','lemon','lime','orange','apple','banana','grape','berry','strawberry','blueberry','raspberry','spinach','kale','lettuce','arugula','cabbage','broccoli','cauliflower','cucumber','zucchini','squash','pumpkin','pepper','chili','jalapeno','cilantro','parsley','mint','basil','dill','scallion','green onion','leek','shallot','ginger','avocado','mushroom','eggplant','aubergine','pear','peach','plum','mango','pineapple','watermelon','melon','radish','beet','turnip','sweet potato','yam','corn','peas','green bean','asparagus','bok choy','chard','okra','fennel bulb','daikon','sprouts','herb']],
        ['Dairy', ['milk','cream','butter','yogurt','yoghurt','cheese','feta','mozzarella','parmesan','cheddar','ricotta','cream cheese','sour cream','cottage cheese','mascarpone','halloumi','paneer','goat cheese','brie','swiss','provolone','egg','eggs']],
        ['Meat & Fish', ['chicken','beef','pork','lamb','turkey','duck','bacon','sausage','ham','prosciutto','salami','steak','ground beef','mince','chop','rib','brisket','tenderloin','fish','salmon','tuna','cod','tilapia','halibut','trout','sardine','anchovy','shrimp','prawn','scallop','mussel','clam','oyster','lobster','crab','squid','octopus']],
        ['Bakery', ['bread','baguette','roll','bun','tortilla','pita','naan','focaccia','ciabatta','sourdough','bagel','english muffin','crumpet','pastry','croissant','cracker','breadcrumb']],
        ['Frozen', ['frozen','ice cream','sorbet','frozen pea','frozen berry']],
        ['Pantry', ['oil','olive oil','vegetable oil','sesame oil','vinegar','soy sauce','tamari','fish sauce','oyster sauce','hoisin','sriracha','ketchup','mustard','mayo','mayonnaise','tahini','miso','peanut butter','jam','jelly','honey','maple syrup','sugar','brown sugar','flour','rice','pasta','noodle','spaghetti','penne','linguine','ramen','udon','soba','quinoa','couscous','bulgur','farro','barley','oats','oatmeal','cornmeal','polenta','semolina','baking powder','baking soda','yeast','cornstarch','starch','stock','broth','bouillon','can','canned','tomato paste','tomato sauce','passata','coconut milk','chickpea','bean','black bean','kidney bean','lentil','split pea','tuna can','sardine can','olive','caper','pickle','sun-dried','nuts','almond','walnut','cashew','pistachio','pecan','peanut','seed','sesame','poppy','chia','flax','sunflower','pumpkin seed','raisin','date','dried fruit','chocolate','cocoa','tea','coffee','wine','sake','mirin','rice wine']],
    ];
    return $rules;
}

function pantry_categorize(string $name): string {
    $n = pantry_normalize($name);
    if ($n === '') return 'Other';
    foreach (pantry_category_rules() as [$cat, $keywords]) {
        foreach ($keywords as $kw) {
            if ($n === $kw || str_contains($n, $kw) || str_contains($kw, $n)) {
                return $cat;
            }
        }
    }
    return 'Other';
}

/** Returns true if an ingredient name is satisfied by an in-stock pantry item. */
function pantry_has_ingredient(array $inStockKeys, string $ingredientName): bool {
    $ing = pantry_normalize($ingredientName);
    if ($ing === '') return false;
    foreach ($inStockKeys as $k) {
        if ($k === '') continue;
        if ($k === $ing || str_contains($ing, $k) || str_contains($k, $ing)) {
            return true;
        }
    }
    return false;
}

/** Mirrors timeAgo() in pantry-data.jsx, given a SQL DATETIME or null. */
function pantry_time_ago(?string $datetime): ?string {
    if (!$datetime) return null;
    $ts = strtotime($datetime);
    if ($ts === false) return null;
    $days = (int) floor((time() - $ts) / 86400);
    if ($days < 1)   return 'today';
    if ($days === 1) return 'yesterday';
    if ($days < 7)   return $days . ' days ago';
    if ($days < 14)  return 'last week';
    if ($days < 30)  return floor($days / 7) . ' weeks ago';
    if ($days < 60)  return 'last month';
    if ($days < 365) return floor($days / 30) . ' months ago';
    return floor($days / 365) . ' years ago';
}
