# Changelog — JeeConsoAPI

Toutes les évolutions notables de ce plugin sont consignées ici.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et le plugin
respecte le [versionnage sémantique](https://semver.org/lang/fr/).

---

## [1.0] — 2026-08-26

Première version publiée. Périmètre « consommation seule ».

### Ajouté

- **Équipement par point de livraison (PRM)** — un compteur Linky par équipement Jeedom,
  configuré avec son PRM à 14 chiffres et un token Bearer personnel obtenu via consentement
  Enedis sur [conso.boris.sh](https://conso.boris.sh/api/auth).
- **Commande « Consommation quotidienne »** (info / numeric, kWh, historisée,
  generic type `DAILY_CONSUMPTION`) — consommation de la veille, convertie depuis les Wh
  renvoyés par l'API.
- **Commande « Puissance maximale quotidienne »** (info / numeric, VA, historisée,
  generic type `POWER`).
- **Commandes « Date des données » et « Dernière mise à jour »** (info / string) pour suivre
  la fraîcheur des relevés.
- **Commande action « Actualiser maintenant »** — force un cycle immédiat, utilisable depuis
  un scénario. Journalise explicitement qu'elle consomme le quota du jour.
- **Cron quotidien respectueux des quotas** — un horaire d'appel tiré au hasard chaque jour
  entre 06:00:00 et 09:59:59, jamais pile à l'heure ronde ; nouvel essai différé dans la
  matinée puis en début d'après-midi si Enedis n'a pas encore publié les données de la
  veille ; plafond dur de 3 appels par jour et par compteur.
- **Import de l'historique à la demande** — bouton dédié, profondeur au choix (1, 6, 12 ou
  36 mois), en deux requêtes seulement. L'écriture passe par `cmd::addHistoryValue()`, sans
  déclencher de scénario, et n'écrit jamais de doublon.
- **Bouton « Tester la connexion »** — diagnostic immédiat de la configuration (code HTTP,
  nombre de points reçus, unité) sans jamais exposer le token.
- **Encart de planification** dans la page du compteur : dernières données obtenues, prochain
  appel autorisé, appels consommés dans la journée, dernière erreur rencontrée.
- **Deux plafonds d'appel indépendants et non contournables** : 3 cycles automatiques par
  jour et par compteur (configurable, max 5) pour le cron, et 10 actions manuelles par jour
  pour les boutons Tester / Actualiser / Importer et la commande action `refresh`. Les deux
  compteurs sont affichés dans l'encart de planification.
- **Configuration globale** du plugin : bornes de la fenêtre matinale, heure du nouvel essai
  de l'après-midi, plafond d'appels automatiques quotidiens.
- **Auto-test de non-régression** (`tools/selftest.php`, 66 vérifications, sans aucun appel
  réseau) : parsing des deux formes de réponse possibles, conversion d'unités, masquage des
  secrets, aller-retour de chiffrement, cycle de vie de l'équipement, respect des deux
  plafonds, et rendu des widgets desktop et mobile (placeholders résolus, câblage du
  graphique d'historique, absence de fuite de secret dans le HTML servi).
  Exécutable en CLI uniquement, inaccessible par HTTP.
- **Widget dashboard et mobile** affichant la consommation de la veille, la puissance
  maximale et la date des données, avec accès au graphique d'historique natif de Jeedom.
- **User-Agent identifiable** sur chaque requête, conformément aux règles d'usage de
  Conso API.

### Sécurité

- Le token Bearer est **chiffré au repos** (`utils::encrypt()`, AES-256-CBC + HMAC-SHA256,
  clé système Jeedom) et déchiffré uniquement pour construire l'en-tête `Authorization`.
- Le champ token n'est **jamais réaffiché** après sauvegarde : un champ laissé vide conserve
  la valeur existante.
- Le token n'apparaît **jamais dans les logs**, sous aucune forme complète ; le PRM y est
  masqué à l'exception de ses 4 derniers chiffres.
- Aucun endpoint AJAX ne renvoie le token, même partiellement.
- Les endpoints AJAX sont restreints par liste blanche via `ajax::init()` et exigent une
  session administrateur.

### Notes

- La courbe de charge par pas de 30 minutes (`consumption_load_curve`), la production
  photovoltaïque, Tempo et Ecowatt sont hors périmètre de cette version.
- Le plugin ne comporte **aucun démon** et **aucune dépendance** à installer.
