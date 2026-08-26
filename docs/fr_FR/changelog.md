# Changelog — JeeConsoAPI

---

## 1.0 — 26 août 2026

Première version. Périmètre « consommation seule ».

### Nouveautés

- **Un équipement par point de livraison (PRM)**, configuré avec le numéro de PRM à
  14 chiffres et un token Bearer personnel obtenu via consentement Enedis sur
  [conso.boris.sh](https://conso.boris.sh/api/auth).
- **Consommation quotidienne** en kWh — commande info numérique historisée.
- **Puissance maximale quotidienne** en VA — commande info numérique historisée.
- **Date des données** et **Dernière mise à jour** pour suivre la fraîcheur des relevés.
- **Actualiser maintenant** — commande action utilisable depuis un scénario, qui force un
  cycle immédiat hors planification.
- **Récupération quotidienne respectueuse des quotas** : horaire tiré au hasard chaque jour
  entre 06:00:00 et 09:59:59, nouvel essai différé dans la matinée puis en début
  d'après-midi si Enedis n'a pas encore publié les données, et plafond dur de 3 appels par
  jour et par compteur.
- **Import de l'historique à la demande** : 1, 6, 12 ou 36 mois, en deux requêtes seulement,
  sans jamais créer de doublon.
- **Test de connexion** en un clic, avec code HTTP et nombre de points reçus.
- **Encart de planification** : dernières données obtenues, prochain appel autorisé, appels
  consommés dans la journée, dernière erreur.
- **Deux plafonds d'appel indépendants et non contournables** : 3 cycles automatiques par
  jour et par compteur pour le cron, 10 actions manuelles par jour pour les boutons et la
  commande action `refresh`.
- **Configuration globale** : bornes de la fenêtre matinale, heure du nouvel essai de
  l'après-midi, plafond d'appels automatiques quotidiens.
- **Widgets dashboard et mobile**, avec accès au graphique d'historique natif de Jeedom.

### Sécurité

- Token Bearer **chiffré au repos** (AES-256-CBC + HMAC-SHA256, clé système Jeedom).
- Token **jamais réaffiché** dans le formulaire ni **journalisé** ; PRM masqué dans les logs.
- Endpoints AJAX restreints par liste blanche et réservés aux administrateurs.

### Hors périmètre de cette version

Courbe de charge par pas de 30 minutes, production photovoltaïque, Tempo et Ecowatt.
