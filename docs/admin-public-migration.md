# Administration sous /admin

La migration est développée dans `codex/admin-public-separation`.

## Organisation

- `routes/web.php` assemble les routes publiques, les routes d'authentification sous `/admin` et les routes de gestion protégées.
- `routes/public.php` expose `/` avec `PublicSite\HomeController` et `PublicSite\Catalogue`.
- `routes/admin.php` expose le dashboard, la collection, les référentiels, les images, les imports, les étiquettes et les recherches de gestion sous `/admin`. Les noms commencent par `admin.`.
- `routes/auth.php` conserve les noms conventionnels `login`, `password.*`, `verification.*`, avec des URLs sous `/admin`.
- Les contrôleurs de gestion, composants Livewire et composants Blade de layout sont dans leurs namespaces `Admin`.
- Les vues de gestion sont dans `resources/views/admin`, y compris les pages Volt. Les composants Blade anonymes utilisent le préfixe `x-admin::`, les layouts de classe `x-admin.app-layout`.
- Le catalogue public utilise ses propres contrôleur, composant Livewire, vues, requêtes et entrée CSS. Il ne dépend pas des composants de gestion. Il s'agit d'un catalogue provisoire en lecture seule, pas de l'implémentation des maquettes du futur frontend.
- Les modèles, données et images restent communs. Les services d'import existants sont utilisés par la gestion et les commandes CLI, pas par le catalogue public. Aucune couche de queries ou d'UI commune aux interfaces n'a été ajoutée.

## Accès

| Parcours | Accès |
| --- | --- |
| `/` | Consultation publique, identique avec ou sans connexion |
| `/admin/login`, mot de passe oublié/reset | Visiteur non connecté |
| `/admin/profile`, vérification et confirmation | Utilisateur connecté ; profil accessible avant vérification pour corriger l'adresse email |
| `/admin`, collection et référentiels | Utilisateur connecté et vérifié, sans obligation d'être `is_admin` |
| Import par lot, recherche d'affectation et aperçu d'import | Restrictions administrateur existantes conservées |
| Suppression d'utilisateurs | Restriction administrateur existante conservée |

Les composants de gestion ordinaires autorisent l'accès au démarrage de chaque requête Livewire via `RequiresManagementAccess`. Les composants d'import et d'upload individuel conservent leurs vérifications explicites. Les formulaires d'écriture de spécimens vérifient aussi l'accès. Les routes HTTP sont protégées par le groupe `auth` + `verified`, avec persistance des middlewares pour les mises à jour Livewire.

La suppression d'une image depuis la modale est limitée au spécimen ouvert. L'upload de genres utilise désormais les champs réels `genus_id` et `filename`, un genre sélectionné et un nom généré compatible avec la colonne de 32 caractères. L'import du modèle `Genus` manquant dans le composant d'upload a été corrigé.

## URLs et déploiement

- Accueil public : `/`.
- Accueil de gestion : `/admin`.
- Collection de gestion : `/admin/plants`.
- Connexion : `/admin/login`.
- Les anciennes URLs de gestion ne sont pas conservées : elles retournent 404. Mettre à jour les favoris et intégrations concernés.
- Les anciens liens signés d'aperçu et de vérification email changent de chemin. Régénérer les aperçus et renvoyer les emails de vérification si nécessaire.
- L'inscription reste désactivée.
- Les endpoints techniques `/livewire/*`, WireUI, les assets et les URLs de fichiers restent à leur emplacement habituel.
- Aucune nouvelle migration de schéma ou de données n'est nécessaire pour ce changement.

Exécuter avec le runtime PHP compatible de l'application :

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Conserver la configuration de base de données et le stockage d'images existants. Aucun changement DNS ou Hostinger n'est requis pour le préfixe `/admin`.

## Validation locale

La suite PHPUnit est configurée pour une base isolée `dryherbarium_testing` sur le port 3308. Pour cette migration, un serveur MySQL temporaire séparé a été utilisé ; les tests n'utilisent pas la base locale importée. `APP_URL` est explicitement fixé en HTTP dans PHPUnit pour isoler les tests de proxy du domaine HTTPS local.

Les tests de migration couvrent le catalogue en lecture seule, la recherche publique, les routes et refus d'accès, toutes les familles de pages de gestion avec des données, les modales, la création d'un référentiel, le refus d'une suppression après déconnexion, la suppression d'images limitée au spécimen, la connexion/déconnexion, le reset de mot de passe, la vérification email, la confirmation de mot de passe, le PDF, le viewer et les deux uploads de genres. La suite existante couvre notamment les imports et l'upload individuel de spécimens.

La compilation Vite et la génération des caches Blade, routes et configuration sont également vérifiées. Les contrôles HTTP Herd vérifient `/`, `/admin/login`, la redirection d'un visiteur depuis `/admin` et la disparition de `/plants`.
