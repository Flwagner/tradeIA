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
make down
```

Le conteneur PHP lance `composer install` automatiquement au premier demarrage si `vendor/` est absent.
