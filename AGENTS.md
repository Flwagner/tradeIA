# Contexte pour agents IA

Ce projet est une application Symfony dockerisee. Ne pas supposer que PHP,
Composer ou les commandes Symfony sont disponibles directement sur l'hote.

## Environnement local

- Application: Symfony 7.4, PHP 8.4 FPM, Nginx, PostgreSQL 16.
- Orchestration: `docker-compose` via le fichier `docker-compose.yml`.
- Service PHP: `php`.
- URL application: `http://127.0.0.1:8088` ou `http://localhost:8088`.
- URL Adminer: `http://127.0.0.1:8099`.
- La racine projet de l'hote est montee dans le conteneur sur `/var/www/html`.
- `vendor/` et `var/` sont des volumes Docker nommes, pas forcement presents ou
  complets sur l'hote.

## Regle importante

Lancer les outils PHP dans le conteneur `php`.

Utiliser `docker-compose exec -T php ...` pour les commandes non interactives
depuis un agent IA. Le `-T` evite les problemes de pseudo-terminal.

Exemples:

```bash
docker-compose exec -T php composer install
docker-compose exec -T php composer require symfony/asset --no-interaction --no-ansi
docker-compose exec -T php php bin/console cache:clear --no-ansi
docker-compose exec -T php php bin/console lint:twig templates --no-ansi
docker-compose exec -T php php bin/console lint:yaml config --no-ansi
docker-compose exec -T php php bin/console lint:container --no-ansi
```

Pour ouvrir un shell interactif, utiliser plutot:

```bash
docker-compose exec php bash
```

## Commandes Makefile

Le Makefile encapsule `docker-compose`:

```bash
make build
make up
make down
make logs
make shell
make console
make composer
make db-create
make db-migrate
make market-import
make momentum-compute
make static-export
make static-deploy
make install-hooks
make phpstan
make cs-check
make cs-fix
make phpcs
make test
make quality
```

Pour un agent IA, preferer les commandes explicites avec `docker-compose exec -T`
lorsqu'il faut capturer une sortie stable.

## Base de donnees locale

Configuration PostgreSQL locale:

- Host depuis les conteneurs: `database`
- Base: `app`
- Utilisateur: `app`
- Mot de passe: `app`
- Port expose sur l'hote: `5432`

## Architecture metier

Le projet est un lab personnel de trading momentum sur ETF. Les calculs
financiers sont le coeur du comportement: ne pas les modifier dans un refacto
technique sans demande explicite.

Entites principales:

- `App\Entity\Etf`: univers des ETF suivis, avec ISIN, symbole, place,
  devise, fournisseur et etat actif.
- `App\Entity\PricePoint`: prix quotidien d'un ETF. Les calculs privilegient
  `adjustedClosePrice` quand il est disponible et positif, puis `closePrice`.
- `App\Entity\MomentumSnapshot`: resultat d'un calcul momentum pour une date et
  une strategie.

Services principaux:

- `App\MarketData\MarketDataImporter`: import et persistance des prix.
- `App\MarketData\YahooFinanceClient`: client de donnees de marche.
- `App\Boursobank\BoursobankTopEtfClient`: recuperation du top ETF Boursobank.
- `App\Momentum\MomentumCalculator`: formule momentum `momentum_v1`.
- `App\Momentum\MomentumComputer`: orchestration du calcul momentum.
- `App\Risk\TrailingStopAdvisor`: simulation et recommandation de trailing stop.
- `App\Backtest\TacticalBacktester`: backtest de rotation tactique.

Les controleurs doivent rester minces: orchestration HTTP, validation simple des
entrees, appels aux services, puis rendu Twig/redirection. Mettre la logique
metier dans des services dedies.

## Invariants financiers

- Ne pas changer les poids, seuils ou formules de `MomentumCalculator` sans
  demande explicite.
- Ne pas changer la strategie `momentum_v1` silencieusement: si une formule
  evolue, preferer un nouveau code de strategie ou une migration assumee.
- Ne pas melanger refacto technique et changement de comportement financier.
- Les prix doivent rester tries chronologiquement pour les calculs de momentum,
  volatilite, drawdown, ATR, stops et backtests.
