# JeeConsoAPI

**Plugin Jeedom pour remonter la consommation électrique de votre compteur Linky**

[![License](https://img.shields.io/badge/licence-AGPL%20v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0.html)
[![Jeedom](https://img.shields.io/badge/Jeedom-4.4+-green.svg)](https://jeedom.com)
[![Conso API](https://img.shields.io/badge/data-Conso%20API-0aa?logo=zap&logoColor=white)](https://conso.boris.sh)
[![Ko-fi](https://img.shields.io/badge/don-Ko--fi-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/aldarande)
[![Liberapay](https://img.shields.io/badge/don-Liberapay-F6C915?logo=liberapay&logoColor=black)](https://liberapay.com/Aldarande/donate)
[![GitHub Sponsors](https://img.shields.io/badge/don-GitHub%20Sponsors-ea4aaa?logo=github&logoColor=white)](https://github.com/sponsors/Aldarande)

---

## Description

JeeConsoAPI récupère chaque jour la consommation électrique de votre compteur Linky et
l'historise dans Jeedom, sans dépendre du plugin Enedis officiel.

Les données proviennent de **[Conso API](https://conso.boris.sh)**, une passerelle gratuite
et open-source vers les API Enedis « Token V3 » et « Metering Data V5 ». Aucune donnée n'est
stockée par Conso API : le service est *stateless*, et c'est Jeedom qui conserve votre
historique.

### Ce que le plugin remonte

| Commande | Type | Unité | Historisée |
|---|---|---|---|
| Consommation quotidienne | info / numeric | kWh | oui |
| Puissance maximale quotidienne | info / numeric | VA | oui |
| Date des données | info / string | — | non |
| Dernière mise à jour | info / string | — | non |
| Actualiser maintenant | action | — | — |

Les deux commandes numériques étant historisées, le graphique d'historique natif de Jeedom
fonctionne sans configuration supplémentaire.

### Ce que le plugin ne fait pas (encore)

- Courbe de charge par pas de 30 minutes (`consumption_load_curve`)
- Données de production photovoltaïque
- Tempo, Ecowatt
- Obtention automatisée du token (pas de flux OAuth) : la saisie est manuelle, une fois pour toutes

---

## Installation

1. Installez le plugin depuis le Market Jeedom, ou copiez le dossier `jeeconsoapi` dans
   `plugins/` de votre Jeedom.
2. Dans **Plugins → Gestion des plugins**, activez **JeeConsoAPI**.
   L'activation crée automatiquement la tâche cron quotidienne.
3. Aucune dépendance à installer : le plugin n'utilise qu'un appel HTTP en PHP, et n'a pas
   de démon.

---

## Configuration

### 1. Obtenir votre token Enedis

Rendez-vous sur **<https://conso.boris.sh/api/auth>** et suivez le parcours de consentement
Enedis. À la fin, vous obtenez un token personnel de la forme `xxx.yyy.zzz`.

Le bouton **« Obtenir mon token »** de la page de configuration du compteur ouvre directement
cette page.

### 2. Trouver votre PRM

Le PRM (Point Référence Mesure) est un identifiant à **14 chiffres**. Vous le trouvez :

- sur votre facture d'électricité ;
- sur l'écran du compteur Linky : appuyez sur **+** jusqu'à afficher « Numéro de PRM ».

### 3. Créer le compteur dans Jeedom

**Plugins → Énergie → JeeConsoAPI → Ajouter**, puis renseignez le PRM et le token, et
sauvegardez.

Utilisez ensuite **« Tester la connexion »** pour vérifier que tout répond correctement.

### 4. Importer l'historique (facultatif)

Le bouton **« Importer l'historique »** rapatrie les mesures passées (1, 6, 12 ou 36 mois)
directement dans l'historique Jeedom. Deux requêtes au total, quelle que soit la profondeur
choisie. Relancer l'import n'écrit jamais de doublon.

---

## Fonctionnement du cron — pourquoi un seul appel par jour

Conso API est un service gratuit, et les quotas Enedis (5 requêtes/seconde, 10 000
requêtes/heure) sont **partagés entre tous ses utilisateurs**. Le service demande donc
explicitement un seul cycle d'appel par jour et par compteur, à un horaire non rond.

Le plugin applique cette discipline strictement :

- La tâche cron s'exécute toutes les 5 minutes entre 6h et 14h55, mais **n'émet une requête
  que lorsque l'état du compteur l'autorise**. Le rythme du cron n'a aucun rapport avec le
  rythme des appels.
- Chaque jour, un **horaire d'appel est tiré au hasard entre 06:00:00 et 09:59:59** : le
  service n'est jamais sollicité pile à l'heure ronde.
- Les données de la veille sont publiées par Enedis vers 8h, parfois avec 1 à 2 heures de
  retard. Si elles sont absentes, le plugin retente **une fois** dans la matinée, puis **une
  fois** en début d'après-midi, et abandonne jusqu'au lendemain. Jamais de boucle serrée.
- Un **plafond dur de 3 appels par jour et par compteur** borne le tout, quelle que soit la
  situation.
- Un **User-Agent identifiable** accompagne chaque requête, comme le demande le service.

Un cycle représente 2 requêtes HTTP, car la consommation quotidienne et la puissance maximale
sont deux endpoints distincts. C'est le minimum incompressible pour alimenter les deux
commandes.

### Codes de retour

| Code | Traitement |
|---|---|
| `200` avec données | Commandes mises à jour, terminé pour la journée |
| `200` sans données | Données pas encore publiées → nouvel essai différé |
| `400` | Requête invalide (PRM ?) → erreur dans les logs, aucun nouvel essai |
| `401` | Token invalide, expiré ou n'autorisant pas ce PRM → **erreur remontée au centre de messages Jeedom** |
| `500` | Panne Enedis / Conso API → nouvel essai différé, silencieux |

---

## Sécurité du token

Le token Bearer donne accès à vos données de consommation. Le plugin le traite en
conséquence :

- **Chiffré au repos** via `utils::encrypt()` (AES-256-CBC + HMAC-SHA256, clé système Jeedom).
  Il n'est déchiffré qu'au moment de construire l'en-tête `Authorization`.
- **Jamais réaffiché** dans le formulaire : le champ reste vide après sauvegarde. Laisser le
  champ vide signifie « conserver le token actuel » ; n'y saisir quelque chose que pour le
  remplacer.
- **Jamais journalisé.** Seule une forme masquée (`abc123…(215 car.)`) peut apparaître dans
  les logs. Le PRM y est également masqué, seuls les 4 derniers chiffres sont visibles.
- **Jamais renvoyé** par un endpoint AJAX : les diagnostics remontent un code HTTP et un
  nombre de points, rien de plus.

> **À savoir** — comme pour tout plugin Jeedom, un administrateur du système ou le détenteur
> de la clé API Jeedom peut lire la configuration des équipements. Le chiffrement protège la
> valeur en base et dans les sauvegardes, pas contre un accès administrateur légitime.
> Ne partagez pas votre clé API Jeedom.

---

## Dépannage

| Symptôme | Piste |
|---|---|
| Aucune donnée après l'installation | Normal : le premier appel a lieu le lendemain matin. Utilisez « Actualiser maintenant » pour ne pas attendre. |
| `401` dans les logs | Token invalide ou expiré → régénérez-le sur conso.boris.sh et ressaisissez-le. |
| `400` dans les logs | Vérifiez le PRM : exactement 14 chiffres, celui du compteur associé au consentement. |
| « Données de la veille pas encore publiées » | Enedis a du retard. Le plugin retentera tout seul. |
| Le widget affiche `--` | Aucune valeur encore collectée. Voir l'encart « Planification » de la page du compteur. |

Les logs se trouvent dans **Analyse → Logs → jeeconsoapi**.

> Par défaut Jeedom ne consigne que le niveau **Erreur**. Les messages qui décrivent le
> déroulement normal sont émis en **Info** : pour les voir, passez le niveau de log du plugin
> à *Info* dans **Réglages → Système → Configuration → Logs**.

### Limites d'appel

Deux compteurs indépendants, affichés dans l'encart « Planification » :

| Compteur | Plafond | Ce qu'il borne |
|---|---|---|
| Appels automatiques | 3 / jour (configurable, max 5) | Les cycles du cron |
| Actions manuelles | 10 / jour | Boutons Tester / Actualiser / Importer, et la commande action `refresh` |

Aucun des deux n'est contournable : c'est ce qui garantit que le plugin tient sa promesse
envers un service gratuit et mutualisé.

---

## Soutenir le développement

Si vous appréciez ce plugin (gratuit et open-source), un don aide à financer le
développement, les tests et les mises à jour :

| Plateforme | Type | Lien |
|---|---|---|
| ☕ Ko-fi | Don ponctuel | [ko-fi.com/aldarande](https://ko-fi.com/aldarande) |
| 💙 GitHub Sponsors | Mensuel | [github.com/sponsors/Aldarande](https://github.com/sponsors/Aldarande) |
| 💛 Liberapay | Récurrent, anonyme possible | [liberapay.com/Aldarande](https://liberapay.com/Aldarande/donate) |

Merci également à **[Boris K.](https://github.com/bokub)** pour Conso API, sans qui ce plugin
n'existerait pas.

---

## Crédits et licence

- Auteur : **Aldarande**
- Source de données : [Conso API](https://conso.boris.sh) — [bokub/conso-api](https://github.com/bokub/conso-api)
- Licence : **AGPL v3** — voir <https://www.gnu.org/licenses/agpl-3.0.html>

Ce plugin n'est ni affilié ni soutenu par Enedis.
