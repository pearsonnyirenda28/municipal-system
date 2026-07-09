<?php
/**
 * includes/pagination.php
 * Small, framework-free pagination + search-box helpers shared by every
 * list page (Cases, Fines, Meter Readings, Damaged Meters, Users).
 *
 * The pattern used on every list page that includes this file:
 *   1. Build the WHERE clause (filters + search) as SQL + a params array.
 *   2. paginate($db, $countSql, $params, $perPage) -> current page, total
 *      pages, and the LIMIT/OFFSET to add to the real SELECT.
 *   3. renderSearchBox() / renderPagination() to draw the controls.
 */

const DEFAULT_PAGE_SIZE = 15;

/**
 * Runs a COUNT(*) query and works out which page we're on.
 * $countSql should be a full "SELECT COUNT(*) ..." query (with the same
 * WHERE/JOIN as the real query) so the page count matches the filtered
 * result set, not the whole table.
 */
function paginate(PDO $db, string $countSql, array $params, int $perPage = DEFAULT_PAGE_SIZE): array
{
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
    $offset = ($page - 1) * $perPage;

    return [
        'total'      => $total,
        'totalPages' => $totalPages,
        'page'       => $page,
        'perPage'    => $perPage,
        'offset'     => $offset,
    ];
}

/**
 * Builds the query string for a pagination/search link, keeping every
 * existing GET parameter except the ones you're overriding (normally
 * just 'page').
 */
function pageUrl(array $override): string
{
    $params = array_merge($_GET, $override);
    // Drop empty values so URLs stay clean (?status=&page=2 -> ?page=2)
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
}

function renderPagination(array $pageInfo): string
{
    if ($pageInfo['totalPages'] <= 1) {
        return '';
    }
    $page = $pageInfo['page'];
    $totalPages = $pageInfo['totalPages'];

    $html = '<nav class="pagination no-print">';

    if ($page > 1) {
        $html .= '<a href="' . htmlspecialchars(pageUrl(['page' => $page - 1])) . '">&laquo; Previous</a>';
    }

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    if ($start > 1) {
        $html .= '<a href="' . htmlspecialchars(pageUrl(['page' => 1])) . '">1</a>';
        if ($start > 2) $html .= '<span class="page-ellipsis">&hellip;</span>';
    }
    for ($i = $start; $i <= $end; $i++) {
        $html .= $i === $page
            ? '<span class="page-current">' . $i . '</span>'
            : '<a href="' . htmlspecialchars(pageUrl(['page' => $i])) . '">' . $i . '</a>';
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<span class="page-ellipsis">&hellip;</span>';
        $html .= '<a href="' . htmlspecialchars(pageUrl(['page' => $totalPages])) . '">' . $totalPages . '</a>';
    }

    if ($page < $totalPages) {
        $html .= '<a href="' . htmlspecialchars(pageUrl(['page' => $page + 1])) . '">Next &raquo;</a>';
    }

    $html .= '</nav>';
    return $html;
}

/** A simple text search box that preserves other filters/sort on submit. */
function renderSearchBox(string $placeholder = 'Search...'): string
{
    $q = htmlspecialchars($_GET['q'] ?? '');
    $hidden = '';
    foreach ($_GET as $key => $value) {
        if (in_array($key, ['q', 'page'], true)) continue;
        if (is_array($value)) continue;
        $hidden .= '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
    }
    return '<form method="get" class="search-box no-print">'
         . $hidden
         . '<input type="text" name="q" value="' . $q . '" placeholder="' . htmlspecialchars($placeholder) . '">'
         . '<button type="submit" class="btn secondary">Search</button>'
         . ($q !== '' ? '<a href="' . htmlspecialchars(pageUrl(['q' => null])) . '" class="btn secondary">Clear</a>' : '')
         . '</form>';
}
