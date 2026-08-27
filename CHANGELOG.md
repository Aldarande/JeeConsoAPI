# Changelog — JeeConsoAPI

Toutes les évolutions notables de ce plugin sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et le plugin
respecte le [versionnage sémantique](https://semver.org/lang/fr/).

---

## [0.1.0] — 2026-08-27

Première version. Périmètre « consommation seule ».

> **Version 0.x assumée** : le plugin n'a pas encore été confronté à l'API réelle avec un
> vrai PRM et un vrai token. Le format exact du corps de réponse de Conso API n'étant pas
> publié, le parser accepte les deux formes plausibles — mais aucune n'est confirmée contre
> le service. Tant que ce n'est pas fait, annoncer une 1.0 serait annoncer une maturité que
> ce code n'a pas.

### Fonctionnalités

- **Un équipement par point de livraison (PRM)**, configuré avec son numéro à 14 chiffres et
  un token Bearer personnel obtenu via consentement Enedis sur
  [conso.boris.sh](https://conso.boris.sh/api/auth).
- **Consommation quotidienne** en kWh (info / numeric, historisée, generic type
  `DAILY_CONSUMPTION`) et **puissance maximale quotidienne** en VA (historisée, `POWER`).
- **Date des données** et **Dernière mise à jour** pour suivre la fraîcheur des relevés.
- **Actualiser maintenant** — commande action utilisable depuis un scénario, qui force un
  cycle immédiat hors planification.
- **Import de l'historique à la demande** : 1, 6, 12 ou 36 mois, en deux requêtes seulement.
  L'écriture passe par `cmd::addHistoryValue()`, sans déclencher de scénario sur chacun des
  ~1100 points, et n'écrit jamais de doublon.
- **Test de connexion** en un clic : code HTTP, nombre de points reçus, unité — sans jamais
  exposer le token.
- **Encart de planification** : dernières données obtenues, prochain appel autorisé, appels
  consommés dans la journée, dernière erreur.
- **Widgets dashboard et mobile**, avec accès au graphique d'historique natif de Jeedom.
- Aucun démon, aucune dépendance à installer.

### Discipline d'appel — le cœur du plugin

Les quotas Enedis (5 req/s, 10 000 req/h) ne sont **pas par utilisateur** : ils sont globaux
et partagés entre tous les clients de Conso API. Le risque n'est donc pas l'abus individuel —
un compteur, c'est 2 requêtes par jour — mais que l'ensemble du parc installé se présente au
même moment. Le dimensionnement ci-dessous a été établi par simulation du parc.

- **Horaire tiré au hasard entre 08:00:00 et 09:59:59**, jamais pile à l'heure ronde. La
  fenêtre démarre à 8h et non à 6h : Enedis publie vers 8h, et appeler avant renvoyait une
  réponse vide puis déclenchait un nouvel essai — deux requêtes pour rien, soit la moitié du
  trafic généré par le plugin.
- **Plancher horaire auto-appris, par compteur** : le plugin retient l'heure à laquelle les
  données apparaissent réellement et ne tire plus en dessous. Le plancher redescend dès
  qu'elles réapparaissent plus tôt, pour qu'un seul jour de retard ne le fige pas.
- **Rattrapage de l'après-midi étalé sur 3 heures** (réglable). Un retard de publication chez
  Enedis est un événement *corrélé* qui frappe tout le parc le même matin : une fenêtre de
  rattrapage étroite fabriquerait la pointe que le reste du dispositif cherche à éviter.
- **`Retry-After` respecté** sur `429` et `403` (secondes ou date HTTP, borné à 24 h), avec
  un décalage aléatoire — sans lui, tous les clients ayant reçu la même consigne
  reviendraient frapper à la seconde près ensemble.
- **Deux plafonds indépendants et non contournables** : 3 cycles automatiques par jour et par
  compteur (configurable, max 5), et 10 actions manuelles par jour pour les boutons et la
  commande action `refresh`.
- **User-Agent identifiable** sur chaque requête, comme le demande le service.

> **Limite assumée** : les collisions ponctuelles ne sont pas supprimables côté client — une
> installation ignore combien d'autres existent et quand elles appellent. Élargir la fenêtre
> n'y change quasiment rien, les collisions étant statistiques. Ce qui est maîtrisé, c'est le
> volume total et l'absence d'effet de horde lors des incidents.

### Sécurité

- Token Bearer **chiffré au repos** (`utils::encrypt()`, AES-256-CBC + HMAC-SHA256, clé
  système Jeedom), déchiffré uniquement pour construire l'en-tête `Authorization`.
- Token **jamais réaffiché** dans le formulaire : un champ laissé vide conserve la valeur
  existante.
- Token **jamais journalisé**. Le PRM est masqué dans les logs, y compris lorsqu'il provient
  du corps d'une réponse ou d'un message d'erreur du service.
- Aucun endpoint AJAX ne renvoie le token. Les endpoints exigent une session administrateur
  et refusent la méthode GET, toutes leurs actions ayant un effet de bord.

### Hors périmètre de cette version

Courbe de charge par pas de 30 minutes (`consumption_load_curve`), production photovoltaïque,
Tempo et Ecowatt.

### Journalisation

- Le canal `jeeconsoapi` **apparaît dans « Analyse → Logs » dès l'installation**. La page
  n'énumère que les fichiers présents dans `log/` : tant qu'aucune ligne n'était écrite, le
  canal restait invisible. Le plugin crée donc son fichier à l'installation.
- **Le niveau du canal est initialisé à « Info »**, et non à l'héritage du niveau global
  (« Erreur » sur une installation standard). Tout ce qui décrit le fonctionnement normal —
  créneau tiré, données obtenues, plancher horaire ajusté — est en `info` : sans cela, un
  utilisateur n'aurait jamais rien vu. Un cron quotidien produit 3 à 5 lignes par jour.
- Ce réglage n'est appliqué **qu'à la première installation**. Si vous choisissez ensuite un
  autre niveau, une mise à jour ne vous le reprendra pas.
- Le niveau se règle dans **Plugins → Gestion des plugins → JeeConsoAPI**, section *Logs* —
  et non dans *Réglages → Système → Configuration → Logs*, qui ne pilote que le niveau global
  et les canaux du cœur de Jeedom.

### Qualité

- Auto-test de non-régression : **93 vérifications**, aucun appel réseau, exécutable en CLI
  uniquement (`tools/selftest.php`, inaccessible par HTTP).
