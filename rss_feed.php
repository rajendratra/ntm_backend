<?php
/* 
 * NTM RSS FEED API
 * ==========================================
 * Generates RSS 2.0 compliant XML feed from existing database
 * Uses the same database connection and patterns as your working code
 */

// Include your existing database connection
error_reporting(E_ALL);
ini_set('display_errors', 0); 
include 'db.php';

// Create connection
$conn = new mysqli($host, $user, $pass, $dbname);
if ($conn->connect_error) {
    outputErrorFeed("Database connection failed: " . $conn->connect_error);
    exit;
}
$conn->set_charset("utf8mb4");

// Get and sanitize parameters
$limit = isset($_GET['limit']) ? min(max(1, (int)$_GET['limit']), 200) : 100;
$days = isset($_GET['days']) ? min(max(1, (int)$_GET['days']), 90) : 7;
$sid = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
$keywords = isset($_GET['keywords']) ? trim($_GET['keywords']) : '';

// Set proper headers
header('Content-Type: application/rss+xml; charset=UTF-8');
header('Cache-Control: public, max-age=300'); // Cache for 5 minutes
header('X-Powered-By: NTM Intelligence RSS Feed');

// Try multiple query strategies to ensure we get data
$articles = getArticlesWithFallback($conn, $limit, $days, $sid, $keywords);

// Generate RSS feed
generateRSSFeed($articles, $limit, $days, $sid, $keywords);

// Close connection
$conn->close();

/**
 * Get articles with multiple fallback strategies
 */
function getArticlesWithFallback($conn, $limit, $days, $sid, $keywords) {
    // Strategy 1: Full query with all joins (like your working search)
    $articles = getArticlesFullQuery($conn, $limit, $days, $sid, $keywords);
    
    if (count($articles) > 0) {
        return $articles;
    }
    
    // Strategy 2: Simplified query without sector/keyword joins
    $articles = getArticlesSimpleQuery($conn, $limit, $days);
    
    if (count($articles) > 0) {
        return $articles;
    }
    
    // Strategy 3: Basic query with no filters
    return getArticlesBasicQuery($conn, $limit);
}

/**
 * Full query - matches your working dashboard query structure
 */
function getArticlesFullQuery($conn, $limit, $days, $sid, $keywords) {
    $articles = [];
    
    // Build keyword conditions
    $kw_sql = "";
    if (!empty($keywords)) {
        $kw_arr = array_unique(array_filter(array_map('trim', explode(',', $keywords))));
        $conds = [];
        foreach ($kw_arr as $kw) { 
            $safe_kw = $conn->real_escape_string($kw); 
            $conds[] = "LOWER(an.Headline) LIKE '%$safe_kw%'"; 
        }
        if (count($conds) > 0) {
            $kw_sql = "(" . implode(' OR ', $conds) . ") AND ";
        }
    }
    
    // Build main query
    $sql = "SELECT DISTINCT 
                an.gidNews, 
                an.Headline, 
                an.PublishDate,
                DATE_FORMAT(an.PublishDate, '%Y-%m-%d %H:%i:%s') AS FormattedDate,
                rnl.rssLinks, 
                M.MediaOutlet,
                M.WebsiteUrl,
                COALESCE(s.Sector, 'General') AS Sector,
                an.Content,
                an.Summary
            FROM app_news an 
            LEFT JOIN app_keyword_news akn ON an.gidNews = akn.gidNews 
            LEFT JOIN app_sector_keywords ask ON ask.k_id = akn.k_id 
            LEFT JOIN app_sectors s ON s.id = ask.sector_id 
            LEFT JOIN rssNewsLinks rnl ON an.gidNews = rnl.rssNewsId 
            LEFT JOIN MediaOutlet M ON M.gidMediaOutlet = an.gidMediaOutlet 
            WHERE $kw_sql an.PublishDate >= DATE_SUB(NOW(), INTERVAL $days DAY)
            AND an.Headline IS NOT NULL 
            AND an.Headline != ''";
    
    if ($sid > 0) {
        $sql .= " AND s.id = $sid";
    }
    
    $sql .= " GROUP BY an.gidNews
              ORDER BY an.PublishDate DESC 
              LIMIT $limit";
    
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $articles[] = $row;
        }
    }
    
    return $articles;
}

/**
 * Simplified query - without sector/keyword complexity
 */
function getArticlesSimpleQuery($conn, $limit, $days) {
    $articles = [];
    
    $sql = "SELECT 
                an.gidNews, 
                an.Headline, 
                an.PublishDate,
                DATE_FORMAT(an.PublishDate, '%Y-%m-%d %H:%i:%s') AS FormattedDate,
                rnl.rssLinks, 
                COALESCE(M.MediaOutlet, 'NTM News') AS MediaOutlet,
                an.Content,
                an.Summary
            FROM app_news an 
            LEFT JOIN rssNewsLinks rnl ON an.gidNews = rnl.rssNewsId 
            LEFT JOIN MediaOutlet M ON M.gidMediaOutlet = an.gidMediaOutlet 
            WHERE an.PublishDate >= DATE_SUB(NOW(), INTERVAL $days DAY)
            AND an.Headline IS NOT NULL 
            AND an.Headline != ''
            ORDER BY an.PublishDate DESC 
            LIMIT $limit";
    
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $articles[] = $row;
        }
    }
    
    return $articles;
}

