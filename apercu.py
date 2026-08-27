# -*- coding: utf-8 -*-
"""Capture d'ecran de menage.php sur un faux compte.

Le compte est FICTIF et construit ici meme : aucune capture envoyee au
client ne doit montrer l'arborescence reelle de son hebergement, ne
serait-ce que parce qu'une capture se reexpedie plus facilement qu'un
acces SSH.

Le port n'est pas choisi a la main : on demande un port libre au systeme.
Un port ecrit en dur finit par pointer sur le serveur d'un autre projet,
et la capture montre alors autre chose que ce qu'on croit.
"""

import os
import shutil
import socket
import subprocess
import tempfile
import time

from playwright.sync_api import sync_playwright

ICI = os.path.dirname(os.path.abspath(__file__))
CLE = 'change-cette-cle-avant-de-televerser'

# Faux compte : deux domaines, des volumes plausibles.
FAUX = [
    ('domains/exemple-a.com/public_html/index.php', 200),
    ('domains/exemple-a.com/public_html/wp-config.php', 3000),
    ('domains/exemple-a.com/public_html/error_log', 41_000_000),
    ('domains/exemple-a.com/public_html/wp-content/debug.log', 6_200_000),
    ('domains/exemple-a.com/public_html/wp-content/cache/page/a.html', 900_000),
    ('domains/exemple-a.com/public_html/wp-content/cache/page/b.html', 1_400_000),
    ('domains/exemple-a.com/public_html/wp-content/cache/objet/c.php', 700_000),
    ('domains/exemple-a.com/public_html/wp-content/litespeed/x.data', 2_800_000),
    ('domains/exemple-a.com/public_html/wp-content/upgrade/tmp/plugin.php', 480_000),
    ('domains/exemple-a.com/public_html/wp-content/uploads/2024/photo.jpg', 2_100_000),
    ('domains/exemple-a.com/public_html/wp-content/uploads/cache/th.jpg', 320_000),
    ('domains/exemple-a.com/public_html/wp-content/plugins/vrai/vrai.php', 12_000),
    ('domains/exemple-a.com/public_html/wp-content/ai1wm-backups/site.wpress', 1_900_000_000),
    ('domains/exemple-a.com/public_html/node_modules/pkg/index.js', 74_000_000),
    ('domains/exemple-a.com/public_html/.DS_Store', 6_148),
    ('domains/exemple-a.com/public_html/site-avant-refonte.zip', 210_000_000),
    ('domains/exemple-a.com/public_html/base-31-mai.sql', 88_000_000),
    ('domains/exemple-a.com/public_html/.git/config', 300),
    ('domains/exemple-a.com/public_html/old/vieux.php', 40_000),
    ('domains/exemple-b.net/public_html/index.php', 1_200),
    ('domains/exemple-b.net/public_html/error_log', 3_400_000),
    ('domains/exemple-b.net/public_html/Thumbs.db', 30_000),
    ('domains/exemple-b.net/public_html/__MACOSX/._x', 400),
    ('domains/exemple-b.net/public_html/wp-content/uploads/2025/v.mp4', 47_000_000),
]


def port_libre():
    s = socket.socket()
    s.bind(('127.0.0.1', 0))
    p = s.getsockname()[1]
    s.close()
    return p


def batir():
    base = tempfile.mkdtemp(prefix='menage-apercu-')
    for rel, taille in FAUX:
        p = os.path.join(base, rel)
        os.makedirs(os.path.dirname(p), exist_ok=True)
        with open(p, 'wb') as f:
            f.truncate(taille)          # fichier creux : pas 2 Go sur le disque
    shutil.copy(os.path.join(ICI, 'menage.php'),
                os.path.join(base, 'domains/exemple-a.com/public_html/menage.php'))
    return base


def main():
    base = batir()
    doc = os.path.join(base, 'domains/exemple-a.com/public_html')
    port = port_libre()
    # Le journal du serveur de test est ecrit HORS du faux compte :
    # depose dedans, il apparaissait dans le « poids par domaine » de la
    # capture, et une capture doit montrer le compte, pas mon outillage.
    journal = tempfile.mkstemp(prefix='menage-php-', suffix='.log')[1]
    srv = subprocess.Popen(['php', '-S', '127.0.0.1:%d' % port, '-t', doc],
                           stdout=open(journal, 'w'),
                           stderr=subprocess.STDOUT)
    try:
        url = 'http://127.0.0.1:%d/menage.php?cle=%s&budget=60' % (port, CLE)
        os.makedirs(os.path.join(ICI, 'shots'), exist_ok=True)
        with sync_playwright() as pw:
            nav = pw.chromium.launch()
            pg = nav.new_page(viewport={'width': 1280, 'height': 800})
            for essai in range(40):
                try:
                    pg.goto(url, timeout=3000)
                    break
                except Exception:
                    time.sleep(0.25)
            pg.wait_for_selector('h1', timeout=10000)

            # On verifie que la page servie est bien la notre avant de la
            # photographier : un serveur qui repond n'est pas une preuve
            # que c'est le bon serveur qui repond.
            assert 'Menage' in pg.title(), pg.title()

            pg.screenshot(path=os.path.join(ICI, 'shots', 'menage-1-haut.png'))
            pg.locator('h2', has_text='Journaux').scroll_into_view_if_needed()
            pg.wait_for_timeout(150)
            pg.screenshot(path=os.path.join(ICI, 'shots', 'menage-2-journaux.png'))
            pg.locator('h2', has_text='Archives').scroll_into_view_if_needed()
            pg.wait_for_timeout(150)
            pg.screenshot(path=os.path.join(ICI, 'shots', 'menage-3-signale.png'))
            nav.close()
    finally:
        srv.terminate()
        shutil.rmtree(base, ignore_errors=True)

    for n in ('menage-1-haut.png', 'menage-2-journaux.png', 'menage-3-signale.png'):
        p = os.path.join(ICI, 'shots', n)
        print('%s  %d octets' % (p, os.path.getsize(p)))


if __name__ == '__main__':
    main()
