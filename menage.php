<?php
/**
 * menage.php — inventaire des fichiers inutiles d'un compte d'hebergement.
 *
 * A DEPOSER dans public_html d'un domaine, a ouvrir dans le navigateur, et
 * A SUPPRIMER une fois le menage fini.
 *
 * PAR DEFAUT CE FICHIER NE SUPPRIME RIEN. Il lit, il mesure, il liste.
 * La suppression demande trois choses simultanement dans l'URL : la cle,
 * &action=supprimer&confirme=oui, et UN SEUL groupe a la fois. Un groupe
 * non listé dans $SUPPRIMABLES ne peut pas etre supprime par ce script,
 * meme avec la bonne cle : les archives, les dumps SQL et les sauvegardes
 * de plugins sont signales mais jamais effaces, parce qu'une archive est
 * souvent la seule copie qui reste de quelque chose.
 *
 * Ce script n'affiche JAMAIS le CONTENU d'un fichier. Il n'affiche que des
 * chemins, des tailles et des dates. Un outil de diagnostic qui imprime le
 * contenu de ce qu'il trouve finit toujours par imprimer un wp-config.php.
 */

/* ------------------------------------------------------------------ */
/* 1. CLE D'ACCES — A CHANGER AVANT DE TELEVERSER                     */
/* ------------------------------------------------------------------ */
$CLE = 'change-cette-cle-avant-de-televerser';

/* Sans la bonne cle : rien. Pas de message d'erreur detaille, pas de
   version, pas de chemin. Une page de diagnostic laissee en ligne sans
   cle est une carte du serveur offerte a n'importe qui. */
if (!isset($_GET['cle']) || !hash_equals($CLE, (string)$_GET['cle'])) {
    header('HTTP/1.1 403 Forbidden');
    header('X-Robots-Tag: noindex, nofollow');
    exit('403');
}
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=utf-8');
@set_time_limit(0);
@ini_set('memory_limit', '256M');

/* ------------------------------------------------------------------ */
/* 2. RACINE DU SCAN                                                  */
/* ------------------------------------------------------------------ */
/**
 * On remonte depuis le script jusqu'a trouver un dossier qui ressemble a
 * une racine de compte (il contient domains/ ou public_html/). A defaut,
 * on reste dans le dossier du script. On ne sort JAMAIS de la maison :
 * pas de / , pas de /home.
 */
function racine_compte($depart) {
    /* On cherche d'abord un dossier qui contient domains/ : c'est la racine
       du COMPTE, donc les ~29 domaines d'un coup. Un dossier qui contient
       public_html/ n'est que la racine d'UN domaine — on le garde en repli,
       jamais en premier choix, sinon le scan s'arrete au site courant. */
    $d = realpath($depart);
    $repli = null;
    for ($i = 0; $i < 8 && $d && $d !== '/' && $d !== ''; $i++) {
        if (is_dir($d . '/domains')) return $d;
        if ($repli === null && is_dir($d . '/public_html')) $repli = $d;
        $p = dirname($d);
        if ($p === $d) break;
        $d = $p;
    }
    return $repli ? $repli : realpath($depart);
}

$RACINE = racine_compte(__DIR__);
if (isset($_GET['racine'])) {
    /* Une racine fournie a la main doit rester DANS la racine detectee,
       sinon ce script devient un explorateur de serveur. */
    $demande = realpath((string)$_GET['racine']);
    if ($demande && strpos($demande . '/', rtrim($RACINE, '/') . '/') === 0) {
        $RACINE = $demande;
    }
}
$RACINE = rtrim($RACINE, '/');

$BUDGET  = max(5, min(180, (int)($_GET['budget'] ?? 25)));   /* secondes */
$PLAFOND = 600000;                                           /* fichiers  */
$DEBUT   = microtime(true);

/* ------------------------------------------------------------------ */
/* 3. GROUPES                                                         */
/* ------------------------------------------------------------------ */
/* 'sup' => true  : regenerable, supprimable par ce script.
   'sup' => false : signale seulement. Le script REFUSE de le supprimer. */
