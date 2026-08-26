<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Plugin JeeConsoAPI - Aldarande
 *
 * Auto-test de non-régression, SANS aucun appel réseau vers Conso API.
 * Crée un équipement temporaire nommé « __selftest_jeeconsoapi » et le supprime
 * à la fin.
 *
 * Usage (depuis l'hôte, conteneur de développement) :
 *   docker exec jeedom-dev php /var/www/html/plugins/jeeconsoapi/tools/selftest.php
 */

// SECURITY: ce script crée et supprime un équipement. Il ne doit JAMAIS être
// atteignable par HTTP — le dossier plugins/ est servi par Apache.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    die('Not found');
}

require_once '/var/www/html/core/php/core.inc.php';
// L'autoloader Jeedom ne charge le fichier qu'au premier accès à `jeeconsoapi::`.
// Les fonctions helper (hors classe) exigent donc un require explicite ici.
require_once '/var/www/html/plugins/jeeconsoapi/core/class/jeeconsoapi.class.php';

$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   $label\n"; }
    else       { $fail++; echo "  FAIL $label" . ($detail ? " :: $detail" : '') . "\n"; }
}

echo "=== 1. Parsing des deux formes de réponse ===\n";

$flat = json_decode('{"reading_type":{"unit":"Wh","measurement_kind":"energy"},
  "interval_reading":[{"value":"12873","date":"2026-08-24"},{"value":"14002","date":"2026-08-25"}]}', true);
$wrapped = json_decode('{"meter_reading":{"usage_point_id":"12345678901234",
  "reading_type":{"unit":"Wh"},
  "interval_reading":[{"value":"9500","date":"2026-08-25"}]}}', true);

$a = jeeconsoapi_extract($flat);
check('forme plate — 2 points', $a !== null && count($a['points']) === 2);
check('forme plate — unité Wh', $a !== null && $a['unit'] === 'Wh');

$b = jeeconsoapi_extract($wrapped);
check('forme meter_reading — 1 point', $b !== null && count($b['points']) === 1);
check('forme meter_reading — valeur 9500', $b !== null && $b['points'][0]['value'] === 9500.0);

check('corps vide → null', jeeconsoapi_extract(null) === null);
check('corps sans interval_reading → null', jeeconsoapi_extract(array('foo' => 'bar')) === null);
$dirty = jeeconsoapi_extract(array('interval_reading' => array(
    array('value' => 'abc', 'date' => '2026-08-25'),   // non numérique → ignoré
    array('date' => '2026-08-25'),                      // sans value  → ignoré
    array('value' => '42', 'date' => '2026-08-25'),
)));
check('points invalides ignorés', $dirty !== null && count($dirty['points']) === 1);

echo "\n=== 2. Conversion d'unités ===\n";
check('12873 Wh → 12.87 kWh', jeeconsoapi_to_kwh(12873, 'Wh') === 12.87);
check('12.5 kWh reste 12.5',  jeeconsoapi_to_kwh(12.5, 'kWh') === 12.5);
check('unité absente → Wh par défaut', jeeconsoapi_to_kwh(1000, '') === 1.0);

echo "\n=== 3. Masquage (aucune fuite de secret) ===\n";
$tok = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36P';
$masked = jeeconsoapi_mask($tok);
check('masque ne contient pas le token', strpos($masked, $tok) === false, $masked);
check('masque court (<=25 car.)', strlen($masked) <= 25, $masked);
check('PRM masqué garde 4 derniers', jeeconsoapi_mask_prm('12345678901234') === '••••••••••1234',
      jeeconsoapi_mask_prm('12345678901234'));

echo "\n=== 4. Chiffrement du token (aller-retour) ===\n";
$enc = utils::encrypt($tok);
check('chiffré préfixé crypt:', strpos($enc, 'crypt:') === 0);
check('chiffré != clair', $enc !== $tok);
check('déchiffrement identique', utils::decrypt($enc) === $tok);
check('encrypt idempotent', utils::encrypt($enc) === $enc);
check('decrypt passe-plat sur clair', utils::decrypt($tok) === $tok);

echo "\n=== 5. Cycle de vie eqLogic ===\n";
$eq = new jeeconsoapi();
$eq->setName('__selftest_jeeconsoapi');
$eq->setEqType_name('jeeconsoapi');
$eq->setIsEnable(1);
$eq->setConfiguration('prm', '12 345 678 901 234');   // avec espaces : doit être nettoyé
$eq->setConfiguration('token', $tok);
$eq->save();

$id = $eq->getId();
$reload = jeeconsoapi::byId($id);
check('équipement créé', is_object($reload));
check('PRM nettoyé à 14 chiffres', $reload->getConfiguration('prm') === '12345678901234',
      var_export($reload->getConfiguration('prm'), true));
check('token stocké chiffré', strpos($reload->getConfiguration('token'), 'crypt:') === 0);
check('token relu en clair', jeeconsoapi_token($reload) === $tok);

