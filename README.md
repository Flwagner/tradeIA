# tradeIA

Application Symfony perso pour apprendre Symfony en construisant un lab de trading momentum sur ETF.

## Stack locale

- PHP 8.4 FPM
- Symfony 7.4
- Nginx
- PostgreSQL 16
- Adminer
- Docker Compose

## Demarrage

```bash
make build
make up
```

Puis ouvre :

- Application : http://localhost:8088
- Adminer : http://localhost:8099

Identifiants Postgres locaux :

- Serveur : `database`
- Base : `app`
- Utilisateur : `app`
- Mot de passe : `app`

## Commandes utiles

```bash
make console
make shell
make logs
make db-create
make db-migrate
make market-import
make momentum-compute
make static-export
make quality
make cs-fix
make down
```

Le conteneur PHP lance `composer install` automatiquement au premier demarrage si `vendor/` est absent.

## Qualite de code

Les outils PHP se lancent dans le conteneur `php`.

```bash
make quality
make phpstan
make cs-check
make cs-fix
make phpcs
make test
```

La suite `quality` execute PHPStan, PHP-CS-Fixer en verification, PHPCS et PHPUnit.

## Modele metier initial

- `Etf` : univers des ETF suivis, avec ISIN, symbole, place de cotation, devise et eligibilite PEA.
- `PricePoint` : prix quotidien importe pour un ETF et une source de donnees.
- `MomentumSnapshot` : metriques et score momentum calcules a une date donnee pour une strategie.

## Import des prix

L'univers ETF suivi est configure dans `config/tradeia/etfs.yaml`.

```bash
php bin/console app:market-data:import --from="-1 year"
php bin/console app:market-data:import --symbol=CW8 --from="-1 month"
php bin/console app:market-data:import --dry-run
```

Depuis l'hote :

```bash
make market-import
```

## Calcul momentum

```bash
php bin/console app:momentum:compute
php bin/console app:momentum:compute --as-of="2026-05-28"
```

Depuis l'hote :

```bash
make momentum-compute
```

## Export statique

L'application peut générer une version statique en lecture seule de la page
d'accueil et des fiches ETF.

```bash
make static-export
```

Depuis le conteneur PHP, avec un chemin de base pour GitHub Pages :

```bash
php bin/console app:static-export --base-path=/tradeIA
```

Les fichiers sont générés dans `static-site/`.

Pour publier l'export sur GitHub Pages, génère puis pousse les fichiers sur la
branche `gh-pages` :

```bash
make static-deploy
```

Variables optionnelles :

```bash
STATIC_EXPORT_BASE_PATH=/tradeIA STATIC_DEPLOY_BRANCH=gh-pages make static-deploy
```

Dans GitHub, configure ensuite Pages pour servir la branche `gh-pages` depuis
`/`.

Pour automatiser ce déploiement avant chaque push vers `main`, installe les
hooks versionnés :

```bash
make install-hooks
```

Le hook `pre-push` lance `make static-deploy` uniquement lors d'un push vers
`main`. Pour le désactiver ponctuellement :

```bash
STATIC_DEPLOY_ON_PUSH=0 git push origin main
```