$GROUPES = array(
    'cache' => array(
        'titre' => 'Caches (se regenerent tout seuls)',
        'sup'   => true,
        'note'  => 'Vidable sans risque : WordPress et le cache serveur les reconstruisent a la premiere visite.',
    ),
    'upgrade' => array(
        'titre' => 'Restes de mises a jour (wp-content/upgrade)',
        'sup'   => true,
        'note'  => 'Dossiers temporaires laisses par les mises a jour de plugins et de themes. Toujours vides de sens une fois la mise a jour finie.',
    ),
    'journaux' => array(
        'titre' => 'Journaux d\'erreurs (error_log, debug.log, *.log)',
        'sup'   => true,
        'note'  => 'Souvent les plus gros fichiers d\'un compte. A LIRE avant de vider si un site plante : ils disent pourquoi.',
    ),
    'systeme' => array(
        'titre' => 'Dechets de systeme (.DS_Store, Thumbs.db, __MACOSX)',
        'sup'   => true,
        'note'  => 'Crees par macOS et Windows au moment du televersement. Aucun role sur le serveur.',
    ),
    'node' => array(
        'titre' => 'node_modules',
        'sup'   => true,
        'note'  => 'Dependances de developpement. Elles n\'ont rien a faire sur un hebergement de production.',
    ),
    'archives' => array(
        'titre' => 'Archives (.zip, .tar.gz, .bak, .old)',
        'sup'   => false,
        'note'  => 'SIGNALE, JAMAIS SUPPRIME. Une archive est souvent la seule copie restante de quelque chose. A toi de trancher, fichier par fichier.',
    ),
    'dumps' => array(
        'titre' => 'Dumps de base de donnees (.sql, .sql.gz)',
        'sup'   => false,
        'note'  => 'SIGNALE, JAMAIS SUPPRIME. Attention : un .sql pose dans un public_html se telecharge par n\'importe qui avec l\'URL. A deplacer hors du web, pas seulement a garder.',
    ),
    'sauvegardes' => array(
        'titre' => 'Sauvegardes de plugins (ai1wm, UpdraftPlus, WPvivid, Duplicator...)',
        'sup'   => false,
        'note'  => 'SIGNALE, JAMAIS SUPPRIME. C\'est en general le plus gros poste d\'un compte WordPress. A purger depuis l\'interface du plugin, qui sait ce qu\'il peut jeter.',
    ),
    'vcs' => array(
        'titre' => 'Dossiers .git / .svn dans un docroot',
        'sup'   => false,
        'note'  => 'SIGNALE, JAMAIS SUPPRIME. Deux problemes : le poids, et le fait qu\'un .git accessible en HTTP laisse telecharger tout l\'historique du code. A retirer a la main.',
    ),
    'suspects' => array(
        'titre' => 'Dossiers au nom d\'ancienne version (old, backup, copie, save...)',
        'sup'   => false,
        'note'  => 'SIGNALE, JAMAIS SUPPRIME. Le nom suggere une vieille copie, mais seul toi sais si un site tourne encore dessus.',
    ),
);

/* ------------------------------------------------------------------ */
/* 4. CLASSEMENT                                                      */
/* ------------------------------------------------------------------ */
/* Chemins qu'on ne classe jamais et dans lesquels on ne descend pas
   pour supprimer : le contenu televerse et la configuration. */
function protege($chemin) {
    $b = basename($chemin);
    if (in_array($b, array('wp-config.php', '.env', '.htaccess', '.htpasswd'), true)) return true;
    /* uploads/ contient les medias du site. Seul uploads/cache est du cache.
       L'exception doit accepter le DOSSIER lui-meme (« .../uploads/cache »,
       sans barre finale) autant que ce qu'il contient : avec une regle qui
       exige la barre, le dossier restait protege et n'etait jamais vide. */
    if (preg_match('#/wp-content/uploads/#', $chemin)
        && !preg_match('#/wp-content/uploads/(cache|wp-clone|ai1wm-backups)(/|$)#', $chemin)) return true;
    return false;
}

