<?php
/**
 * includes/charts.php
 * Improvement 3: Dashboard monthly trend charts.
 *
 * Deliberately plain server-rendered SVG rather than a JS charting
 * library: the app already works offline for meter capture (Improvement
 * 1), and pulling in a CDN-hosted chart library would be the one thing
 * on the page that silently breaks without a connection. A handful of
 * <rect> bars costs nothing extra and needs no dependency.
 */

/**
 * Renders a simple multi-series monthly bar chart as inline SVG.
 *
 * @param array $buckets  Output of monthlyBuckets() - each row has
 *                         'label' (e.g. "Feb") plus one entry per series key.
 * @param array $series   ['column_key' => ['label' => 'Cases Opened', 'color' => '#2C7873']]
 */
function renderMonthlyBarChart(array $buckets, array $series, string $title): string
{
    $width = 640;
    $height = 220;
    $paddingLeft = 36;
    $paddingBottom = 34;
    $paddingTop = 18;
    $plotWidth = $width - $paddingLeft - 12;
    $plotHeight = $height - $paddingTop - $paddingBottom;

    $max = 1;
    foreach ($buckets as $b) {
        foreach (array_keys($series) as $key) {
            $max = max($max, (float) $b[$key]);
        }
    }
    // Round the axis top up to a friendly number.
    $niceMax = $max <= 5 ? 5 : (int) (ceil($max / 5) * 5);

    $months = count($buckets);
    $seriesCount = count($series);
    $groupWidth = $plotWidth / max($months, 1);
    $barGap = 4;
    $barWidth = max(4, ($groupWidth - $barGap * ($seriesCount + 1)) / $seriesCount);

    $svg = '<svg viewBox="0 0 ' . $width . ' ' . $height . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . htmlspecialchars($title) . '" style="width:100%;height:auto;">';

    // Gridlines + Y-axis labels (0, 1/2, max).
    foreach ([0, 0.5, 1] as $frac) {
        $y = $paddingTop + $plotHeight - ($frac * $plotHeight);
        $svg .= '<line x1="' . $paddingLeft . '" y1="' . $y . '" x2="' . ($width - 8) . '" y2="' . $y . '" stroke="#E1E6ED" stroke-width="1"/>';
        $svg .= '<text x="' . ($paddingLeft - 8) . '" y="' . ($y + 4) . '" font-size="10" fill="#6b7684" text-anchor="end">' . (int) round($frac * $niceMax) . '</text>';
    }

    foreach ($buckets as $i => $b) {
        $groupX = $paddingLeft + $i * $groupWidth;
        // Month label.
        $svg .= '<text x="' . ($groupX + $groupWidth / 2) . '" y="' . ($height - 10) . '" font-size="11" fill="#3a4457" text-anchor="middle">' . htmlspecialchars($b['label']) . '</text>';

        $j = 0;
        foreach ($series as $key => $meta) {
            $value = (float) $b[$key];
            $barHeight = $niceMax > 0 ? ($value / $niceMax) * $plotHeight : 0;
            $x = $groupX + $barGap + $j * ($barWidth + $barGap);
            $y = $paddingTop + $plotHeight - $barHeight;
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barWidth, 1) . '" height="' . round($barHeight, 1)
                  . '" fill="' . htmlspecialchars($meta['color']) . '" rx="2">'
                  . '<title>' . htmlspecialchars($meta['label']) . ' - ' . htmlspecialchars($b['label']) . ': ' . (int) $value . '</title>'
                  . '</rect>';
            $j++;
        }
    }

    $svg .= '</svg>';

    // Legend.
    $legend = '<div style="display:flex;gap:16px;flex-wrap:wrap;font-size:0.82rem;color:#3a4457;margin-top:6px;">';
    foreach ($series as $meta) {
        $legend .= '<span style="display:inline-flex;align-items:center;gap:6px;">'
                 . '<span style="width:10px;height:10px;border-radius:2px;background:' . htmlspecialchars($meta['color']) . ';display:inline-block;"></span>'
                 . htmlspecialchars($meta['label']) . '</span>';
    }
    $legend .= '</div>';

    return '<div class="form-panel" style="max-width:none;margin-bottom:20px;">'
         . '<h3 style="margin-top:0;">' . htmlspecialchars($title) . '</h3>'
         . $svg . $legend . '</div>';
}