/**
 * Basic query - last resort, no date filter
 */
function getArticlesBasicQuery($conn, $limit) {
    $articles = [];
    
    $sql = "SELECT 
                an.gidNews, 
                an.Headline, 
                an.PublishDate,
                DATE_FORMAT(an.PublishDate, '%Y-%m-%d %H:%i:%s') AS FormattedDate,
                rnl.rssLinks, 
                'NTM News' AS MediaOutlet
            FROM app_news an 
            LEFT JOIN rssNewsLinks rnl ON an.gidNews = rnl.rssNewsId 
            WHERE an.Headline IS NOT NULL 
            AND an.Headline != ''
            ORDER BY an.PublishDate DESC 
            LIMIT $limit";
    
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $articles[] = $row;
        }
    }
    
    return $articles;
}

/**
 * Generate the RSS feed XML
 */
function generateRSSFeed($articles, $limit, $days, $sid, $keywords) {
    $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    $feedUrl = $siteUrl . $_SERVER['REQUEST_URI'];
    
    // Build feed title based on filters
    $feedTitle = "NTM Market Intelligence";
    if ($sid > 0) {
        $feedTitle .= " - Sector News";
    }
    if (!empty($keywords)) {
        $feedTitle .= " - Search: " . htmlspecialchars(substr($keywords, 0, 50));
    }
    
    $feedDescription = "Real-time market intelligence and news from global sources. ";
    $feedDescription .= "Stay updated with the latest business news, market trends, and industry insights.";
    
    // Start XML output
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
    $xml .= '<channel>' . "\n";
    
    // Channel metadata
    $xml .= '    <title><![CDATA[' . $feedTitle . ']]></title>' . "\n";
    $xml .= '    <link>' . htmlspecialchars($feedUrl) . '</link>' . "\n";
    $xml .= '    <description><![CDATA[' . $feedDescription . ']]></description>' . "\n";
    $xml .= '    <language>en-us</language>' . "\n";
    $xml .= '    <lastBuildDate>' . date('D, d M Y H:i:s O') . '</lastBuildDate>' . "\n";
    $xml .= '    <generator>NTM Intelligence RSS Generator v2.0</generator>' . "\n";
    $xml .= '    <ttl>60</ttl>' . "\n"; // Time to live in minutes
    $xml .= '    <atom:link href="' . htmlspecialchars($feedUrl) . '" rel="self" type="application/rss+xml" />' . "\n";
    
    // Add image/logo
    $xml .= '    <image>' . "\n";
    $xml .= '        <url>' . $siteUrl . '/images/ntm-logo.png</url>' . "\n";
    $xml .= '        <title>NTM Intelligence</title>' . "\n";
    $xml .= '        <link>' . $siteUrl . '</link>' . "\n";
    $xml .= '        <width>144</width>' . "\n";
    $xml .= '        <height>40</height>' . "\n";
    $xml .= '    </image>' . "\n";
    
    // Add items
    if (count($articles) > 0) {
        foreach ($articles as $article) {
            $xml .= generateRSSItem($article, $siteUrl);
        }
        
        // Add feed statistics
        $xml .= '    <sy:updatePeriod>hourly</sy:updatePeriod>' . "\n";
        $xml .= '    <sy:updateFrequency>1</sy:updateFrequency>' . "\n";
        $xml .= '    <sy:updateBase>' . date('Y-m-d\TH:i:sO') . '</sy:updateBase>' . "\n";
        
    } else {
        // No articles found - show helpful message
        $xml .= '    <item>' . "\n";
        $xml .= '        <title><![CDATA[No Articles Found]]></title>' . "\n";
        $xml .= '        <link>' . $siteUrl . '</link>' . "\n";
        $xml .= '        <description><![CDATA[No articles match your criteria. Try adjusting the filters or check back later for new market intelligence.]]></description>' . "\n";
        $xml .= '        <pubDate>' . date('D, d M Y H:i:s O') . '</pubDate>' . "\n";
        $xml .= '        <guid isPermaLink="false">no-articles-' . time() . '</guid>' . "\n";
        $xml .= '    </item>' . "\n";
    }
    
    $xml .= '</channel>' . "\n";
    $xml .= '</rss>';
    
    // Output the feed
    echo $xml;
}

/**
 * Generate a single RSS item
 */