function classer_dossier($chemin, $nom) {
    $bas = strtolower($nom);
    if ($bas === 'node_modules')       return 'node';
    if ($bas === '__macosx')           return 'systeme';
    if ($bas === '.git' || $bas === '.svn') return 'vcs';
    if ($bas === 'ai1wm-backups' || $bas === 'updraft' || $bas === 'wpvivid'
        || $bas === 'backwpup' || $bas === 'backupbuddy' || $bas === 'duplicator'
        || $bas === 'backups' || $bas === 'wp-snapshots') return 'sauvegardes';

    /* Les caches : on vise le CONTENU de ces dossiers, pas leur presence. */
    if (preg_match('#/wp-content/(cache|litespeed|w3tc-cache|et-cache|cache-enabler)$#i', $chemin)) return 'cache';
    if (preg_match('#/wp-content/uploads/cache$#i', $chemin)) return 'cache';
    if (preg_match('#/wp-content/upgrade(-temp-backup)?$#i', $chemin)) return 'upgrade';

    if (preg_match('#^(old|ancien|ancienne|backup|bak|sauvegarde|copie|copy|save|_old|old_site|site_old|test|temp|tmp)$#', $bas)
        && preg_match('#/(public_html|domains/[^/]+)/[^/]+$#', $chemin)) return 'suspects';
    return null;
}

function classer_fichier($chemin, $nom) {
    $bas = strtolower($nom);
    if ($bas === '.ds_store' || $bas === 'thumbs.db' || $bas === 'desktop.ini') return 'systeme';
    if ($bas === 'error_log' || $bas === 'debug.log' || substr($bas, -4) === '.log') return 'journaux';
    if (preg_match('#\.sql(\.gz|\.zip|\.bz2)?$#', $bas)) return 'dumps';
    if (preg_match('#\.(zip|tar|tgz|tar\.gz|gz|bz2|rar|7z|bak|old)$#', $bas)) return 'archives';
    return null;
}

/* ------------------------------------------------------------------ */
/* 5. PARCOURS                                                        */
/* ------------------------------------------------------------------ */
$trouve   = array();     /* groupe => [ [chemin, octets, mtime], ... ] */
$poids    = array();     /* groupe => octets */
$parDom   = array();     /* 1er niveau sous la racine => octets */
$nbFich   = 0;
$tronque  = false;
$illisib  = 0;
$liens    = 0;

foreach ($GROUPES as $g => $_) { $trouve[$g] = array(); $poids[$g] = 0; }

function budget_depasse() {
    global $DEBUT, $BUDGET, $nbFich, $PLAFOND;
    return (microtime(true) - $DEBUT) > $BUDGET || $nbFich > $PLAFOND;
}

/** Taille recursive d'un dossier. Compte aussi dans $nbFich : le budget
 *  doit couvrir CE parcours aussi, sinon un node_modules de 80 000
 *  fichiers mange tout le temps sans que le compteur ne bouge. */
function taille_dossier($d) {
    global $nbFich, $tronque, $illisib;
    $tot = 0; $pile = array($d);
    while ($pile) {
        if (budget_depasse()) { $tronque = true; return $tot; }
        $cur = array_pop($pile);
        $dh = @opendir($cur);
        if (!$dh) { $illisib++; continue; }
        while (($n = readdir($dh)) !== false) {
            if ($n === '.' || $n === '..') continue;
            $p = $cur . '/' . $n;
            if (is_link($p)) continue;
            $nbFich++;
            if (is_dir($p)) $pile[] = $p;
            else { $s = @filesize($p); if ($s !== false) $tot += $s; }
        }
        closedir($dh);
    }
    return $tot;
}

function domaine_de($chemin) {
    global $RACINE;
    $rel = ltrim(substr($chemin, strlen($RACINE)), '/');
    $bouts = explode('/', $rel);
    if ($bouts[0] === 'domains' && isset($bouts[1])) return 'domains/' . $bouts[1];
    return $bouts[0] === '' ? '.' : $bouts[0];
}

