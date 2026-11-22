<?php
/*
 * ASMB : robot du calendrier scolaire (zone A, académie de Besançon, donc Territoire de Belfort).
 * Lancé avec l'agenda FFK, une fois par mois (.github/workflows/agenda.yml), ou à la main : php vacances.php
 * Lit le jeu de données officiel « Le calendrier scolaire » de l'Éducation nationale (data.education.gouv.fr)
 * et écrit vacances.json : [{nom, debut, reprise}], debut = premier jour de vacances, reprise = retour en classe.
 * Le suivi des jeunes du site (périodes « de la reprise aux vacances suivantes ») s'en sert pour les années à venir.
 * Prudence : si la source ne répond pas ou donne trop peu de dates, on garde le fichier précédent.
 */
require __DIR__ . '/ffk.php';   // pour ffk_telecharger()

$ACADEMIE = 'Besançon';
$fichier = __DIR__ . '/vacances.json';
$paris = new DateTimeZone('Europe/Paris');
$depuis = (date('Y') - 1) . '-06-01';
$url = 'https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records?'
     . http_build_query(array('where' => 'location="' . $ACADEMIE . '" AND start_date >= date\'' . $depuis . '\'',
                              'order_by' => 'start_date', 'limit' => 100,
                              'select' => 'description,start_date,end_date,population,annee_scolaire,zones'));

function jour_paris($iso, $tz) {
    if (!$iso) return null;
    try { $d = new DateTime($iso); } catch (Exception $e) { return null; }
    $d->setTimezone($tz);
    return $d->format('Y-m-d');
}
function nom_vacances($desc) {
    $d = mb_strtolower($desc, 'UTF-8');
    if (strpos($d, 'pont') !== false) return null;           // pont de l'Ascension : pas une coupure de période
    foreach (array('toussaint' => 'Toussaint', 'noël' => 'Noël', 'hiver' => 'Hiver', 'printemps' => 'Printemps', 'été' => 'Été') as $k => $n)
        if (strpos($d, $k) !== false && strpos($d, 'vacances') !== false) return $n;
    return null;
}

$r = ffk_telecharger($url);
$data = $r['code'] === 200 ? json_decode($r['corps'], true) : null;
if (!$data || !isset($data['results'])) { fwrite(STDERR, "Calendrier scolaire indisponible ({$r['code']} {$r['erreur']}) : on garde le précédent.\n"); exit(0); }

$vac = array(); $rentrees = array();
foreach ($data['results'] as $l) {
    $pop = isset($l['population']) ? $l['population'] : '-';
    if ($pop === 'Enseignants') continue;                     // on suit les élèves
    $desc = isset($l['description']) ? $l['description'] : '';
    if (stripos($desc, 'rentrée') !== false) { $j = jour_paris($l['start_date'], $paris); if ($j) $rentrees[] = $j; continue; }
    $nom = nom_vacances($desc); if (!$nom) continue;
    $debut = jour_paris($l['start_date'], $paris); if (!$debut) continue;
    $reprise = isset($l['end_date']) ? jour_paris($l['end_date'], $paris) : null;
    if ($reprise !== null && $reprise <= $debut) $reprise = null;
    $vac[$nom . $debut] = array('nom' => $nom, 'debut' => $debut, 'reprise' => $reprise);
}
// été : la reprise est la rentrée suivante si le jeu de données ne la donne pas
sort($rentrees);
foreach ($vac as $k => $v) if ($v['nom'] === 'Été' && $v['reprise'] === null)
    foreach ($rentrees as $j) if ($j > $v['debut']) { $vac[$k]['reprise'] = $j; break; }
$vac = array_values($vac);
usort($vac, function ($a, $b) { return strcmp($a['debut'], $b['debut']); });

if (count($vac) < 4) { fwrite(STDERR, 'Trop peu de vacances lues (' . count($vac) . ") : on garde le précédent.\n"); exit(0); }
$sortie = array('version' => 1, 'majLe' => gmdate('Y-m-d\TH:i:s\Z'), 'zone' => 'A', 'academie' => $ACADEMIE,
                'source' => 'data.education.gouv.fr, jeu « fr-en-calendrier-scolaire »', 'vacances' => $vac);
$avant = is_file($fichier) ? json_decode(file_get_contents($fichier), true) : null;
if ($avant && $avant['vacances'] === $vac) { echo "Calendrier scolaire inchangé.\n"; exit(0); }
file_put_contents($fichier, json_encode($sortie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo count($vac) . " vacances écrites.\n";
foreach ($vac as $v) echo "  {$v['nom']} : {$v['debut']} → " . ($v['reprise'] ?: '?') . "\n";