function generateRSSItem($article, $siteUrl) {
    // Clean and prepare data
    $title = trim(stripslashes(html_entity_decode($article['Headline'], ENT_QUOTES, 'UTF-8')));
    $title = mb_strlen($title) > 200 ? mb_substr($title, 0, 197) . '...' : $title;
    
    $link = !empty($article['rssLinks']) ? $article['rssLinks'] : $siteUrl . '/news/' . $article['gidNews'];
    
    $pubDate = date('D, d M Y H:i:s O', strtotime($article['PublishDate']));
    
    // Build description
    $description = '';
    if (!empty($article['Summary'])) {
        $description = strip_tags(html_entity_decode($article['Summary'], ENT_QUOTES, 'UTF-8'));
    } elseif (!empty($article['Content'])) {
        $description = strip_tags(html_entity_decode($article['Content'], ENT_QUOTES, 'UTF-8'));
        $description = mb_strlen($description) > 500 ? mb_substr($description, 0, 497) . '...' : $description;
    } else {
        $description = $title;
    }
    
    // Build full content
    $content = '';
    if (!empty($article['Content'])) {
        $content = stripslashes(html_entity_decode($article['Content'], ENT_QUOTES, 'UTF-8'));
    } elseif (!empty($article['Summary'])) {
        $content = stripslashes(html_entity_decode($article['Summary'], ENT_QUOTES, 'UTF-8'));
    }
    
    $author = !empty($article['MediaOutlet']) ? $article['MediaOutlet'] : 'NTM Intelligence';
    $category = isset($article['Sector']) && $article['Sector'] != 'General' ? $article['Sector'] : 'Market News';
    
    // Get sentiment
    $sentiment = getSentimentLabel($title);
    
    $xml = '    <item>' . "\n";
    $xml .= '        <title><![CDATA[' . $title . ']]></title>' . "\n";
    $xml .= '        <link>' . htmlspecialchars($link) . '</link>' . "\n";
    $xml .= '        <guid isPermaLink="false">ntm-news-' . $article['gidNews'] . '</guid>' . "\n";
    $xml .= '        <pubDate>' . $pubDate . '</pubDate>' . "\n";
    $xml .= '        <description><![CDATA[' . $description . ']]></description>' . "\n";
    
    if (!empty($content)) {
        $xml .= '        <content:encoded><![CDATA[' . $content . ']]></content:encoded>' . "\n";
    }
    
    $xml .= '        <author><![CDATA[' . $author . ']]></author>' . "\n";
    $xml .= '        <dc:creator><![CDATA[' . $author . ']]></dc:creator>' . "\n";
    $xml .= '        <category><![CDATA[' . $category . ']]></category>' . "\n";
    $xml .= '        <category><![CDATA[' . $sentiment . ']]></category>' . "\n";
    
    // Add source info
    if (!empty($article['MediaOutlet'])) {
        $xml .= '        <source url="' . htmlspecialchars($link) . '"><![CDATA[' . $article['MediaOutlet'] . ']]></source>' . "\n";
    }
    
    $xml .= '    </item>' . "\n";
    
    return $xml;
}

/**
 * Get sentiment label for an article
 */
function getSentimentLabel($headline) {
    $headline = strtolower($headline);
    $posWords = ['beat','surge','rise','gain','profit','growth','strong','record','best','wins','boost','approval','launches','surpasses','outperforms','soars','breakthrough','rally','jump','boom','explodes','positive','success'];
    $negWords = ['miss','drop','fall','loss','decline','weak','down','crash','probe','cut','delay','warning','risk','lawsuit','fails','plunges','slumps','concern','plummets','tumbles','collapses','negative','crisis'];
    
    $score = 0;
    foreach ($posWords as $w) {
        if (strpos($headline, $w) !== false) $score++;
    }
    foreach ($negWords as $w) {
        if (strpos($headline, $w) !== false) $score--;
    }
    
    if ($score > 0) return 'Positive Sentiment';
    if ($score < 0) return 'Negative Sentiment';
    return 'Neutral Sentiment';
}

/**
 * Output error as valid RSS feed
 */
function outputErrorFeed($message) {
    $siteUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0">' . "\n";
    echo '<channel>' . "\n";
    echo '    <title>NTM RSS Feed - Error</title>' . "\n";
    echo '    <link>' . $siteUrl . '</link>' . "\n";
    echo '    <description>Error loading RSS feed</description>' . "\n";
    echo '    <lastBuildDate>' . date('D, d M Y H:i:s O') . '</lastBuildDate>' . "\n";
    echo '    <item>' . "\n";
    echo '        <title><![CDATA[Feed Error]]></title>' . "\n";
    echo '        <description><![CDATA[' . htmlspecialchars($message) . ']]></description>' . "\n";
    echo '        <pubDate>' . date('D, d M Y H:i:s O') . '</pubDate>' . "\n";
    echo '    </item>' . "\n";
    echo '</channel>' . "\n";
    echo '</rss>';
}
?>