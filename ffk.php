<?php
/*
 * ASMB : récupération des calendriers de la fédération (FFK) pour l'agenda du site.
 * Tourne sur GitHub (robot.php, une fois par mois) : l'hébergement Free du site n'a pas le droit
 * de se connecter à d'autres sites (essai du 20/11/2026 : « Could not connect to server »).
 *
 *   Départemental : https://sites.ffkarate.fr/territoiredebelfort/calendrier/      (pages HTML, liens /event/…)
 *   Régional      : https://sites.ffkarate.fr/bourgognefranchecomte/calendrier/   (pages HTML, liens /event/…)
 *   National      : https://www.ffkarate.fr/calendrier/                           (fichier agenda .ics de la saison, sinon pages HTML)
 *
 * Fonctions pures (pas de base de données) : ffk_tout() renvoie la liste des rendez-vous classés
 * (niveau, type) avec leur lien direct, et un compte rendu par source. Compatible PHP 5.6 → 8.x.
 * Les pages de la fédération peuvent changer : le lecteur HTML cherche les liens des fiches,
 * puis le bloc qui entoure chaque lien, sans dépendre des noms de classes.
 */

if (!function_exists('mb_strlen')) { function mb_strlen($s, $e = null) { return strlen(utf8_decode($s)); } }

define('FFK_AGENT', 'Mozilla/5.0 (compatible; ASMB-Karate-Belfort/1.0; +https://asmbkaratedo.pages-perso.free.fr/)');

$GLOBALS['FFK_SOURCES'] = array(
    'departemental' => array('url' => 'https://sites.ffkarate.fr/territoiredebelfort/calendrier/', 'fiche' => '~/territoiredebelfort/event/[^/?#]+/?$~'),
    'regional'      => array('url' => 'https://sites.ffkarate.fr/bourgognefranchecomte/calendrier/', 'fiche' => '~/bourgognefranchecomte/event/[^/?#]+/?$~'),
    'national'      => array('url' => 'https://www.ffkarate.fr/calendrier/', 'fiche' => '~^https://www\.ffkarate\.fr/calendrier/(?!page/|pdf|xls)[^/?#]+/?$~'),
);

/* ---------- téléchargement ---------- */

// Renvoie array('code' => statut HTTP, 'corps' => texte, 'erreur' => message, 'ms' => durée, 'moyen' => curl|flux).
// $verifier = false seulement en secours, si le serveur n'a pas les certificats récents (données publiques en lecture seule).
function ffk_telecharger($url, $verifier = true, $delai = 20) {
    $t = microtime(true);
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => $delai, CURLOPT_USERAGENT => FFK_AGENT, CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => $verifier, CURLOPT_SSL_VERIFYHOST => $verifier ? 2 : 0,
            CURLOPT_HTTPHEADER => array('Accept: text/html,text/calendar;q=0.9,*/*;q=0.5', 'Accept-Language: fr')));
        $corps = curl_exec($c);
        $res = array('code' => (int)curl_getinfo($c, CURLINFO_HTTP_CODE), 'corps' => $corps === false ? '' : $corps,
                     'erreur' => $corps === false ? curl_error($c) : '', 'moyen' => 'curl');
        curl_close($c);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(array(
            'http' => array('timeout' => $delai, 'user_agent' => FFK_AGENT, 'follow_location' => 1, 'ignore_errors' => true, 'header' => "Accept-Language: fr\r\n"),
            'ssl'  => array('verify_peer' => $verifier, 'verify_peer_name' => $verifier)));
        $corps = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header)) foreach ($http_response_header as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
        $e = error_get_last();
        $res = array('code' => $code, 'corps' => $corps === false ? '' : $corps, 'erreur' => $corps === false ? ($e ? $e['message'] : 'échec') : '', 'moyen' => 'flux');
    } else {
        $res = array('code' => 0, 'corps' => '', 'erreur' => 'Ni curl ni allow_url_fopen : le serveur ne peut pas télécharger de page.', 'moyen' => 'aucun');
    }
    $res['ms'] = (int)round((microtime(true) - $t) * 1000);
    return $res;
}

// Téléchargement avec vérification des certificats, puis sans si (et seulement si) la vérification a échoué.
function ffk_obtenir($url) {
    $r = ffk_telecharger($url, true);
    if ($r['code'] === 0 && preg_match('/SSL|certificate|certificat/i', $r['erreur'])) {
        $r2 = ffk_telecharger($url, false);
        $r2['sans_verif'] = true;
        if ($r2['code']) return $r2;
    }
    return $r;
}