- Quand un prix ajuste existe et est positif, il est la base des metriques.
- Les backtests affiches sont indicatifs: ne pas ajouter de promesse de
  performance ou de conseil financier.

## Conventions Symfony du projet

- Services autowires/autoconfigures via `config/services.yaml`.
- Entites Doctrine dans `src/Entity`, repositories dans `src/Repository`.
- Commandes console dans `src/Command`.
- Templates dans `templates`, un layout commun dans `templates/base.html.twig`.
- Configuration metier statique dans `config/tradeia/`.
- Preferer les injections de dependances par constructeur et les services
  `readonly` quand c'est coherent avec le style existant.
- Eviter les helpers globaux et la logique SQL dans les controleurs.
- Garder les migrations existantes intactes sauf demande explicite.

## Commandes metier utiles

Toujours les lancer dans le conteneur PHP:

```bash
docker-compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction
docker-compose exec -T php php bin/console app:market-data:import
docker-compose exec -T php php bin/console app:market-data:import --from="-1 year"
docker-compose exec -T php php bin/console app:market-data:import --symbol=CW8 --from="-1 month"
docker-compose exec -T php php bin/console app:market-data:import --dry-run
docker-compose exec -T php php bin/console app:momentum:compute
docker-compose exec -T php php bin/console app:momentum:compute --as-of="2026-05-28"
docker-compose exec -T php php bin/console app:static-export
docker-compose exec -T php php bin/console app:static-export --base-path=/tradeIA
COMPOSE=docker-compose bin/deploy-static
```

## Frontend et assets

- Les templates Twig sont dans `templates/`.
- Les assets statiques publics sont dans `public/assets/`.
- Le layout charge les assets avec `{{ asset(...) }}`.
- Le composant `symfony/asset` est requis pour utiliser le helper Twig `asset()`.
- Ne pas ajouter de chaine Node/npm/Encore/Vite sans besoin explicite.
- Eviter les styles et scripts inline. Preferer `public/assets/styles/app.css`
  et `public/assets/scripts/app.js`.
- Pour les comportements JS simples, preferer des attributs `data-*` dans Twig
  et un listener central dans `app.js`.

## Fichiers sensibles

- `config/reference.php` est auto-genere par Symfony. Ne pas le modifier a la
  main. Si Composer/Symfony le regenere, verifier que le diff est attendu.
- `composer.lock` doit rester coherent avec `composer.json`.
- Les fichiers dans `migrations/` representent l'historique de schema. Ne pas
  les reordonner, renommer ou reecrire sans demande explicite.
- `config/tradeia/etfs.yaml` decrit l'univers ETF de reference.
- Le depot est public. Ne jamais committer de secrets reels. Garder les valeurs
  privees locales dans `.env.local` ou `.env.*.local`, deja ignores par Git.
- La branche GitHub Pages expose publiquement les donnees presentes dans
  l'export statique.

## Verification apres modification

Selon le type de changement, lancer dans le conteneur:

```bash
docker-compose exec -T php php bin/console lint:twig templates --no-ansi
docker-compose exec -T php php bin/console lint:yaml config --no-ansi
docker-compose exec -T php php bin/console lint:container --no-ansi
docker-compose exec -T php composer validate --strict --no-ansi
docker-compose exec -T php composer quality --no-ansi
```

Avant chaque commit, lancer obligatoirement la suite qualite:

```bash
docker-compose exec -T php composer quality --no-ansi
```

Pour un changement PHP, lancer au minimum les lints pertinents et une commande
console proche du comportement touche quand c'est possible.

Verification HTTP sans proxy local:

```bash
curl --noproxy '*' -fsS http://127.0.0.1:8088/ >/dev/null
curl --noproxy '*' -fsSI http://127.0.0.1:8088/assets/styles/app.css
```

## Notes de collaboration

- Respecter les changements non commit existants.
- Ne pas lancer `php`, `composer` ou `bin/console` depuis l'hote pour valider le
  projet.
- Preferer `rg` pour chercher dans le code.
- Garder les refactos scopes et compatibles avec les conventions Symfony deja
  en place dans le projet.
