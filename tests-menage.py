# -*- coding: utf-8 -*-
"""Controles de menage.php.

RIEN ICI NE TOUCHE AU COMPTE DU CLIENT. Chaque controle reconstruit un faux
compte d'hebergement dans un dossier temporaire, y copie menage.php, et
verifie le comportement dessus. Tester une regle de protection sur ce
qu'elle est censee proteger, c'est risquer de le detruire pour prouver
qu'il est protege.

Le point le plus important est le controle 4 : apres un simple inventaire,
TOUS les fichiers doivent encore etre la. Un outil de menage dont le mode
lecture efface quelque chose est pire que pas d'outil du tout.
"""

import json
import os
import shutil
import subprocess
import sys
import tempfile

ICI = os.path.dirname(os.path.abspath(__file__))
CLE = 'change-cette-cle-avant-de-televerser'

_ok = [0]
_ko = [0]


def t(nom, cond, detail=''):
    if cond:
        _ok[0] += 1
        print('  ok   %s' % nom)
    else:
        _ko[0] += 1
        print('  ECHEC %s %s' % (nom, ('-> ' + detail) if detail else ''))


# ---------------------------------------------------------------- fixture

# Le contenu de wp-config.php porte une chaine temoin : si elle apparait un
# jour dans la sortie HTML, c'est que le script imprime le contenu des
# fichiers qu'il trouve, et il devient une fuite au lieu d'un diagnostic.
TEMOIN = 'MOT-DE-PASSE-TEMOIN-NE-DOIT-JAMAIS-SORTIR'

ARBRE = {
    'domains/site-a.com/public_html/index.php': '<?php // site',
    'domains/site-a.com/public_html/wp-config.php':
        "<?php define('DB_PASSWORD','%s');" % TEMOIN,
    'domains/site-a.com/public_html/.htaccess': '# rewrite',
    'domains/site-a.com/public_html/error_log': 'x' * 5000,
    'domains/site-a.com/public_html/debug.log': 'y' * 100,
    'domains/site-a.com/public_html/.DS_Store': 'z' * 30,
    'domains/site-a.com/public_html/sauvegarde-2024.zip': 'a' * 9000,
    'domains/site-a.com/public_html/base.sql': 'b' * 4000,
    'domains/site-a.com/public_html/wp-content/cache/page/a.html': 'c' * 2000,
    'domains/site-a.com/public_html/wp-content/cache/page/b.html': 'c' * 2000,
    'domains/site-a.com/public_html/wp-content/litespeed/x.data': 'd' * 1000,
    'domains/site-a.com/public_html/wp-content/upgrade/tmp/plugin.php': 'e' * 700,
    'domains/site-a.com/public_html/wp-content/uploads/2024/photo.jpg': 'f' * 8000,
    'domains/site-a.com/public_html/wp-content/uploads/cache/thumb.jpg': 'g' * 600,
    'domains/site-a.com/public_html/wp-content/plugins/vrai/vrai.php': '<?php',
    'domains/site-a.com/public_html/wp-content/ai1wm-backups/site.wpress': 'h' * 12000,
    'domains/site-a.com/public_html/node_modules/pkg/index.js': 'i' * 3000,
    'domains/site-a.com/public_html/node_modules/pkg/lisez.md': 'i' * 300,
    'domains/site-a.com/public_html/.git/config': 'j' * 200,
    'domains/site-a.com/public_html/old/vieux.php': 'k' * 400,
    'domains/site-a.com/public_html/__MACOSX/._truc': 'l' * 50,
    'domains/site-b.net/public_html/index.php': '<?php // site b',
    'domains/site-b.net/public_html/Thumbs.db': 'm' * 90,
}


def batir():
    base = tempfile.mkdtemp(prefix='menage-fixture-')
    for rel, contenu in ARBRE.items():
        p = os.path.join(base, rel)
        os.makedirs(os.path.dirname(p), exist_ok=True)
        with open(p, 'w') as f:
            f.write(contenu)
    cible = os.path.join(base, 'domains/site-a.com/public_html/menage.php')
    shutil.copy(os.path.join(ICI, 'menage.php'), cible)
    return base