/* ---------- outils texte et dates ---------- */

function ffk_texte($s) {
    $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES, 'UTF-8');
    $s = str_replace(array("\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89"), ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}
function ffk_minus($s) { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }
function ffk_sans_accents($s) {
    return strtr(ffk_minus($s), array('é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', 'œ' => 'oe'));
}

// numéro du mois à partir d'un nom français abrégé ou non (« déc. », « Sept », « juillet »…)
function ffk_mois($nom) {
    $n = ffk_sans_accents(rtrim($nom, '.'));
    $table = array('janv' => 1, 'fevr' => 2, 'fev' => 2, 'mars' => 3, 'avr' => 4, 'mai' => 5, 'juin' => 6, 'juil' => 7, 'aout' => 8,
                   'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12);
    foreach ($table as $k => $v) if (strpos($n, $k) === 0) return $v;
    return 0;
}
define('FFK_MOIS_RE', '(janv(?:ier|\.)?|f[ée]vr?(?:ier|\.)?|mars|avr(?:il|\.)?|mai|juin|juil(?:let|\.)?|ao[uû]t|sept(?:embre|\.)?|oct(?:obre|\.)?|nov(?:embre|\.)?|d[ée]c(?:embre|\.)?)');

/* Dates d'un texte de bloc : « Dim. 6 déc. 2026 », « Sam. 26 au Dim. 27 Sept. 2026 », « Mar. 1 Sept. 2026 au Mar. 31 Août 2027 »,
   heures « 08h30 – 11h30 ». $annee_defaut : année tirée d'un intertitre de mois (« Décembre 2026 ») quand le bloc ne la donne pas.
   Renvoie array('debut' => 'AAAA-MM-JJ', 'fin' => 'AAAA-MM-JJ', 'heures' => '08h30 – 11h30') ou null. */
function ffk_dates($t, $mois_defaut = 0, $annee_defaut = 0) {
    $M = FFK_MOIS_RE; $j = '(\d{1,2})(?:er)?';
    $d1 = $d2 = null;
    if (preg_match("/$j\s+$M\s*(\d{4})?\s+au\s+(?:[a-zé]+\.?\s+)?$j\s+$M\s*(\d{4})?/iu", $t, $m)) {          // 1 sept. 2026 au 31 août 2027
        $a2 = !empty($m[6]) ? (int)$m[6] : $annee_defaut; $a1 = !empty($m[3]) ? (int)$m[3] : $a2;
        $d1 = array($a1, ffk_mois($m[2]), (int)$m[1]); $d2 = array($a2, ffk_mois($m[5]), (int)$m[4]);
    } elseif (preg_match("/$j\s+au\s+(?:[a-zé]+\.?\s+)?$j\s+$M\s*(\d{4})?/iu", $t, $m)) {                       // 26 au 27 sept. 2026
        $a = !empty($m[4]) ? (int)$m[4] : $annee_defaut; $mo = ffk_mois($m[3]);
        $d1 = array($a, $mo, (int)$m[1]); $d2 = array($a, $mo, (int)$m[2]);
    } elseif (preg_match("/$j\s+$M\s*(\d{4})?/iu", $t, $m)) {                                                    // 6 déc. 2026
        $a = !empty($m[3]) ? (int)$m[3] : $annee_defaut;
        $d1 = $d2 = array($a, ffk_mois($m[2]), (int)$m[1]);
    } elseif ($mois_defaut && preg_match('/\b(?:lun|mar|mer|jeu|ven|sam|dim)[a-z]*\.?\s+(\d{1,2})\b/iu', $t, $m)) { // « Dim. 6 » sous l'intertitre du mois
        $d1 = $d2 = array($annee_defaut, $mois_defaut, (int)$m[1]);
    }
    if (!$d1 || !$d1[0] || !$d1[1]) return null;
    if ($d2[0] && $d2[1] && sprintf('%04d%02d%02d', $d2[0], $d2[1], $d2[2]) < sprintf('%04d%02d%02d', $d1[0], $d1[1], $d1[2])) $d1[0]--;   // 28 déc. au 3 janv. 2027
    $h = '';
    if (preg_match('/(\d{1,2})\s*h\s*(\d{2})?\s*(?:-|–|à|au)\s*(\d{1,2})\s*h\s*(\d{2})?/iu', $t, $m))
        $h = sprintf('%dh%s – %dh%s', $m[1], $m[2] !== '' ? $m[2] : '00', $m[3], isset($m[4]) && $m[4] !== '' ? $m[4] : '00');
    elseif (preg_match('/\b(\d{1,2})\s*h\s*(\d{2})?\b/iu', $t, $m)) $h = sprintf('%dh%s', $m[1], isset($m[2]) && $m[2] !== '' ? $m[2] : '00');
    return array('debut' => vsprintf('%04d-%02d-%02d', $d1), 'fin' => vsprintf('%04d-%02d-%02d', $d2 ? $d2 : $d1), 'heures' => $h);
}

/* ---------- classement : type de rendez-vous ----------
   stage-elite, stage, competition, formation, grades ; null = hors sujet (réunions, assemblées, colloques, autres disciplines…). */
function ffk_type($titre, $etiquettes) {
    $tt = ffk_sans_accents($titre);
    if (preg_match('/\b(reunion|assemblee|comite directeur|colloque|seminaire des presidents|ag\b|election)/', $tt)) return null;
    // d'abord la catégorie donnée par la fédération, si elle est claire
    foreach ($etiquettes as $e) {
        $e = ffk_sans_accents($e);
        if (preg_match('/^entrainements? elite$/', $e)) return 'stage-elite';
        if (preg_match('/^(competitions?( nationales?)?|interclubs?|championnats?.*)$/', $e)) return 'competition';
        if (preg_match('/^(formations?( continues?)?|arbitrage)$/', $e)) return 'formation';
        if (preg_match('/^grades?$/', $e)) return 'grades';
        if (preg_match('/^stages?$/', $e)) return 'stage';
    }
    $t = ffk_sans_accents($titre . ' | ' . implode(' | ', $etiquettes));
    if (preg_match('/\belite\b|\bentrainement elite\b|\bpole\b|\bsection sportive\b/', $t)) return 'stage-elite';
    if (preg_match('/championnat|coupe|open\b|interclub|inter club|competition|tournoi|karate 1|premier league|serie a|france karate|selection|ranking/', $t)) return 'competition';
    if (preg_match('/formation|arbitrage|arbitre|juge|examen|diplome|dif\b|daf\b|cqp|bpjeps|vae|jury|continue/', $t)) return 'formation';
    if (preg_match('/passages? de grades?|\bgrades?\b|\bdan\b|\bkyu\b/', $tt)) return 'grades';
    if (preg_match('/\bstages?\b|rassemblement|regroupement|entrainement/', $t)) return 'stage';
    if (preg_match('/\bgrades?\b/', $t)) return 'grades';
    return null;
}

/* Rendez-vous d'une autre discipline que le karaté (le club pratique le karaté-do) : écartés.
   Disciplines de la FFK relevées dans l'agenda (20/11/2026) : Karaté, France Karaté (équipe de France), Wushu,
   Arts martiaux vietnamiens, Karaté contact, Karaté mix, Yoseikan Budo, Krav-Maga, Full contact, Arts martiaux du Sud-Est asiatique.
   Sans discipline (sites des comités et ligues, formations, grades…), le titre décide. Appliqué aux trois niveaux. */
function ffk_autre_discipline($titre, $etiquettes) {
    $karate = false; $autre = false;
    foreach ($etiquettes as $e) {
        $e = ffk_sans_accents($e);
        if (in_array($e, array('karate', 'france karate', 'para karate', 'karate do', 'kata', 'kumite'))) $karate = true;
        elseif (preg_match('/wushu|vietnam|karate contact|karate mix|yoseikan|krav|full contact|sud-est|tai ?jitsu|kenpo|kung|sanda|qi gong|tai chi|body karate|karate jutsu|disciplines associees/', $e)) $autre = true;
    }
    // le titre qui nomme une autre discipline l'emporte (« Stage national de Nihon Tai Jitsu » classé aussi Karaté)
    if (preg_match('/wushu|lei tai|sanda|taolu|vietnam|\bamv\b|yoseikan|krav|full contact|karate contact|karate mix|kick|tai ?jitsu|nihon|kenpo|kung ?fu|qi ?gong|tai ?chi|disciplines associees|body karate|karate jutsu|sud-est/', ffk_sans_accents($titre))) return true;
    return $autre && !$karate;
}

/* ---------- lecture des pages HTML (sites des comités et ligues) ---------- */

function ffk_dom($html) {
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    return $d;
}
function ffk_absolu($href, $base) {
    if (preg_match('#^https?://#i', $href)) return $href;
    $p = parse_url($base);
    $racine = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($href, '//') === 0) return $p['scheme'] . ':' . $href;
    if (strpos($href, '/') === 0) return $racine . $href;
    return rtrim(preg_replace('#/[^/]*$#', '/', $base), '/') . '/' . $href;
}