function noter($groupe, $chemin, $octets, $mtime) {
    global $trouve, $poids, $parDom;
    $trouve[$groupe][] = array($chemin, $octets, $mtime);
    $poids[$groupe] += $octets;
}

$pile = array($RACINE);
while ($pile) {
    if (budget_depasse()) { $tronque = true; break; }
    $cur = array_pop($pile);
    $dh = @opendir($cur);
    if (!$dh) { $illisib++; continue; }
    while (($n = readdir($dh)) !== false) {
        if ($n === '.' || $n === '..') continue;
        $p = $cur . '/' . $n;
        if (is_link($p)) { $liens++; continue; }
        $nbFich++;
        $dom = domaine_de($p);
        if (is_dir($p)) {
            $g = classer_dossier($p, $n);
            if ($g && !protege($p)) {
                $t = taille_dossier($p);
                noter($g, $p, $t, @filemtime($p));
                if (!isset($parDom[$dom])) $parDom[$dom] = 0;
                $parDom[$dom] += $t;
                continue;                      /* on ne descend pas dedans */
            }
            $pile[] = $p;
        } else {
            $s = @filesize($p); if ($s === false) $s = 0;
            if (!isset($parDom[$dom])) $parDom[$dom] = 0;
            $parDom[$dom] += $s;
            $g = classer_fichier($p, $n);
            if ($g && !protege($p)) noter($g, $p, $s, @filemtime($p));
        }
    }
    closedir($dh);
}

/* ------------------------------------------------------------------ */
/* 6. SUPPRESSION (jamais par defaut)                                 */
/* ------------------------------------------------------------------ */
$SUPPRIMABLES = array();
foreach ($GROUPES as $g => $d) if ($d['sup']) $SUPPRIMABLES[] = $g;

$rapportSup = null;
$action  = (string)($_GET['action'] ?? '');
$groupeS = (string)($_GET['groupe'] ?? '');
$confirm = (string)($_GET['confirme'] ?? '');

/** Efface un fichier ou un arbre. Chaque cible est re-verifiee ici :
 *  dans la racine, non protegee, et toujours classee dans le groupe
 *  demande. Une liste calculee plus haut ne suffit pas — c'est la
 *  verification au moment de l'effacement qui compte. */
function effacer($chemin, $groupe, &$n, &$octets) {
    global $RACINE;
    $r = realpath($chemin);
    if (!$r || strpos($r . '/', $RACINE . '/') !== 0) return;
    if (protege($r)) return;
    if (is_dir($r)) {
        $attendu = classer_dossier($r, basename($r));
        /* Le contenu d'un cache s'efface ; le dossier lui-meme reste, pour
           que le plugin n'ait pas a le recreer avec les bons droits. */
        $racineDuGroupe = ($attendu === $groupe);
        $dh = @opendir($r);
        if (!$dh) return;
        $enfants = array();
        while (($e = readdir($dh)) !== false) if ($e !== '.' && $e !== '..') $enfants[] = $r . '/' . $e;
        closedir($dh);
        foreach ($enfants as $e) {
            if (protege($e)) continue;
            if (is_link($e)) { @unlink($e); continue; }
            if (is_dir($e)) effacer_arbre($e, $n, $octets);
            else { $s = @filesize($e); if (@unlink($e)) { $n++; $octets += (int)$s; } }
        }
        if (!$racineDuGroupe) @rmdir($r);
        else if ($groupe === 'node' || $groupe === 'systeme' || $groupe === 'vcs') @rmdir($r);
        return;
    }
    if (classer_fichier($r, basename($r)) !== $groupe) return;
    $s = @filesize($r);
    if (@unlink($r)) { $n++; $octets += (int)$s; }
}

function effacer_arbre($d, &$n, &$octets) {
    global $RACINE;
    $r = realpath($d);
    if (!$r || strpos($r . '/', $RACINE . '/') !== 0) return;
    $dh = @opendir($r);
    if (!$dh) return;
    $enfants = array();
    while (($e = readdir($dh)) !== false) if ($e !== '.' && $e !== '..') $enfants[] = $r . '/' . $e;
    closedir($dh);
    foreach ($enfants as $e) {
        if (protege($e)) continue;
        if (is_link($e)) { @unlink($e); continue; }
        if (is_dir($e)) effacer_arbre($e, $n, $octets);
        else { $s = @filesize($e); if (@unlink($e)) { $n++; $octets += (int)$s; } }
    }
    @rmdir($r);
}

