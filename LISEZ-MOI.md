# Menage — trouver et supprimer les fichiers inutiles d'un hebergement

Un seul fichier a deposer, `menage.php`. Il liste ce qui encombre le compte,
domaine par domaine, avec les tailles. **Par defaut il ne supprime rien.**

## Pourquoi un fichier a deposer et pas un nettoyage direct

Le SSH du compte repond `403` depuis plusieurs semaines, et le FTP est
enferme dans le `public_html` de steyli.com — `../` renvoie le meme dossier.
Je ne peux donc pas parcourir le compte moi-meme. Ce fichier fait le travail
depuis l'interieur : tu le deposes par le gestionnaire de fichiers hPanel, tu
l'ouvres dans le navigateur, et tu decides.

Si le SSH est rouvert, ce fichier ne sert plus a rien — je fais le menage
directement.

## En trois etapes

1. **Ouvrir `menage.php` et changer la cle**, ligne 24 :

   ```php
   $CLE = 'change-cette-cle-avant-de-televerser';
   ```

   N'importe quelle suite de caracteres, du moment qu'elle n'est pas
   devinable. Sans cle valide la page repond `403` et n'affiche rien —
   ni chemin, ni version, ni taille. Une page de diagnostic laissee en
   ligne sans cle, c'est un plan du serveur offert au premier venu.

2. **Deposer le fichier** dans le `public_html` d'un domaine, puis ouvrir :

   ```
   https://<le-domaine>/menage.php?cle=TA-CLE
   ```

   Le script remonte tout seul jusqu'a la racine du compte : il voit donc
   les ~29 domaines d'un coup, pas seulement celui ou il est pose.

3. **Lire, puis decider.** Chaque groupe a son bouton. Rien ne part sans un
   clic explicite, et le bouton n'existe que pour les groupes regenerables.

## Ce qu'il supprime, ce qu'il refuse de supprimer

Supprimable (bouton actif) — tout se reconstruit tout seul :

| Groupe     | Quoi                                                    |
|------------|---------------------------------------------------------|
| `cache`    | `wp-content/cache`, `litespeed`, `uploads/cache`, w3tc… |
| `upgrade`  | `wp-content/upgrade`, restes de mises a jour            |
| `journaux` | `error_log`, `debug.log`, `*.log`                       |
| `systeme`  | `.DS_Store`, `Thumbs.db`, `__MACOSX`, `desktop.ini`     |
| `node`     | `node_modules`                                          |

Signale mais **jamais supprime**, meme avec la bonne cle — le script refuse
la demande :

| Groupe        | Pourquoi je n'y touche pas                                  |
|---------------|-------------------------------------------------------------|
| `archives`    | Un `.zip` est souvent la seule copie restante de quelque chose. |
| `dumps`       | Idem, et un `.sql` dans un `public_html` se telecharge par n'importe qui avec l'URL : a **deplacer hors du web**, pas seulement a garder. |
| `sauvegardes` | ai1wm, UpdraftPlus, WPvivid, Duplicator. C'est en general le plus gros poste du compte, mais c'est au plugin de purger : lui sait ce qu'il peut jeter. |
| `vcs`         | Un `.git` accessible en HTTP laisse telecharger tout l'historique du code. A retirer, mais a la main. |
| `suspects`    | Dossiers nommes `old`, `backup`, `copie`… Le nom suggere une vieille copie ; toi seul sais si un site tourne encore dessus. |

Jamais listes, jamais touches, dans aucun mode : `wp-config.php`, `.env`,
`.htaccess`, `.htpasswd`, et tout le contenu de `wp-content/uploads/`
sauf `uploads/cache`.

## Deux details qui comptent

**Le journal d'erreurs se lit avant de se vider.** `error_log` est souvent
le plus gros fichier d'un compte, et c'est aussi le seul endroit qui dit
pourquoi un site plante. Si un site va mal en ce moment, lis-le d'abord.

**L'analyse a un budget de temps.** Par defaut 25 secondes. Sur un compte
tres charge elle s'arretera avant la fin — dans ce cas la page l'ecrit en
rouge, en haut, au lieu de faire croire qu'elle a tout vu. Relance alors
avec `&budget=120`, ou vise un domaine :

```
?cle=TA-CLE&racine=/home/<compte>/domains/<domaine>
```

Une racine en dehors du compte est ignoree, pas suivie.

## Quand c'est fini

Supprime `menage.php` et `menage-journal.txt` du serveur. Un outil qui liste
les fichiers d'un compte n'a rien a faire en ligne une fois le menage fait,
meme protege par une cle.

## Verification

`tests-menage.py` : **55 controles**, tous verts. Ils tournent sur un faux
compte reconstruit a chaque execution dans un dossier temporaire — jamais
sur un vrai hebergement. On ne teste pas une regle de protection sur ce
qu'elle est censee proteger.

Les controles qui comptent le plus :

- un inventaire ne supprime **rien** (46 entrees avant, 46 apres) ;
- apres avoir supprime les cinq groupes autorises, `index.php`,
  `wp-config.php`, `.htaccess`, les plugins et **la photo dans
  `uploads/2024/`** sont toujours la ;
- une demande de suppression sur `archives`, `dumps`, `sauvegardes`, `vcs`
  ou `suspects` est refusee et le fichier vise est toujours present ;
- une chaine temoin placee dans `wp-config.php` n'apparait jamais dans la
  page : le script affiche des chemins et des tailles, jamais du contenu ;
- `?racine=/etc` ne sort pas du compte ;
- sans cle, la page ne revele meme pas le chemin qu'elle aurait analyse.

```
php -l menage.php
python3 tests-menage.py
```

`runner.php` et `apercu.py` servent uniquement aux tests et aux captures.
Ils n'ont pas besoin d'etre televerses.