/* Liste des rendez-vous d'une page : array de array(url, titre, debut, fin, heures, lieu, etiquettes[]) + 'suivante' (URL page suivante ou '').
   Méthode : chaque lien vers une fiche ; on remonte dans la page tant que le bloc ne contient que cette fiche : c'est le bloc du rendez-vous. */
/* Mise en page des sites des comités et ligues (relevée le 20/11/2026) :
   <a href="…/event/…" class="hentry upcomming"><div class="date"><time datetime="2026-12-06">Dim. 6 Décembre 2026</time><br> de 08h30 à 11h30</div>
   <div class="event_info"><div class="title">…</div><div class="address">…</div><div class="cat">Entrainement Elite</div></div></a> */
function ffk_lire_hentry($xp, $base, $motif_fiche) {
    $res = array();
    $classe = function ($c) { return "contains(concat(' ', normalize-space(@class), ' '), ' $c ')"; };
    foreach ($xp->query('//a[' . $classe('hentry') . '][@href]') as $a) {
        $u = rtrim(ffk_absolu(trim($a->getAttribute('href')), $base), '/') . '/';
        if (!preg_match($motif_fiche, $u)) continue;
        $champ = function ($c) use ($xp, $a, $classe) { $n = $xp->query('.//*[' . $classe($c) . ']', $a)->item(0); return $n ? ffk_texte($n->textContent) : ''; };
        $t = $xp->query('.//time[@datetime]', $a)->item(0);
        $date_txt = $champ('date');
        $debut = $t && preg_match('/^\d{4}-\d{2}-\d{2}/', $t->getAttribute('datetime'), $m) ? $m[0] : '';
        $d = ffk_dates($date_txt);
        if ($debut === '' && $d) $debut = $d['debut'];
        if ($debut === '') continue;
        $fin = $d && $d['fin'] > $debut ? $d['fin'] : $debut;
        $titre = $champ('title');
        if ($titre === '') continue;
        $cat = $champ('cat');
        $res[] = array('url' => $u, 'titre' => $titre, 'debut' => $debut, 'fin' => $fin, 'heures' => $d ? $d['heures'] : '',
                       'lieu' => $champ('address'), 'etiquettes' => $cat !== '' ? array($cat) : array());
    }
    return $res;
}