if ($action === 'supprimer') {
    if ($confirm !== 'oui') {
        $rapportSup = array('err' => 'Il manque &confirme=oui dans l\'URL. Rien n\'a ete touche.');
    } elseif (!in_array($groupeS, $SUPPRIMABLES, true)) {
        $rapportSup = array('err' => 'Le groupe « ' . htmlspecialchars($groupeS)
            . ' » n\'est pas supprimable par ce script. Groupes autorises : '
            . implode(', ', $SUPPRIMABLES) . '. Rien n\'a ete touche.');
    } else {
        $n = 0; $oct = 0;
        foreach ($trouve[$groupeS] as $it) effacer($it[0], $groupeS, $n, $oct);
        $rapportSup = array('groupe' => $groupeS, 'n' => $n, 'octets' => $oct);
        $ligne = sprintf("%s\t%s\t%d fichiers\t%d octets\n",
            date('c'), $groupeS, $n, $oct);
        @file_put_contents(__DIR__ . '/menage-journal.txt', $ligne, FILE_APPEND);
        /* La liste affichee doit refleter l'apres, pas l'avant. */
        $trouve[$groupeS] = array(); $poids[$groupeS] = 0;
    }
}

/* ------------------------------------------------------------------ */
/* 7. AFFICHAGE                                                       */
/* ------------------------------------------------------------------ */
function ko($o) {
    $u = array('o', 'Ko', 'Mo', 'Go', 'To'); $i = 0;
    while ($o >= 1024 && $i < 4) { $o /= 1024; $i++; }
    return ($i === 0 ? (int)$o : number_format($o, 1, ',', ' ')) . ' ' . $u[$i];
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$MAXLIGNES = 150;
$urlBase = '?cle=' . rawurlencode($CLE);
$totalRecup = 0;
foreach ($GROUPES as $g => $d) if ($d['sup']) $totalRecup += $poids[$g];
arsort($parDom);
?>
<!doctype html><html lang="fr"><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Menage — inventaire</title>
<style>
 body{font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;padding:24px;
      background:#faf9fc;color:#1b1922;max-width:1100px}
 h1{font-size:22px;margin:0 0 4px} h2{font-size:16px;margin:28px 0 6px}
 .sub{color:#6b6676;margin:0 0 18px}
 table{border-collapse:collapse;width:100%;font-size:13px}
 td,th{border-bottom:1px solid #e8e6ee;padding:5px 8px;text-align:left;vertical-align:top}
 th{background:#f2f0f6;font-weight:600} td.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
 code{font:12px/1.4 ui-monospace,Menlo,Consolas,monospace;word-break:break-all}
 .note{background:#f2f0f6;border-left:3px solid #b4b0be;padding:8px 12px;margin:6px 0 10px;color:#4a4653}
 .danger{border-left-color:#a33;background:#fdf3f3}
 .ok{border-left-color:#2b7;background:#f2fbf6}
 .btn{display:inline-block;background:#1b1922;color:#fff;text-decoration:none;
      padding:6px 12px;border-radius:4px;font-size:13px}
 .btn.off{background:#c9c6d1;color:#5a5665;pointer-events:none}
 .tot{font-size:15px}
</style>
<h1>Menage — inventaire du compte</h1>
<p class="sub">Racine analysee : <code><?= h($RACINE) ?></code><br>
<?= number_format($nbFich, 0, ',', ' ') ?> entrees parcourues en
<?= number_format(microtime(true) - $DEBUT, 1, ',', ' ') ?> s<?php
if ($liens) echo ' — ' . $liens . ' lien(s) symbolique(s) ignore(s)';
if ($illisib) echo ' — ' . $illisib . ' dossier(s) illisible(s)';
?>.</p>

<?php if ($tronque): ?>
<div class="note danger"><strong>Analyse incomplete.</strong> Le budget de
<?= $BUDGET ?> s a ete atteint : ce qui est affiche ci-dessous est un
sous-ensemble, pas le total du compte. Relance avec un budget plus large,
par exemple <code>&amp;budget=120</code>, ou vise un domaine precis avec
<code>&amp;racine=<?= h($RACINE) ?>/domains/&lt;domaine&gt;</code>.</div>
<?php endif; ?>

<?php if ($rapportSup): ?>
  <?php if (isset($rapportSup['err'])): ?>
  <div class="note danger"><?= $rapportSup['err'] ?></div>
  <?php else: ?>
  <div class="note ok"><strong>Supprime :</strong> groupe
  « <?= h($rapportSup['groupe']) ?> », <?= number_format($rapportSup['n'], 0, ',', ' ') ?>
  fichier(s), <?= ko($rapportSup['octets']) ?> liberes. Consigne dans
  <code>menage-journal.txt</code>.</div>
  <?php endif; ?>
<?php endif; ?>

<div class="note tot"><strong>Recuperable sans risque :
<?= ko($totalRecup) ?></strong> (caches, restes de mises a jour, journaux,
dechets systeme, node_modules). Le reste de la liste est signale mais ne
sera pas supprime par ce script.</div>

<h2>Poids par domaine</h2>
<table><tr><th>Dossier</th><th class="n">Taille</th></tr>
<?php $i = 0; foreach ($parDom as $d => $o): if (++$i > 40) break; ?>
<tr><td><code><?= h($d) ?></code></td><td class="n"><?= ko($o) ?></td></tr>
<?php endforeach; ?>
</table>
<p class="sub" style="margin-top:6px">Ces totaux ne comptent que ce qui a ete
parcouru<?= $tronque ? ' avant l\'arret du budget' : '' ?>.</p>

<?php foreach ($GROUPES as $g => $d):
    $items = $trouve[$g];
    usort($items, function ($a, $b) { return $b[1] <=> $a[1]; });
    $cache = max(0, count($items) - $MAXLIGNES); ?>
<h2><?= h($d['titre']) ?> — <?= count($items) ?> element(s), <?= ko($poids[$g]) ?></h2>
<div class="note<?= $d['sup'] ? '' : ' danger' ?>"><?= h($d['note']) ?></div>
<?php if ($d['sup'] && $items): ?>
<p><a class="btn" href="<?= h($urlBase) ?>&amp;action=supprimer&amp;groupe=<?= h($g) ?>&amp;confirme=oui"
   onclick="return confirm('Supprimer definitivement le groupe <?= h($g) ?> ? Cette action est irreversible.')">Supprimer ce groupe</a></p>
<?php elseif ($d['sup']): ?>
<p><span class="btn off">Rien a supprimer</span></p>
<?php endif; ?>
<?php if ($items): ?>
<table><tr><th>Chemin</th><th class="n">Taille</th><th class="n">Modifie</th></tr>
<?php foreach (array_slice($items, 0, $MAXLIGNES) as $it): ?>
<tr><td><code><?= h(ltrim(substr($it[0], strlen($RACINE)), '/')) ?></code></td>
    <td class="n"><?= ko($it[1]) ?></td>
    <td class="n"><?= $it[2] ? date('Y-m-d', $it[2]) : '—' ?></td></tr>
<?php endforeach; ?>
</table>
<?php if ($cache): ?>
<p class="sub"><?= $cache ?> ligne(s) supplementaire(s) non affichee(s) — elles
sont comptees dans le total ci-dessus, seul l'affichage est limite a
<?= $MAXLIGNES ?> lignes par groupe.</p>
<?php endif; ?>
<?php endif; ?>
<?php endforeach; ?>

<h2>Quand c'est fini</h2>
<div class="note danger">Supprime <code>menage.php</code> et
<code>menage-journal.txt</code> du serveur. Un outil qui liste les fichiers
d'un compte n'a rien a faire en ligne une fois le menage termine, meme
protege par une cle.</div>
</html>
