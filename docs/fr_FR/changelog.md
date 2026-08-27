# Changelog — JeeConsoAPI

---

## 0.1.0 — 27 août 2026

Première version. Périmètre « consommation seule ».

> **Pourquoi une 0.x** : le plugin n'a pas encore été confronté à l'API réelle avec un vrai
> PRM et un vrai token. Le format exact du corps de réponse de Conso API n'étant pas publié,
> le parser accepte les deux formes plausibles — mais aucune n'est confirmée contre le
> service. Tant que ce n'est pas fait, annoncer une 1.0 serait annoncer une maturité que ce
> code n'a pas.

### Nouveautés

- **Un équipement par point de livraison (PRM)**, configuré avec son numéro à 14 chiffres et
  un token Bearer personnel obtenu via consentement Enedis sur
  [conso.boris.sh](https://conso.boris.sh/api/auth).
- **Consommation quotidienne** en kWh et **puissance maximale quotidienne** en VA, toutes
  deux historisées : le graphique natif de Jeedom fonctionne sans configuration.
- **Date des données** et **Dernière mise à jour** pour suivre la fraîcheur des relevés.
- **Actualiser maintenant** — commande action utilisable depuis un scénario.
- **Import de l'historique à la demande** : 1, 6, 12 ou 36 mois, en deux requêtes seulement,
  sans jamais créer de doublon.
- **Test de connexion** en un clic, avec code HTTP et nombre de points reçus.
- **Encart de planification** : dernières données obtenues, prochain appel autorisé, appels
  consommés dans la journée, dernière erreur.
- **Widgets dashboard et mobile.**
- Aucun démon, aucune dépendance à installer.

### Discipline d'appel

Les quotas Enedis (5 req/s, 10 000 req/h) sont **partagés entre tous les clients** de Conso
API. Le dimensionnement ci-dessous a été établi par simulation du parc installé.

- Horaire tiré au hasard **entre 08:00:00 et 09:59:59**, jamais pile à l'heure ronde. La
  fenêtre démarre à 8h parce qu'Enedis publie vers 8h : appeler avant, c'est une requête
  perdue suivie d'un nouvel essai.
- **Plancher horaire auto-appris** par compteur, qui redescend dès que les données
  réapparaissent plus tôt.
- **Rattrapage de l'après-midi étalé sur 3 heures**, un retard Enedis frappant tout le parc
  le même matin.
- **`Retry-After` respecté** sur `429` et `403`, avec décalage aléatoire.
- **Deux plafonds indépendants** : 3 cycles automatiques et 10 actions manuelles par jour.

### Sécurité

- Token Bearer **chiffré au repos**, jamais réaffiché, jamais journalisé, jamais renvoyé en
  AJAX. Le PRM est masqué dans les logs.
- Endpoints AJAX réservés aux administrateurs et fermés à la méthode GET.

### Hors périmètre

Courbe de charge par pas de 30 minutes, production photovoltaïque, Tempo et Ecowatt.