function ffk_lire_page($html, $base, $motif_fiche) {
    $dom = ffk_dom($html);
    $xp = new DOMXPath($dom);
    $precis = ffk_lire_hentry($xp, $base, $motif_fiche);   // si la mise en page connue est là ; sinon méthode générale ci-dessous
    $par_url = array();
    foreach ($xp->query('//a[@href]') as $a) {
        $u = preg_replace('/#.*$/', '', ffk_absolu(trim($a->getAttribute('href')), $base));
        if (!preg_match($motif_fiche, $u)) continue;
        $u = rtrim($u, '/') . '/';
        $par_url[$u][] = $a;
    }
    $fiches_de = function ($n) use ($xp, $motif_fiche, $base) {   // URLs de fiches contenues dans un élément
        $s = array();
        foreach ($xp->query('.//a[@href]', $n) as $a) {
            $u = preg_replace('/#.*$/', '', ffk_absolu(trim($a->getAttribute('href')), $base));
            if (preg_match($motif_fiche, $u)) $s[rtrim($u, '/') . '/'] = 1;
        }
        return $s;
    };
    $res = array();
    if ($precis) $par_url = array();
    foreach ($par_url as $u => $liens) {
        $bloc = $liens[0];
        for ($n = $liens[0]->parentNode; $n && $n->nodeType === XML_ELEMENT_NODE && $n->nodeName !== 'body'; $n = $n->parentNode) {
            if (count($fiches_de($n)) > 1) break;
            $bloc = $n;
        }
        $texte = ffk_texte($dom->saveHTML($bloc));
        // titre, dans l'ordre : intertitre du bloc ; texte de lien qui n'est ni une date ni un bouton ;
        // plus long morceau de texte dans le lien (lien qui englobe toute la carte) ; à défaut, l'adresse de la fiche
        $bouton = '/^(en savoir plus|voir|lire la suite|d[ée]tails?|informations?|programme|inscription|r[ée]sultats?|boutique)$/iu';
        $titre = '';
        foreach ($xp->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6', $bloc) as $h) { $x = ffk_texte($h->textContent); if ($x !== '' && !ffk_dates($x)) { $titre = $x; break; } }
        if ($titre === '') foreach ($liens as $a) {
            $x = ffk_texte($a->textContent);
            if (mb_strlen($x, 'UTF-8') > mb_strlen($titre, 'UTF-8') && !preg_match($bouton, $x) && !ffk_dates($x)) $titre = $x;
        }
        if ($titre === '') foreach ($liens as $a) foreach ($xp->query('.//*[not(*)]', $a) as $n) {
            $x = ffk_texte($n->textContent);
            if (mb_strlen($x, 'UTF-8') > mb_strlen($titre, 'UTF-8') && !ffk_dates($x) && !preg_match('/^\d{1,2}\s*h/u', $x)) $titre = $x;
        }
        if ($titre === '') $titre = ucfirst(str_replace('-', ' ', basename(rtrim($u, '/'))));
        // intertitre de mois au-dessus du bloc (« Décembre 2026 ») : sert si la date du bloc n'a pas d'année
        $mois = 0; $annee = 0;
        foreach ($xp->query('preceding::*[self::h2 or self::h3 or self::h4 or self::h5 or self::strong or self::span or self::div][position() <= 60]', $bloc) as $h) {
            $x = ffk_texte($h->textContent);
            if (mb_strlen($x, 'UTF-8') < 30 && preg_match('/^' . FFK_MOIS_RE . '[a-zé]*\s+(\d{4})$/iu', $x, $m)) { $mois = ffk_mois($m[1]); $annee = (int)$m[2]; }
        }
        $sans_titre = trim(str_replace($titre, ' ', $texte));
        $dates = ffk_dates($sans_titre, $mois, $annee);
        if (!$dates) $dates = ffk_dates($texte, $mois, $annee);
        if (!$dates) continue;
        // étiquettes : classes du bloc (« category-stages », « event_category-competitions »…) et petits textes connus
        $etiquettes = array();
        foreach (array_merge(array($bloc), iterator_to_array($xp->query('.//*[@class]', $bloc))) as $n)
            if ($n instanceof DOMElement && preg_match_all('/(?:category|categorie|cat|tag|type|term)[-_]([a-z0-9-]+)/i', $n->getAttribute('class'), $mm))
                foreach ($mm[1] as $c) $etiquettes[] = str_replace('-', ' ', $c);
        foreach ($xp->query('.//*[not(*)]', $bloc) as $n) {
            $x = ffk_texte($n->textContent);
            if ($x !== '' && mb_strlen($x, 'UTF-8') <= 30 && $x !== $titre && !ffk_dates($x) && !preg_match('/\d/', $x)) $etiquettes[] = $x;
        }
        // lieu : morceau de texte avec une adresse ou un nom de salle, sinon ce qui suit les heures
        $lieu = '';
        foreach ($xp->query('.//*[not(*)]', $bloc) as $n) {
            $x = ffk_texte($n->textContent);
            if ($x !== $titre && mb_strlen($x, 'UTF-8') > 4 && preg_match('/dojo|gymnase|salle|rue|avenue|boulevard|complexe|palais|cdos|creps|\b\d{5}\b|\(\d{2}\)|visio/iu', $x) && !ffk_dates($x)) { $lieu = $x; break; }
        }
        $res[] = array('url' => $u, 'titre' => $titre, 'debut' => $dates['debut'], 'fin' => $dates['fin'], 'heures' => $dates['heures'],
                       'lieu' => $lieu, 'etiquettes' => array_values(array_unique($etiquettes)));
    }
    // page suivante : lien rel=next, « Suivant », ou /page/N+1/
    $suivante = '';
    foreach ($xp->query('//a[@rel="next"]|//link[@rel="next"]|//a[contains(@class,"next")]') as $a) { $suivante = ffk_absolu($a->getAttribute('href'), $base); break; }
    if ($suivante === '') foreach ($xp->query('//a[@href]') as $a) if (preg_match('/^suivant/iu', ffk_texte($a->textContent))) { $suivante = ffk_absolu($a->getAttribute('href'), $base); break; }
    return array('evenements' => $precis ? $precis : $res, 'suivante' => $suivante);
}

/* ---------- agenda .ics (calendrier national) ---------- */

function ffk_lire_ics($ics) {
    $ics = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $ics));   // lignes repliées
    $res = array();
    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocs)) return $res;
    $val = function ($b, $cle) {
        if (!preg_match('/^' . $cle . '(;[^:\n]*)?:(.*)$/m', $b, $m)) return '';
        return trim(str_replace(array('\\,', '\\;', '\\n', '\\N', '\\\\'), array(',', ';', ' ', ' ', '\\'), $m[2]));
    };
    foreach ($blocs[1] as $b) {
        $deb = $val($b, 'DTSTART'); $fin = $val($b, 'DTEND');
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2}))?/', $deb, $d)) continue;
        $journee = !isset($d[4]) || $d[4] === '';
        $f = preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2}))?/', $fin, $ff) ? $ff : $d;
        $fin_iso = sprintf('%s-%s-%s', $f[1], $f[2], $f[3]);
        if ($journee && $fin) $fin_iso = date('Y-m-d', strtotime($fin_iso . ' -1 day'));   // DTEND exclusif pour les journées entières
        if ($fin_iso < sprintf('%s-%s-%s', $d[1], $d[2], $d[3])) $fin_iso = sprintf('%s-%s-%s', $d[1], $d[2], $d[3]);
        // la description donne « Discipline : … » et « Famille : … » (séparés par \n) : ajoutés aux étiquettes
        $etiq = array_values(array_filter(array_map('trim', explode(',', $val($b, 'CATEGORIES')))));
        if (preg_match('/^DESCRIPTION(?:;[^:\n]*)?:(.*)$/m', $b, $dm))
            foreach (explode('\n', $dm[1]) as $ligne) if (preg_match('/^\s*(Discipline|Famille)\s*:\s*(.+)$/u', str_replace('\,', ',', $ligne), $lm))
                foreach (explode(',', $lm[2]) as $x) $etiq[] = trim($x);
        $res[] = array('url' => $val($b, 'URL'), 'titre' => $val($b, 'SUMMARY'), 'debut' => sprintf('%s-%s-%s', $d[1], $d[2], $d[3]), 'fin' => $fin_iso,
                       'heures' => $journee ? '' : sprintf('%dh%s', $d[4], $d[5]), 'lieu' => $val($b, 'LOCATION'),
                       'etiquettes' => array_values(array_unique(array_filter($etiq))));
    }
    return $res;
}

