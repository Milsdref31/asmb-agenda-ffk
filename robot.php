<?php
/*
 * ASMB : robot de l'agenda fédéral. Lancé une fois par mois par GitHub Actions (.github/workflows/agenda.yml),
 * ou à la main : php robot.php
 * Lit les trois calendriers de la FFK (voir ffk.php) et écrit agenda.json, que le site du club affiche.
 *
 * Prudence : si une source ne répond pas ou ne donne plus rien alors qu'elle donnait des rendez-vous,
 * on garde ses rendez-vous précédents encore à venir (un site momentanément en panne ne vide pas l'agenda).
 * Le robot s'arrête en erreur (et GitHub prévient par e-mail) si les trois sources échouent.
 */
require __DIR__ . '/ffk.php';

$fichier = __DIR__ . '/agenda.json';
$avant = is_file($fichier) ? json_decode(file_get_contents($fichier), true) : null;
$res = ffk_tout();
$aujourdhui = date('Y-m-d');

$evenements = $res['evenements'];
$sources = array();
$echecs = 0;
foreach ($res['sources'] as $niveau => $cr) {
    $nb = (int)$cr['gardes'];
    $sources[$niveau] = array('ok' => $cr['erreur'] === '' && $cr['lus'] > 0, 'nb' => $nb, 'pages' => (int)$cr['pages'], 'erreur' => $cr['erreur']);
    if ($sources[$niveau]['ok'] && $nb > 0) continue;
    $echecs++;
    if ($avant && !empty($avant['evenements'])) {
        $anciens = array_values(array_filter($avant['evenements'], function ($e) use ($niveau, $aujourdhui) { return $e['niveau'] === $niveau && $e['fin'] >= $aujourdhui; }));
        if ($anciens) {
            $evenements = array_merge($evenements, $anciens);
            $sources[$niveau]['garde_precedent'] = count($anciens);
        }
    }
    fwrite(STDERR, "Attention : $niveau — " . ($cr['erreur'] !== '' ? $cr['erreur'] : 'aucun rendez-vous compris') . "\n");
}
usort($evenements, function ($a, $b) { return strcmp($a['debut'] . $a['niveau'] . $a['titre'], $b['debut'] . $b['niveau'] . $b['titre']); });

$sortie = array(
    'version' => 1,
    'majLe' => gmdate('Y-m-d\TH:i:s\Z'),
    'sources' => $sources,
    'liens' => array_map(function ($s) { return $s['url']; }, $GLOBALS['FFK_SOURCES']),
    'evenements' => $evenements,
);
file_put_contents($fichier, json_encode($sortie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

/* Agendas à s'abonner (.ics), un par choix de filtres du site : ics/<niveau>-<type>.ics, « tous » pour tout.
   Le téléphone ou Google Agenda les relisent tout seuls : les rendez-vous suivent la mise à jour mensuelle. */
$NIVEAUX = array('tous', 'departemental', 'regional', 'national');
$TYPES = array('tous', 'stage', 'stage-elite', 'competition', 'formation', 'grades');
$NOM_NIV = array('tous' => '', 'departemental' => ' départemental', 'regional' => ' régional', 'national' => ' national');
$NOM_TYP = array('tous' => 'Agenda FFK', 'stage' => 'Stages FFK', 'stage-elite' => 'Stages élite FFK', 'competition' => 'Compétitions FFK', 'formation' => 'Formations FFK', 'grades' => 'Grades FFK');
@mkdir(__DIR__ . '/ics');
foreach ($NIVEAUX as $nv) foreach ($TYPES as $ty) {
    $liste = array_filter($evenements, function ($e) use ($nv, $ty) { return ($nv === 'tous' || $e['niveau'] === $nv) && ($ty === 'tous' || $e['type'] === $ty); });
    file_put_contents(__DIR__ . "/ics/$nv-$ty.ics", ffk_ics($liste, $NOM_TYP[$ty] . $NOM_NIV[$nv] . ' (ASMB Karaté)'));
}

foreach ($sources as $n => $s) printf("%-14s %s  %3d rendez-vous  %d page(s)%s\n", $n, $s['ok'] ? 'OK ' : 'ÉCHEC', $s['nb'], $s['pages'],
    isset($s['garde_precedent']) ? '  (gardés de la fois précédente : ' . $s['garde_precedent'] . ')' : '');
$types = array();
foreach ($evenements as $e) $types[$e['type']] = (isset($types[$e['type']]) ? $types[$e['type']] : 0) + 1;
echo 'Par type : ', json_encode($types), "\n";
foreach (array_slice($evenements, 0, 25) as $e) printf("  %s %-13s %-11s %s\n", $e['debut'], $e['niveau'], $e['type'], $e['titre']);
exit($echecs === count($sources) ? 1 : 0);
