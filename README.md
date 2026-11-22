# Agenda FFK de l'ASMB Karaté-do Belfort

Robot qui lit chaque mois les calendriers de la Fédération française de karaté et publie `agenda.json`,
affiché dans la rubrique « Agenda fédéral » du site du club (https://asmbkaratedo.pages-perso.free.fr/).

| Niveau | Source |
|---|---|
| Départemental | https://sites.ffkarate.fr/territoiredebelfort/calendrier/ |
| Régional | https://sites.ffkarate.fr/bourgognefranchecomte/calendrier/ |
| National | https://www.ffkarate.fr/calendrier/ (fichier agenda .ics de la saison) |

Pourquoi ici et pas sur le site : l'hébergement Free du club n'a pas le droit de se connecter à d'autres sites.

## Fonctionnement

- `.github/workflows/agenda.yml` lance `php robot.php` le 1er de chaque mois, et à la demande
  (onglet **Actions › Agenda FFK › Run workflow**).
- `ffk.php` télécharge et lit les pages, classe chaque rendez-vous :
  niveau (`departemental`, `regional`, `national`) et type (`stage`, `stage-elite`, `competition`, `formation`, `grades`).
  Les réunions, assemblées et colloques sont écartés ; au national, les autres disciplines (wushu, yoseikan…) aussi.
- `robot.php` écrit `agenda.json`. Si une source ne répond pas, ses rendez-vous précédents encore à venir sont gardés.
  Si les trois échouent, le robot s'arrête en erreur et GitHub prévient par e-mail.
- Le site lit `https://raw.githubusercontent.com/Milsdref31/asmb-agenda-ffk/main/agenda.json` (rien à installer sur Free).
- `ics/<niveau>-<type>.ics` (« tous » pour tout) : agendas à s'abonner depuis un téléphone ou Google Agenda, un par choix de filtres du site.

## Format de `agenda.json`

```json
{ "version": 1, "majLe": "2026-12-01T04:17:00Z",
  "sources": { "departemental": { "ok": true, "nb": 9, "pages": 2, "erreur": "" } },
  "evenements": [ { "niveau": "departemental", "type": "stage-elite", "titre": "Entrainement élite",
                    "debut": "2026-12-06", "fin": "2026-12-06", "heures": "8h30 – 11h30",
                    "lieu": "Dojo Serge Merlet, Belfort", "url": "https://sites.ffkarate.fr/territoiredebelfort/event/…" } ] }
```

Si la FFK change ses pages et que l'agenda se vide, c'est `ffk_lire_page()` dans `ffk.php` qu'il faut adapter.
Essai sur son ordinateur : `php robot.php` (PHP 5.6 à 8.x, extensions curl, dom, mbstring).

## Calendrier scolaire (suivi des jeunes)

`vacances.php` lit chaque mois le calendrier scolaire officiel de la zone A (académie de Besançon, donc Territoire de Belfort)
sur data.education.gouv.fr et écrit `vacances.json`. Le suivi des jeunes du site s'en sert pour ses périodes
(de la reprise des cours jusqu'aux vacances suivantes). Si la source ne répond pas, le fichier précédent est gardé.