/* Textes saisis tout en majuscules sur certains sites (« CHAMPIONNAT COMBAT BFC VETERANS ») : remis en casse normale.
   Titre : majuscule au début seulement ; lieu : majuscule à chaque mot (sauf petits mots). Les sigles sans voyelle (BFC, PB, MCJS) restent. */
function ffk_casse($s, $lieu = false) {
    if (!preg_match('/\p{L}/u', $s) || mb_strtoupper($s, 'UTF-8') !== $s) return $s;
    $petits = array('le', 'la', 'les', 'de', 'du', 'des', 'et', 'à', 'au', 'aux', 'en', 'sur', "d'", "l'");
    $mots = preg_split('/(\s+|-|\(|\))/u', mb_strtolower($s, 'UTF-8'), -1, PREG_SPLIT_DELIM_CAPTURE);
    $n = 0;
    foreach ($mots as $i => $m) {
        if ($m === '' || !preg_match('/\p{L}/u', $m)) continue;
        if (!preg_match('/[aeiouyàâäéèêëîïôöùûü]/u', $m)) $mots[$i] = mb_strtoupper($m, 'UTF-8');
        elseif ($n === 0 || ($lieu && !in_array($m, $petits))) $mots[$i] = mb_strtoupper(mb_substr($m, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($m, 1, null, 'UTF-8');
        $n++;
    }
    return implode('', $mots);
}

// « Dojo X - Ville (21) - Ville » : morceaux répétés retirés
function ffk_lieu($s) {
    $morceaux = array(); $vus = array();
    foreach (preg_split('/\s+-\s+/u', $s) as $m) {
        $cle = ffk_sans_accents(preg_replace('/\s*\(\d+\)\s*/', '', $m));
        $deja = false; foreach ($vus as $v) if (strpos($v, $cle) !== false) $deja = true;
        if (!$deja) { $morceaux[] = $m; $vus[] = $cle; }
    }
    return implode(' - ', $morceaux);
}

/* ---------- agenda .ics à s'abonner ----------
   Journées entières, ou heures locales (« 8h30 – 11h30 ») sans fuseau : lues comme l'heure de Paris par les téléphones français. */
function ffk_ics_texte($s) { return str_replace(array('\\', ';', ',', "\n"), array('\\\\', '\\;', '\\,', '\\n'), $s); }
function ffk_ics_plier($ligne) {   // lignes de 75 octets au plus, sans couper un caractère
    $out = ''; $l = 0; $n = strlen($ligne);
    for ($i = 0; $i < $n; ) {
        $c = ord($ligne[$i]); $t = $c < 0x80 ? 1 : ($c < 0xE0 ? 2 : ($c < 0xF0 ? 3 : 4));
        if ($l + $t > 74) { $out .= "\r\n "; $l = 1; }
        $out .= substr($ligne, $i, $t); $l += $t; $i += $t;
    }
    return $out . "\r\n";
}
function ffk_ics($evenements, $nom) {
    $NOMS_T = array('stage' => 'Stage', 'stage-elite' => 'Stage élite', 'competition' => 'Compétition', 'formation' => 'Formation', 'grades' => 'Grades');
    $NOMS_N = array('departemental' => 'Départemental (Territoire de Belfort)', 'regional' => 'Régional (Bourgogne-Franche-Comté)', 'national' => 'National');
    $l = array('BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//ASMB Karate Belfort//Agenda FFK//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
               'X-WR-CALNAME:' . ffk_ics_texte($nom), 'X-WR-TIMEZONE:Europe/Paris', 'REFRESH-INTERVAL;VALUE=DURATION:P1D', 'X-PUBLISHED-TTL:P1D');
    $stamp = gmdate('Ymd\THis\Z');
    foreach ($evenements as $e) {
        $l[] = 'BEGIN:VEVENT';
        $l[] = 'UID:' . substr(md5($e['url'] . $e['debut'] . $e['titre']), 0, 20) . '@asmb-agenda-ffk';
        $l[] = 'DTSTAMP:' . $stamp;
        $d = str_replace('-', '', $e['debut']);
        if ($e['debut'] === $e['fin'] && preg_match('/^(\d{1,2})h(\d{2})\s*–\s*(\d{1,2})h(\d{2})$/u', $e['heures'], $h)) {
            $l[] = sprintf('DTSTART:%sT%02d%02d00', $d, $h[1], $h[2]);
            $l[] = sprintf('DTEND:%sT%02d%02d00', $d, $h[3], $h[4]);
        } else {
            $l[] = 'DTSTART;VALUE=DATE:' . $d;
            $l[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($e['fin'] . ' +1 day'));
        }
        $l[] = 'SUMMARY:' . ffk_ics_texte($e['titre']);
        if ($e['lieu'] !== '') $l[] = 'LOCATION:' . ffk_ics_texte($e['lieu']);
        $l[] = 'DESCRIPTION:' . ffk_ics_texte($NOMS_T[$e['type']] . ' · ' . $NOMS_N[$e['niveau']] . "\nFiche : " . $e['url']);
        $l[] = 'URL:' . $e['url'];
        $l[] = 'CATEGORIES:' . ffk_ics_texte($NOMS_T[$e['type']]);
        // rappel la veille (24 h avant) : suivi par Apple Calendrier et Outlook ; Google Agenda applique ses propres rappels
        $l[] = 'BEGIN:VALARM'; $l[] = 'ACTION:DISPLAY'; $l[] = 'DESCRIPTION:' . ffk_ics_texte('Demain : ' . $e['titre']); $l[] = 'TRIGGER:-P1D'; $l[] = 'END:VALARM';
        $l[] = 'END:VEVENT';
    }
    $l[] = 'END:VCALENDAR';
    return implode('', array_map('ffk_ics_plier', $l));
}

/* ---------- tout récupérer ---------- */

/* Renvoie array('evenements' => [...], 'sources' => array(niveau => compte rendu)).
   Chaque rendez-vous : niveau, type, titre, debut, fin, heures, lieu, url (fiche sur le site de la fédération).
   Garde les rendez-vous d'aujourd'hui à dans 13 mois. $pages_max : pages lues par site (sécurité). */
function ffk_tout($pages_max = 6) {
    global $FFK_SOURCES;
    $aujourdhui = date('Y-m-d'); $limite = date('Y-m-d', strtotime('+13 months'));
    $tout = array(); $sources = array();
    foreach ($FFK_SOURCES as $niveau => $src) {
        $cr = array('pages' => 0, 'lus' => 0, 'gardes' => 0, 'erreur' => '', 'ms' => 0, 'moyen' => '');
        $bruts = array();
        $url = $src['url']; $vus = array();
        if ($niveau === 'national') {   // d'abord l'agenda .ics annoncé sur la page (« Mise à jour quotidienne »)
            $r = ffk_obtenir($url); $cr['ms'] += $r['ms']; $cr['moyen'] = $r['moyen'] . (empty($r['sans_verif']) ? '' : ' (sans vérif. certificat)');
            if ($r['code'] === 200 && preg_match_all('#https?://[^"\'\s<>]+\.ics#i', $r['corps'], $m)) {
                foreach (array_unique($m[0]) as $lien_ics) {
                    $ri = ffk_obtenir($lien_ics); $cr['ms'] += $ri['ms'];
                    if ($ri['code'] === 200) { $bruts = array_merge($bruts, ffk_lire_ics($ri['corps'])); $cr['ics'][] = basename($lien_ics); }
                }
            }
            if (!$bruts && $r['code'] === 200) { $p = ffk_lire_page($r['corps'], $url, $src['fiche']); $bruts = $p['evenements']; $cr['pages'] = 1; }
            if ($r['code'] !== 200) $cr['erreur'] = $r['erreur'] ? $r['erreur'] : 'HTTP ' . $r['code'];
        } else {
            while ($url && $cr['pages'] < $pages_max && !isset($vus[$url])) {
                $vus[$url] = 1;
                $r = ffk_obtenir($url); $cr['ms'] += $r['ms']; $cr['moyen'] = $r['moyen'] . (empty($r['sans_verif']) ? '' : ' (sans vérif. certificat)');
                if ($r['code'] !== 200) { if (!$cr['pages']) $cr['erreur'] = $r['erreur'] ? $r['erreur'] : 'HTTP ' . $r['code']; break; }
                $cr['pages']++;
                $p = ffk_lire_page($r['corps'], $url, $src['fiche']);
                $bruts = array_merge($bruts, $p['evenements']);
                $url = $p['suivante'];
            }
        }
        $cr['lus'] = count($bruts);
        $deja = array();
        foreach ($bruts as $e) {
            if ($e['fin'] < $aujourdhui || $e['debut'] > $limite || $e['titre'] === '') continue;
            if ((strtotime($e['fin']) - strtotime($e['debut'])) / 86400 > 21) continue;   // périodes d'inscription, VAE… : pas des rendez-vous
            if (ffk_autre_discipline($e['titre'], $e['etiquettes'])) continue;
            $type = ffk_type($e['titre'], $e['etiquettes']);
            if (!$type) continue;
            $cle = $e['url'] !== '' ? $e['url'] : $e['titre'] . $e['debut'];
            if (isset($deja[$cle])) continue; $deja[$cle] = 1;
            $tout[] = array('niveau' => $niveau, 'type' => $type, 'titre' => ffk_casse($e['titre']), 'debut' => $e['debut'], 'fin' => $e['fin'],
                            'heures' => $e['heures'], 'lieu' => ffk_lieu(ffk_casse($e['lieu'], true)), 'url' => $e['url'] !== '' ? $e['url'] : $src['url']);
            $cr['gardes']++;
        }
        $sources[$niveau] = $cr;
    }
    usort($tout, function ($a, $b) { return strcmp($a['debut'] . $a['niveau'], $b['debut'] . $b['niveau']); });
    return array('evenements' => $tout, 'sources' => $sources);
}
