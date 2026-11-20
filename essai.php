<?php
// Essai temporaire : montre la structure des pages de la FFK (à supprimer après réglage)
require __DIR__ . '/ffk.php';
foreach (array('departemental', 'regional') as $niv) {
    $src = $GLOBALS['FFK_SOURCES'][$niv];
    $r = ffk_obtenir($src['url']);
    echo "===== $niv HTTP {$r['code']} taille " . strlen($r['corps']) . "\n";
    $dom = ffk_dom($r['corps']); $xp = new DOMXPath($dom); $vus = 0; $deja = array();
    foreach ($xp->query('//a[@href]') as $a) {
        $u = ffk_absolu($a->getAttribute('href'), $src['url']);
        if (!preg_match($src['fiche'], $u) || isset($deja[$u])) continue; $deja[$u] = 1;
        $n = $a; for ($i = 0; $i < 6 && $n->parentNode && $n->parentNode->nodeName !== 'body'; $i++) { $n = $n->parentNode; if (count($xp->query('.//a[contains(@href,"/event/")]', $n)) > 3) break; }
        echo "--- $u\n", substr(preg_replace('/\s+/', ' ', $dom->saveHTML($n)), 0, 3000), "\n";
        if (++$vus >= 3) break;
    }
}
$r = ffk_obtenir('https://www.ffkarate.fr/calendrier/');
preg_match_all('#https?://[^"\'\s<>]+\.ics#i', $r['corps'], $m);
$ics = ffk_obtenir($m[0][0]);
echo "===== ICS ", $m[0][0], "\n", substr($ics['corps'], 0, 2500), "\n";
$cats = array();
foreach (ffk_lire_ics($ics['corps']) as $e) foreach ($e['etiquettes'] as $c) $cats[$c] = (isset($cats[$c]) ? $cats[$c] : 0) + 1;
arsort($cats); echo "CATEGORIES ", json_encode($cats, JSON_UNESCAPED_UNICODE), "\n";