def appel(base, params):
    """Execute menage.php avec un $_GET simule. Retourne (code, html)."""
    dossier = os.path.join(base, 'domains/site-a.com/public_html')
    p = subprocess.run(
        ['php', os.path.join(ICI, 'runner.php'), json.dumps(params), dossier],
        capture_output=True, text=True, timeout=120)
    return p.returncode, p.stdout + p.stderr


def existe(base, rel):
    return os.path.exists(os.path.join(base, rel))


def inventaire(base):
    """Liste tous les chemins relatifs presents, pour comparer avant/apres."""
    out = set()
    for rac, dirs, fics in os.walk(base):
        for n in list(dirs) + fics:
            out.add(os.path.relpath(os.path.join(rac, n), base))
    return out


# ---------------------------------------------------------------- controles

print('\n-- acces --')
base = batir()
_, sans = appel(base, {})
t('sans cle : refuse', '403' in sans and 'Menage' not in sans)
t('sans cle : ne revele aucun chemin', base not in sans)
_, faux = appel(base, {'cle': 'mauvaise'})
t('mauvaise cle : refuse', '403' in faux and 'Menage' not in faux)

print('\n-- inventaire --')
avant = inventaire(base)
_, html = appel(base, {'cle': CLE, 'budget': '60'})
t('la page s affiche', 'Menage' in html and 'Poids par domaine' in html)
apres = inventaire(base)
t('un inventaire ne supprime RIEN (%d entrees avant, %d apres)'
  % (len(avant), len(apres)), avant == apres,
  str(sorted(avant - apres))[:200])

t('le contenu des fichiers n est jamais imprime', TEMOIN not in html)
t('wp-config.php n est pas classe comme dechet',
  'wp-config.php</code>' not in html)

for nom, motif in [('error_log', 'error_log'),
                   ('debug.log', 'debug.log'),
                   ('.DS_Store', '.DS_Store'),
                   ('node_modules', 'node_modules'),
                   ('cache', 'wp-content/cache'),
                   ('upgrade', 'wp-content/upgrade'),
                   ('archive zip', 'sauvegarde-2024.zip'),
                   ('dump sql', 'base.sql'),
                   ('ai1wm-backups', 'ai1wm-backups'),
                   ('.git', '.git'),
                   ('dossier old', 'old</code>'),
                   ('__MACOSX', '__MACOSX')]:
    t('repere %s' % nom, motif in html)

t('la photo d uploads n est jamais listee comme dechet',
  '2024/photo.jpg' not in html)
t('uploads/cache est bien vu comme du cache',
  'uploads/cache' in html)
t('les deux domaines apparaissent dans le poids par domaine',
  'domains/site-a.com' in html and 'domains/site-b.net' in html)
t('la racine remonte au compte, pas au domaine courant',
  html.count('domains/site-b.net') >= 1)

print('\n-- garde-fous de suppression --')
avant = inventaire(base)
_, h1 = appel(base, {'cle': CLE, 'action': 'supprimer', 'groupe': 'cache'})
t('sans confirme=oui : refuse', 'confirme=oui' in h1)
t('sans confirme=oui : rien supprime', inventaire(base) == avant)

_, h2 = appel(base, {'cle': CLE, 'action': 'supprimer',
                     'groupe': 'archives', 'confirme': 'oui'})
t('groupe non supprimable : refuse', 'pas supprimable' in h2)
t('l archive zip est toujours la', existe(
    base, 'domains/site-a.com/public_html/sauvegarde-2024.zip'))
t('le dump sql est toujours la', existe(
    base, 'domains/site-a.com/public_html/base.sql'))
t('rien supprime sur un groupe non autorise', inventaire(base) == avant)

_, h3 = appel(base, {'cle': CLE, 'action': 'supprimer',
                     'groupe': 'sauvegardes', 'confirme': 'oui'})
t('les sauvegardes de plugins ne sont pas supprimables', existe(
    base, 'domains/site-a.com/public_html/wp-content/ai1wm-backups/site.wpress'))

print('\n-- suppression du cache --')
_, h4 = appel(base, {'cle': CLE, 'action': 'supprimer',
                     'groupe': 'cache', 'confirme': 'oui'})
t('le cache est annonce comme supprime', 'Supprime' in h4)
t('les fichiers de cache sont partis', not existe(
    base, 'domains/site-a.com/public_html/wp-content/cache/page/a.html'))