$cmds = array('daily_consumption', 'max_power', 'data_date', 'last_update', 'refresh');
foreach ($cmds as $lid) {
    check("commande $lid créée", is_object($reload->getCmd(null, $lid)));
}
$cc = $reload->getCmd('info', 'daily_consumption');
check('daily_consumption historisée', $cc->getIsHistorized() == 1);
check('daily_consumption unité kWh', $cc->getUnite() === 'kWh');
check('daily_consumption generic DAILY_CONSUMPTION', $cc->getGeneric_type() === 'DAILY_CONSUMPTION');
$mp = $reload->getCmd('info', 'max_power');
check('max_power unité VA', $mp->getUnite() === 'VA');
check('max_power generic POWER', $mp->getGeneric_type() === 'POWER');

echo "\n=== 6. Champ token vide = conserver l'existant ===\n";
$reload->setConfiguration('token', '');
$reload->save();
$again = jeeconsoapi::byId($id);
check('token conservé après save avec champ vide', jeeconsoapi_token($again) === $tok);

echo "\n=== 7. PRM invalide rejeté ===\n";
$bad = jeeconsoapi::byId($id);
$bad->setConfiguration('prm', '123');
try { $bad->save(); check('PRM à 3 chiffres rejeté', false, 'aucune exception levée'); }
catch (Exception $e) { check('PRM à 3 chiffres rejeté', true); }

echo "\n=== 8. Créneau matinal dans la fenêtre 6h-10h ===\n";
$fresh = jeeconsoapi::byId($id);
$outOfRange = 0; $roundHours = 0;
for ($i = 0; $i < 300; $i++) {
    $slot = $fresh->drawMorningSlot();
    $h = (int) date('G', $slot);
    if ($h < 6 || $h >= 10) { $outOfRange++; }
    if ((int) date('i', $slot) === 0 && (int) date('s', $slot) === 0) { $roundHours++; }
}
check('300 tirages tous entre 6h et 9h59', $outOfRange === 0, "hors plage : $outOfRange");
check('quasi jamais pile à l\'heure ronde', $roundHours <= 1, "pile à l'heure : $roundHours");

echo "\n=== 9. Le cycle ne part pas si les données du jour sont déjà là ===\n";
$fresh->setConfiguration('prm', '12345678901234');
$fresh->setConfiguration('state_done_date', date('Y-m-d', strtotime('-1 day')));
$fresh->saveState();
$r = jeeconsoapi::byId($id)->runCycle(false);
check('statut already_done (aucun appel réseau)', $r['status'] === 'already_done',
      var_export($r, true));

echo "\n=== 10. Plafond d'appels respecté ===\n";
$q = jeeconsoapi::byId($id);
$q->setConfiguration('state_done_date', '2000-01-01');
$q->setConfiguration('state_slot_date', date('Y-m-d'));
$q->setConfiguration('state_next_ts', time() - 60);
$q->setConfiguration('state_attempts', 99);
$q->saveState();
$r2 = jeeconsoapi::byId($id)->runCycle(false);
check('statut quota_reached (aucun appel réseau)', $r2['status'] === 'quota_reached',
      var_export($r2, true));

echo "\n=== 11. Plafond des actions manuelles ===\n";
$m = jeeconsoapi::byId($id);
$m->setConfiguration('state_manual_date', date('Y-m-d'));
$m->setConfiguration('state_manual_attempts', 0);
$m->saveState();

// 10 consommations doivent passer, la 11e être refusée — sans aucun appel réseau.
$granted = 0; $refused = 0;
for ($i = 0; $i < 12; $i++) {
    $res = jeeconsoapi::byId($id)->consumeManualQuota();
    if ($res === null) { $granted++; } else { $refused++; }
}
check('10 actions manuelles autorisées', $granted === 10, "autorisées : $granted");
check('les suivantes refusées', $refused === 2, "refusées : $refused");

$m2 = jeeconsoapi::byId($id);
check('compteur manuel exposé dans getScheduleInfo',
      $m2->getScheduleInfo()['manual'] === 10, var_export($m2->getScheduleInfo()['manual'], true));

// Un refresh forcé doit être bloqué par ce plafond, sans toucher au réseau.
$r3 = jeeconsoapi::byId($id)->runCycle(true);
check('runCycle(force) bloqué par le plafond manuel',
      $r3['status'] === 'manual_quota_reached', var_export($r3['status'], true));

// testConnection doit lever une exception plutôt que d'appeler le service.
try {
    jeeconsoapi::byId($id)->testConnection();
    check('testConnection bloqué par le plafond manuel', false, 'aucune exception');
} catch (Exception $e) {
    check('testConnection bloqué par le plafond manuel',
          strpos($e->getMessage(), 'Plafond') !== false, $e->getMessage());
}

// Changement de jour → le compteur manuel repart à zéro.
$m3 = jeeconsoapi::byId($id);
$m3->setConfiguration('state_manual_date', '2000-01-01');
$m3->saveState();
check('compteur manuel remis à zéro au changement de jour',
      jeeconsoapi::byId($id)->consumeManualQuota() === null);

echo "\n=== Nettoyage ===\n";
jeeconsoapi::byId($id)->remove();
check('équipement de test supprimé', !is_object(jeeconsoapi::byId($id)));

echo "\n========================================\n";
echo "  $pass réussi(s), $fail échec(s)\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
