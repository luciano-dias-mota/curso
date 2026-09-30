# PMMT Academy

Base MVC em PHP 8.2+ para a plataforma gamificada de estudos.

## Instalação rápida

1. Copie `.env.example` para `.env`.
2. Ajuste banco e `APP_URL`.
3. Rode `composer install`.
4. Importe `database/schema.sql`.
5. Acesse `http://localhost/pmmta-academy/public`.

## Estrutura

- `app/Core`: núcleo MVC.
- `app/Controllers`: controllers.
- `app/Models`: models.
- `app/Middleware`: middlewares.
- `app/Views`: views.
- `routes`: rotas.
- `public`: document root.
- `database`: schema.