t('le DOSSIER cache reste en place (droits preserves)', existe(
    base, 'domains/site-a.com/public_html/wp-content/cache'))
t('litespeed vide aussi', not existe(
    base, 'domains/site-a.com/public_html/wp-content/litespeed/x.data'))
t('la vignette uploads/cache est partie', not existe(
    base, 'domains/site-a.com/public_html/wp-content/uploads/cache/thumb.jpg'))
t('LA PHOTO D UPLOADS EST INTACTE', existe(
    base, 'domains/site-a.com/public_html/wp-content/uploads/2024/photo.jpg'))
t('wp-config.php est intact', existe(
    base, 'domains/site-a.com/public_html/wp-config.php'))
t('.htaccess est intact', existe(
    base, 'domains/site-a.com/public_html/.htaccess'))
t('le plugin actif est intact', existe(
    base, 'domains/site-a.com/public_html/wp-content/plugins/vrai/vrai.php'))
t('index.php est intact', existe(
    base, 'domains/site-a.com/public_html/index.php'))
t('le journal de menage est ecrit', existe(
    base, 'domains/site-a.com/public_html/menage-journal.txt'))

print('\n-- suppression des autres groupes autorises --')
appel(base, {'cle': CLE, 'action': 'supprimer',
             'groupe': 'node', 'confirme': 'oui'})
t('node_modules est parti en entier', not existe(
    base, 'domains/site-a.com/public_html/node_modules'))
appel(base, {'cle': CLE, 'action': 'supprimer',
             'groupe': 'journaux', 'confirme': 'oui'})
t('error_log est parti', not existe(
    base, 'domains/site-a.com/public_html/error_log'))
t('debug.log est parti', not existe(
    base, 'domains/site-a.com/public_html/debug.log'))
appel(base, {'cle': CLE, 'action': 'supprimer',
             'groupe': 'systeme', 'confirme': 'oui'})
t('.DS_Store est parti', not existe(
    base, 'domains/site-a.com/public_html/.DS_Store'))
t('Thumbs.db de l AUTRE domaine est parti aussi', not existe(
    base, 'domains/site-b.net/public_html/Thumbs.db'))
t('__MACOSX est parti', not existe(
    base, 'domains/site-a.com/public_html/__MACOSX'))
appel(base, {'cle': CLE, 'action': 'supprimer',
             'groupe': 'upgrade', 'confirme': 'oui'})
t('le reste de mise a jour est parti', not existe(
    base, 'domains/site-a.com/public_html/wp-content/upgrade/tmp/plugin.php'))

t('.git n a PAS ete touche (signale seulement)', existe(
    base, 'domains/site-a.com/public_html/.git/config'))
t('le dossier old n a PAS ete touche', existe(
    base, 'domains/site-a.com/public_html/old/vieux.php'))
t('le site tient toujours debout apres tout le menage',
  existe(base, 'domains/site-a.com/public_html/index.php')
  and existe(base, 'domains/site-a.com/public_html/wp-config.php')
  and existe(base, 'domains/site-a.com/public_html/wp-content/uploads/2024/photo.jpg')
  and existe(base, 'domains/site-b.net/public_html/index.php'))

print('\n-- sortie de racine --')
base2 = batir()
_, hors = appel(base2, {'cle': CLE, 'racine': '/etc'})
t('une racine hors du compte est ignoree, pas suivie',
  '/etc</code>' not in hors and 'passwd' not in hors)
_, hd = appel(base2, {'cle': CLE, 'racine': os.path.join(
    base2, 'domains/site-b.net')})
t('une racine interne est acceptee', 'site-b.net' in hd)
t('elle ne montre plus l autre domaine', 'site-a.com/public_html/error_log' not in hd)

print('\n-- budget --')
_, court = appel(base2, {'cle': CLE, 'budget': '5'})
t('le budget est borne et annonce', 'entrees parcourues' in court)

shutil.rmtree(base, ignore_errors=True)
shutil.rmtree(base2, ignore_errors=True)

print('\n%d controles OK, %d en echec' % (_ok[0], _ko[0]))
sys.exit(1 if _ko[0] else 0)
