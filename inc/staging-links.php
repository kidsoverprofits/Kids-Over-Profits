<?php
/**
 * The old staging install at https://kidsoverprofits.org/staging/.
 *
 * It is gone (checked 2026-10-14: no ~/public_html/staging on the server; /staging/ answers 404 and its old
 * inner addresses redirect to the live pages). robots.txt used to ask crawlers to stay out of /staging/; that
 * rule is gone too, because it kept Google from recrawling the old addresses and seeing they are gone, so
 * they could stay in its index. If a cached copy of the old staging home page still answers, purge /staging/
 * in LiteSpeed Cache (Toolbox > Purge > Purge by URL).
 *
 * Links from published content into /staging/ were fixed in the database in September 2026;
 * (kop-pipeline) scripts/check-staging-links.py re-checks the rendered pages for any that come back.
 */

if (!defined('ABSPATH')) {
    exit;
}
