<?php
/**
 * News processor lists (KOP Tools > Dropdown Lists): article types, content
 * warnings and the tags the AI is told to prefer. No WordPress needed: the
 * News Processor's AI read (api/process-news-ai.php) and its save
 * (api/save-news-submission.php) run without it.
 *
 * news_submissions.article_type is a MySQL enum: a type added on the screen
 * is added to the column too (kop_news_article_type_column_sync(), on every
 * save of the lists and before a save that uses a type the column lacks).
 */

kop_choice_register('news-article-types', array(
    'label'    => 'Article types',
    'group'    => 'News processor',
    'where'    => 'The "Article type" choice in the News Processor, the browser extension\'s News Processor and the article editors in Submissions Review and the review inbox, the types the AI may pick when it reads an article, and the names on the type badges and filters of the news pages.',
    'note'     => 'The News Processor has extra boxes (plaintiffs, charges, closure date...) only for the built-in types lawsuit, arrest, closure and corporate; a new type gets the common fields only.',
    'js'       => true,
    'last'     => array('general'),
    'defaults' => array(
        'lawsuit'   => 'Lawsuit Article',
        'event'     => 'Specific Event Article',
        'expose'    => 'Exposé or Survivor Account',
        'arrest'    => 'Staff Arrest Article',
        'closure'   => 'Facility Closure Article',
        'corporate' => 'Corporate Change Article',
        'general'   => 'General News Article',
    ),
));

kop_choice_register('news-content-warnings', array(
    'label'    => 'Content warnings',
    'group'    => 'News processor',
    'where'    => 'The content warning boxes in the News Processor, the browser extension\'s News Processor and the article editor in Submissions Review, and the warnings the AI may pick when it reads an article.',
    'plain'    => true,
    'js'       => true,
    'last'     => array(),
    'defaults' => array(
        'Physical Restraint', 'Chemical Restraint', 'Seclusion', 'Humiliating Punishments',
        'Child Sexual Abuse', 'Child-on-Child CSA', 'Graphic Descriptions of Assaults or Injuries',
        'Peer Violence', 'Child Death', 'Suicide', 'Self-harm', 'Substance Abuse',
        'Victim Blaming', 'Spiritual Abuse', 'Racism', 'Homophobia', 'Transphobia',
        'Hate Crimes', 'Slurs', 'Autism-Specific Abuse', 'Food Restriction',
        'Unsanitary Conditions', 'Medical Neglect', 'Eating Disorders', 'Conversion Therapy',
        'Forced Labor', 'Involuntary Transport', 'Law Enforcement Abuse',
    ),
));

kop_choice_register('news-tags', array(
    'label'    => 'Preferred news tags',
    'group'    => 'News processor',
    'where'    => 'The topic tags the AI is told to use, word for word, when it reads an article in the News Processor, the browser extension or the hourly fill of bare news links. Articles can still carry other tags; this list only steers the AI.',
    'note'     => 'The AI is told to prefer these exact tags and to make a new one only when none fits. Merging two spellings of one tag ("youth detention" into Juvenile Justice) and the generic tags hidden from the news filters are set in the code (api/news-tags.php), not here.',
    'plain'    => true,
    'last'     => array(),
    'defaults' => array(
        'Wilderness Therapy', 'Juvenile Justice', 'Transport', 'Forced Labor', 'Foster Care',
        'Group Home', 'Child Protective Services', 'Family Law',
        'Faith-Based Programs', 'Conversion Therapy', 'Human Trafficking', 'Kidnapping',
        'Death', 'Suicide', 'Self-Harm', 'Escape', 'Riot', 'Arrest', 'Trial', 'Settlement',
        'Closure', 'Licensing', 'Oversight', 'Legislation', 'Corporate Governance',
        'Staff Misconduct', 'Sexual Misconduct', 'Safety Violations', 'Survivor Stories',
        'Documentary', 'Adoption', 'Immigration', 'Disability Rights', 'LGBTQ+ Rights',
        'Private Schools', 'Private Prisons', 'Medicaid',
    ),
));

if (!function_exists('kop_news_article_types')) {
    /** value => label. 'all' (checking a stored or saved type) or 'offered' (dropdowns, the AI). */
    function kop_news_article_types($mode = 'all') {
        $out = array();
        foreach (kop_choices('news-article-types', null, $mode) as $value => $c) {
            $out[(string) $value] = kop_choice_label($c);
        }
        return $out;
    }
}

if (!function_exists('kop_news_article_type_values')) {
    function kop_news_article_type_values($mode = 'all') {
        return array_map('strval', array_keys(kop_news_article_types($mode)));
    }
}

if (!function_exists('kop_news_article_type_label')) {
    /** The label of a stored type; a value the list does not know reads as itself, capitalised. */
    function kop_news_article_type_label($value) {
        $value = (string) $value;
        $types = kop_news_article_types('all');
        return $types[$value] ?? ucfirst($value);
    }
}

if (!function_exists('kop_news_content_warnings')) {
    function kop_news_content_warnings($mode = 'offered') {
        return kop_choices('news-content-warnings', null, $mode);
    }
}

if (!function_exists('kop_news_article_type_column_sync')) {
    /**
     * Adds the list's types (hidden ones too) to the news_submissions.article_type
     * enum when it lacks any. MySQL only; anything else is left alone.
     */
    function kop_news_article_type_column_sync($pdo = null) {
        if (!$pdo && function_exists('kop_seed_pdo')) {
            try { $pdo = kop_seed_pdo(); } catch (Throwable $e) { $pdo = null; }
        }
        if (!$pdo instanceof PDO) return false;
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return false;
            // The column becomes VARCHAR(40) (inc/choice-lists/statuses.php), which takes any type:
            // nothing to add after that. The enum code below is only the fallback.
            if (function_exists('kop_status_column_to_varchar')) {
                $r = kop_status_column_to_varchar($pdo, 'news_submissions', 'article_type', 'general');
                if ($r === 'already' || $r === 'altered') return true;
                if ($r === 'no table') return false;
                error_log('kop news article_type column: ' . $r);
            }
            $col = $pdo->query("SHOW COLUMNS FROM `news_submissions` LIKE 'article_type'")->fetch(PDO::FETCH_ASSOC);
            if (!$col || stripos((string) $col['Type'], 'enum(') !== 0) return false;
            preg_match_all("/'((?:[^']|'')*)'/", (string) $col['Type'], $m);
            $have = array_map(function ($v) { return str_replace("''", "'", $v); }, $m[1]);
            $want = array_values(array_unique(array_merge($have, kop_news_article_type_values('all'))));
            if (count($want) === count($have)) return true;
            $enum = implode(',', array_map(function ($v) use ($pdo) { return $pdo->quote($v); }, $want));
            $pdo->exec("ALTER TABLE `news_submissions` MODIFY `article_type` enum($enum) DEFAULT 'general' COMMENT 'Type of news article'");
            return true;
        } catch (Throwable $e) {
            error_log('kop news article_type column sync: ' . $e->getMessage());
            return false;
        }
    }
}

if (function_exists('add_action')) {
    add_action('kop_choice_lists_saved', function () {
        kop_news_article_type_column_sync();
    });
}
